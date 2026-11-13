<?php

declare(strict_types=1);

namespace App\Services;

use App\Brain\Data\PerimeterData;
use App\Models\EmbeddingVersion;
use App\Models\ItemSection;
use Illuminate\Support\Collection;
use Laravel\Ai\Embeddings;
use RuntimeException;

/**
 * Embed item sections and rank neighbours inside one perimeter (#65).
 * Off by default; uses laravel/ai with Embeddings::fake() in tests.
 */
final class BrainEmbeddingService
{
    /**
     * Resolve or create the active EmbeddingVersion from config.
     */
    public function activeModel(): EmbeddingVersion
    {
        $name = (string) config('ai.embeddings.model_name', 'local-embedding');
        $version = (string) config('ai.embeddings.model_version', '1');
        $dimensions = (int) config('ai.embeddings.dimensions', 8);

        return EmbeddingVersion::query()->firstOrCreate(
            ['name' => $name, 'version' => $version],
            ['dimensions' => $dimensions],
        );
    }

    /**
     * Generate and persist an embedding for one section when embeddings are enabled.
     *
     * @throws RuntimeException when the provider returns an empty vector
     */
    public function embedSection(ItemSection $section): ItemSection
    {
        if (! config('ai.embeddings.enabled')) {
            return $section;
        }

        $payload = $this->sectionPayload($section);
        $hash = hash('sha256', $payload);
        $model = $this->activeModel();

        if (
            $section->embedding_content_hash === $hash
            && $section->embedding_model_id === $model->id
            && is_array($section->embedding_vector)
            && $section->embedding_vector !== []
        ) {
            return $section;
        }

        $providerModel = (string) config(
            'ai.providers.'.config('ai.default_for_embeddings').'.models.embeddings.default',
            $model->name,
        );

        $response = Embeddings::for([$payload])
            ->dimensions($model->dimensions)
            ->generate(model: $providerModel);

        $vector = $response->first();

        if ($vector === []) {
            throw new RuntimeException('Embedding response missing vector.');
        }

        $floats = array_map(
            static fn (mixed $value): float => (float) $value,
            $vector,
        );

        $section->forceFill([
            'embedding_vector' => $floats,
            'embedding_content_hash' => $hash,
            'embedding_model_id' => $model->id,
            'embedded_at' => now(),
        ])->save();

        return $section->refresh();
    }

    /**
     * Cosine-ranked sections inside the perimeter (embedded rows only).
     *
     * @return list<array{section_id: string, item_id: string, anchor: string, heading: string, score: float}>
     */
    public function similar(
        PerimeterData $perimeter,
        string $query,
        int $limit = 10,
    ): array {
        if (! config('ai.embeddings.enabled')) {
            return [];
        }

        $limit = max(1, min(50, $limit));
        $model = $this->activeModel();

        $providerModel = (string) config(
            'ai.providers.'.config('ai.default_for_embeddings').'.models.embeddings.default',
            $model->name,
        );

        $response = Embeddings::for([$query])
            ->dimensions($model->dimensions)
            ->generate(model: $providerModel);

        $queryVector = $response->first();

        if ($queryVector === []) {
            return [];
        }

        $queryFloats = array_map(
            static fn (mixed $value): float => (float) $value,
            $queryVector,
        );

        /** @var Collection<int, ItemSection> $sections */
        $sections = ItemSection::query()
            ->whereNotNull('embedding_vector')
            ->where('embedding_model_id', $model->id)
            ->whereHas('item', static function ($builder) use ($perimeter): void {
                $builder->withinPerimeter($perimeter);
            })
            ->with('item:id,stable_key,path,title')
            ->get();

        $ranked = [];

        foreach ($sections as $section) {
            $vector = $section->embedding_vector;

            if (! is_array($vector) || $vector === []) {
                continue;
            }

            $score = $this->cosineSimilarity($queryFloats, array_map(
                static fn (mixed $value): float => (float) $value,
                $vector,
            ));

            $ranked[] = [
                'section_id' => $section->id,
                'item_id' => $section->item_id,
                'anchor' => $section->anchor,
                'heading' => $section->heading,
                'score' => $score,
            ];
        }

        usort(
            $ranked,
            static fn (array $left, array $right): int => $right['score'] <=> $left['score'],
        );

        return array_slice($ranked, 0, $limit);
    }

    /**
     * Sections that need (re)embedding for the active model version.
     *
     * @return Collection<int, ItemSection>
     */
    public function sectionsNeedingEmbed(?string $sectionId = null): Collection
    {
        $model = $this->activeModel();
        $query = ItemSection::query()->orderBy('id');

        if ($sectionId !== null && $sectionId !== '') {
            $query->where('id', $sectionId);
        } else {
            $query->where(function ($builder) use ($model): void {
                $builder->whereNull('embedding_vector')
                    ->orWhereNull('embedding_model_id')
                    ->orWhere('embedding_model_id', '!=', $model->id);
            });
        }

        return $query->get();
    }

    private function sectionPayload(ItemSection $section): string
    {
        return trim($section->heading."\n\n".$section->body);
    }

    /**
     * @param  array<int, float>  $left
     * @param  array<int, float>  $right
     */
    private function cosineSimilarity(array $left, array $right): float
    {
        $leftValues = array_values($left);
        $rightValues = array_values($right);
        $size = min(count($leftValues), count($rightValues));

        if ($size === 0) {
            return 0.0;
        }

        $dot = 0.0;
        $leftNorm = 0.0;
        $rightNorm = 0.0;

        for ($index = 0; $index < $size; $index++) {
            $dot += $leftValues[$index] * $rightValues[$index];
            $leftNorm += $leftValues[$index] ** 2;
            $rightNorm += $rightValues[$index] ** 2;
        }

        if ($leftNorm <= 0.0 || $rightNorm <= 0.0) {
            return 0.0;
        }

        return $dot / (sqrt($leftNorm) * sqrt($rightNorm));
    }
}
