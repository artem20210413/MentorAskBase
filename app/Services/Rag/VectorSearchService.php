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
        $maxDistance = config('rag.search.max_relevant_distance');

        return DocumentChunk::query()
            ->whereHas('document', fn ($q) => $q->where('status', 'processed'))
            ->nearestNeighbors('embedding', $questionEmbedding, Distance::Cosine)
            ->take($topK)
            ->get()
            ->filter(fn (DocumentChunk $chunk) => $chunk->neighbor_distance <= $maxDistance)
            ->values();
    }
}
