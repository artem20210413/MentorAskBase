<?php

namespace App\Services\Rag;

use App\Models\DocumentChunk;
use Illuminate\Support\Collection;
use Pgvector\Laravel\Distance;

class VectorSearchService
{
    /**
     * @param  array<int, float>  $questionEmbedding
     * @return Collection<int, DocumentChunk>
     */
    public function search(array $questionEmbedding): Collection
    {
        $topK = config('rag.search.top_k');
        $minRelevance = config('rag.search.min_relevance_percent');

        return DocumentChunk::query()
            ->whereHas('document', fn ($q) => $q->where('status', 'processed'))
            ->nearestNeighbors('embedding', $questionEmbedding, Distance::Cosine)
            ->take($topK)
            ->get()
            ->filter(fn (DocumentChunk $chunk) => self::relevancePercent($chunk->neighbor_distance) >= $minRelevance)
            ->values();
    }

    public static function relevancePercent(float $distance): int
    {
        return (int) round(max(0, 1 - $distance) * 100);
    }
}
