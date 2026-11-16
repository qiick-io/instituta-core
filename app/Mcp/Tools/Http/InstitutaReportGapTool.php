<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Http;

use App\Actions\ReportGapAction;
use App\Data\ReportGapData;
use App\Enums\ContractErrorCodeEnum;
use App\Enums\GapReportKindEnum;
use App\Exceptions\ContractException;
use App\Mcp\Support\HttpBrainContext;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

/**
 * Lets an agent say what is missing or wrong in the brain (#229) without writing a proposal.
 */
#[Name('instituta_report_gap')]
#[Description('Report a gap in the brain when you cannot write the fix yourself: kind missing (no entry answers the question), contradiction (two entries disagree), unclear or outdated. Give a one-line summary, the question that led you here, and the related items (id, stable_key or path, optional anchor). It changes nothing in the brain: the owners see it and turn it into a proposal or dismiss it. Any key that can read may report.')]
class InstitutaReportGapTool extends Tool
{
    public function __construct(
        private readonly HttpBrainContext $brain,
        private readonly ReportGapAction $report = new ReportGapAction,
    ) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'kind' => $schema->string()->enum(array_column(GapReportKindEnum::cases(), 'value'))->description('missing, contradiction, unclear or outdated')->required(),
            'summary' => $schema->string()->description('One line: what is missing or wrong')->required(),
            'question' => $schema->string()->description('The question or task that led you here')->nullable(),
            'related' => $schema->array()->items($schema->object([
                'item' => $schema->string()->description('Item id, stable_key or path')->required(),
                'anchor' => $schema->string()->description('Optional section anchor'),
            ]))->max(10)->description('The items it concerns (at most 10)'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $kind = GapReportKindEnum::tryFrom((string) $request->get('kind'));
        $summary = $request->get('summary');
        $question = $request->get('question');
        $related = $this->related($request->get('related'));

        if ($kind === null || ! is_string($summary) || trim($summary) === '' || mb_strlen($summary) > 2000 || $related === null) {
            return Response::error(ContractErrorCodeEnum::ValidationFailed->mcpText('kind must be missing, contradiction, unclear or outdated; summary is required; related is a list of at most 10 {item, anchor}.'));
        }

        try {
            $gapReport = $this->report->execute(
                $this->brain->perimeter(),
                $this->brain->apiKey(),
                new ReportGapData($kind, trim($summary), is_string($question) && trim($question) !== '' ? trim($question) : null, $related),
                'mcp',
            );
        } catch (ContractException $exception) {
            return Response::error($exception->errorCode->mcpText($exception->getMessage()));
        }

        return Response::structured(['id' => $gapReport->id, 'status' => $gapReport->status->value]);
    }

    /**
     * @return list<array{item: string, anchor: string|null}>|null
     */
    private function related(mixed $rawRelated): ?array
    {
        if ($rawRelated === null) {
            return [];
        }

        if (! is_array($rawRelated) || ! array_is_list($rawRelated) || count($rawRelated) > 10) {
            return null;
        }

        $related = [];

        foreach ($rawRelated as $entry) {
            $item = is_array($entry) ? ($entry['item'] ?? null) : null;
            $anchor = is_array($entry) ? ($entry['anchor'] ?? null) : null;

            if (! is_string($item) || $item === '' || ($anchor !== null && ! is_string($anchor))) {
                return null;
            }

            $related[] = ['item' => $item, 'anchor' => $anchor === '' ? null : $anchor];
        }

        return $related;
    }
}
