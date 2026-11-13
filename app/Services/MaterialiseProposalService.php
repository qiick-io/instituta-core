<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ProposalActionEnum;
use App\Enums\ProposalMaterialisationStatusEnum;
use App\Enums\ProposalStatusEnum;
use App\Enums\SourceProviderEnum;
use App\Models\Proposal;
use App\Models\Source;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Turns an accepted proposal into a reviewable git change (branch + PR on GitHub).
 *
 * Never updates the brain index and never mutates a local source path in place:
 * work happens under storage/app/instituta/proposals/{id}.
 */
final class MaterialiseProposalService
{
    public function __construct(
        private readonly DenylistGuardService $denylist = new DenylistGuardService,
    ) {}

    /**
     * @side-effect Writes a review workspace, runs git, may call the GitHub API; updates proposal materialisation columns (never logs body).
     */
    public function materialise(Proposal $proposal): Proposal
    {
        if ($proposal->status !== ProposalStatusEnum::Accepted) {
            throw new InvalidArgumentException('Only accepted proposals can be materialised.');
        }

        if ($proposal->materialisation_status === ProposalMaterialisationStatusEnum::Materialised
            || $proposal->materialisation_status === ProposalMaterialisationStatusEnum::LocalReview) {
            return $proposal;
        }

        try {
            $source = $this->resolveSource($proposal);
            $this->denylist->assertAllowed($proposal->title.' '.$proposal->path, $source->remote_url, $source->local_path);
            $this->assertSafeRelativePath($proposal->path);

            $branch = 'instituta/proposal-'.$proposal->id;
            [$repoRoot, $contentRoot] = $this->prepareWorkspace($proposal, $source);
            $this->ensureGitRepository($repoRoot);
            $this->checkoutBranch($repoRoot, $branch, $source->ref ?? 'main');
            $this->applyChange($contentRoot, $proposal);
            $commit = $this->commitChange($repoRoot, $proposal, $source->path);
            $result = $this->publish($repoRoot, $proposal, $source, $branch);

            $proposal->forceFill([
                'source_id' => $source->id,
                'materialisation_status' => $result['status'],
                'remote_branch' => $branch,
                'remote_pr_url' => $result['pr_url'],
                'materialised_commit' => $commit,
                'materialised_at' => now(),
                'materialisation_error' => null,
            ])->save();
        } catch (Throwable $exception) {
            $proposal->forceFill([
                'materialisation_status' => ProposalMaterialisationStatusEnum::Failed,
                'materialisation_error' => mb_substr($exception->getMessage(), 0, 1000),
            ])->save();

            throw $exception;
        }

        return $proposal->refresh();
    }

    private function resolveSource(Proposal $proposal): Source
    {
        if ($proposal->source_id !== null && $proposal->source_id !== '') {
            $source = Source::query()->find($proposal->source_id);

            if ($source === null || $source->organisation_id !== $proposal->organisation_id) {
                throw new InvalidArgumentException('Proposal source is missing or outside the organisation.');
            }

            return $source;
        }

        $source = Source::query()
            ->where('organisation_id', $proposal->organisation_id)
            ->orderBy('created_at')
            ->first();

        if ($source === null) {
            throw new InvalidArgumentException('No ingest source is registered for this organisation.');
        }

        return $source;
    }

    private function assertSafeRelativePath(string $path): void
    {
        if ($path === ''
            || str_starts_with($path, '/')
            || str_contains($path, '..')
            || str_contains($path, "\0")) {
            throw new InvalidArgumentException('Proposal path is not a safe relative path.');
        }
    }

    /**
     * @return array{0: string, 1: string} repo root (with .git) and content root (optional source.path).
     */
    private function prepareWorkspace(Proposal $proposal, Source $source): array
    {
        $repoRoot = storage_path('app/instituta/proposals/'.$proposal->id);
        File::ensureDirectoryExists(dirname($repoRoot));

        if (is_dir($repoRoot)) {
            File::deleteDirectory($repoRoot);
        }

        $origin = match ($source->provider) {
            SourceProviderEnum::Local => $source->local_path,
            SourceProviderEnum::Git, SourceProviderEnum::Github => storage_path('app/instituta/sources/'.$source->id),
        };

        if ($origin === null || $origin === '' || ! is_dir($origin)) {
            throw new RuntimeException('Source workspace is not available for materialisation.');
        }

        File::copyDirectory($origin, $repoRoot);

        $subdir = $source->path;
        if ($subdir !== null && $subdir !== '') {
            $contentRoot = $repoRoot.DIRECTORY_SEPARATOR.ltrim($subdir, DIRECTORY_SEPARATOR);

            if (! is_dir($contentRoot)) {
                throw new RuntimeException('Source subpath is missing in the review workspace.');
            }

            return [$repoRoot, $contentRoot];
        }

        return [$repoRoot, $repoRoot];
    }

    private function ensureGitRepository(string $workspace): void
    {
        if (is_dir($workspace.DIRECTORY_SEPARATOR.'.git')) {
            return;
        }

        $init = Process::path($workspace)->run(['git', 'init']);
        if ($init->failed()) {
            throw new RuntimeException('git init failed: '.trim($init->errorOutput().' '.$init->output()));
        }

        Process::path($workspace)->run(['git', 'config', 'user.email', 'instituta@localhost']);
        Process::path($workspace)->run(['git', 'config', 'user.name', 'Instituta']);
    }

    private function checkoutBranch(string $workspace, string $branch, string $baseRef): void
    {
        $checkoutBase = Process::path($workspace)->run(['git', 'checkout', $baseRef]);
        if ($checkoutBase->failed()) {
            // Fresh init / fixture without the named ref: stay on current HEAD.
        }

        $checkout = Process::path($workspace)->run(['git', 'checkout', '-B', $branch]);
        if ($checkout->failed()) {
            throw new RuntimeException('git checkout failed: '.trim($checkout->errorOutput().' '.$checkout->output()));
        }
    }

    private function applyChange(string $workspace, Proposal $proposal): void
    {
        $absolutePath = $workspace.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $proposal->path);
        $realWorkspace = realpath($workspace);

        if ($realWorkspace === false) {
            throw new RuntimeException('Review workspace is not readable.');
        }

        if ($proposal->action === ProposalActionEnum::Delete) {
            if (is_file($absolutePath)) {
                unlink($absolutePath);
            }

            return;
        }

        $directory = dirname($absolutePath);
        File::ensureDirectoryExists($directory);

        $body = $proposal->body ?? '';
        if (file_put_contents($absolutePath, $body) === false) {
            throw new RuntimeException('Failed to write proposal body into the review workspace.');
        }

        $realFile = realpath($absolutePath);
        if ($realFile === false || ! str_starts_with($realFile, $realWorkspace.DIRECTORY_SEPARATOR)) {
            @unlink($absolutePath);
            throw new InvalidArgumentException('Proposal path escaped the review workspace.');
        }
    }

    private function commitChange(string $repoRoot, Proposal $proposal, ?string $sourceSubdir): string
    {
        $relativePath = ($sourceSubdir !== null && $sourceSubdir !== '')
            ? trim($sourceSubdir, '/').'/'.$proposal->path
            : $proposal->path;

        $add = Process::path($repoRoot)->run(['git', 'add', '-A', '--', $relativePath]);
        if ($add->failed()) {
            throw new RuntimeException('git add failed: '.trim($add->errorOutput().' '.$add->output()));
        }

        $message = 'instituta: '.$proposal->action->value.' '.$proposal->stable_key;
        $commit = Process::path($repoRoot)->run([
            'git',
            '-c',
            'user.email=instituta@localhost',
            '-c',
            'user.name=Instituta',
            'commit',
            '-m',
            $message,
            '--allow-empty',
        ]);
        if ($commit->failed()) {
            throw new RuntimeException('git commit failed: '.trim($commit->errorOutput().' '.$commit->output()));
        }

        $rev = Process::path($repoRoot)->run(['git', 'rev-parse', 'HEAD']);
        if ($rev->failed()) {
            throw new RuntimeException('git rev-parse failed: '.trim($rev->errorOutput().' '.$rev->output()));
        }

        $hash = trim($rev->output());

        return $hash !== '' ? $hash : 'HEAD';
    }

    /**
     * @return array{status: ProposalMaterialisationStatusEnum, pr_url: string|null}
     */
    private function publish(string $workspace, Proposal $proposal, Source $source, string $branch): array
    {
        return match ($source->provider) {
            SourceProviderEnum::Local => [
                'status' => ProposalMaterialisationStatusEnum::LocalReview,
                'pr_url' => null,
            ],
            SourceProviderEnum::Git => $this->pushBranch($workspace, $source, $branch),
            SourceProviderEnum::Github => $this->pushAndOpenGithubPullRequest($workspace, $proposal, $source, $branch),
        };
    }

    /**
     * @return array{status: ProposalMaterialisationStatusEnum, pr_url: string|null}
     */
    private function pushBranch(string $workspace, Source $source, string $branch): array
    {
        $remote = $source->remote_url;
        if ($remote === null || $remote === '') {
            throw new RuntimeException('Git source is missing remote_url.');
        }

        $push = Process::path($workspace)->run(['git', 'push', '-u', $remote, 'HEAD:'.$branch]);
        if ($push->failed()) {
            throw new RuntimeException('git push failed: '.trim($push->errorOutput().' '.$push->output()));
        }

        return [
            'status' => ProposalMaterialisationStatusEnum::Materialised,
            'pr_url' => null,
        ];
    }

    /**
     * @return array{status: ProposalMaterialisationStatusEnum, pr_url: string|null}
     */
    private function pushAndOpenGithubPullRequest(
        string $workspace,
        Proposal $proposal,
        Source $source,
        string $branch,
    ): array {
        $token = $this->githubToken();
        if ($token === null) {
            throw new RuntimeException('GitHub materialisation refused: INSTITUTA_GITHUB_TOKEN is not configured.');
        }

        $remote = $source->remote_url;
        if ($remote === null || $remote === '') {
            throw new RuntimeException('GitHub source is missing remote_url.');
        }

        $authenticated = $this->authenticatedGithubRemote($remote, $token);
        $push = Process::path($workspace)->run(['git', 'push', '-u', $authenticated, 'HEAD:'.$branch]);
        if ($push->failed()) {
            throw new RuntimeException('git push failed: '.trim($push->errorOutput().' '.$push->output()));
        }

        [$owner, $repo] = $this->parseGithubOwnerRepo($remote);
        $base = $source->ref !== null && $source->ref !== '' ? $source->ref : 'main';

        $response = Http::connectTimeout(3)
            ->timeout(20)
            ->withToken($token)
            ->acceptJson()
            ->asJson()
            ->post("https://api.github.com/repos/{$owner}/{$repo}/pulls", [
                'title' => $proposal->title,
                'head' => $branch,
                'base' => $base,
                'body' => 'Instituta proposal `'.$proposal->id."`.\n\nStable key: `{$proposal->stable_key}`\nAction: `{$proposal->action->value}`\n",
            ]);

        if ($response->failed()) {
            throw new RuntimeException('GitHub pull request create failed (HTTP '.$response->status().').');
        }

        $prUrl = $response->json('html_url');
        if (! is_string($prUrl) || $prUrl === '') {
            throw new RuntimeException('GitHub pull request create returned no html_url.');
        }

        return [
            'status' => ProposalMaterialisationStatusEnum::Materialised,
            'pr_url' => $prUrl,
        ];
    }

    private function authenticatedGithubRemote(string $remote, string $token): string
    {
        if (str_starts_with($remote, 'https://github.com/')) {
            return 'https://x-access-token:'.$token.'@github.com/'.substr($remote, strlen('https://github.com/'));
        }

        return $remote;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function parseGithubOwnerRepo(string $remote): array
    {
        if (preg_match('#github\.com[:/](?P<owner>[^/]+)/(?P<repo>[^/]+?)(?:\.git)?/?$#', $remote, $matches) !== 1) {
            throw new InvalidArgumentException('Could not parse GitHub owner/repo from remote_url.');
        }

        return [$matches['owner'], $matches['repo']];
    }

    private function githubToken(): ?string
    {
        $token = config('instituta.ingest.github_token');
        if (! is_string($token) || trim($token) === '') {
            return null;
        }

        return trim($token);
    }
}
