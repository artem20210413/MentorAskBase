<?php

namespace App\Services\Rag;

use OpenAI\Laravel\Facades\OpenAI;

class EmbeddingService
{
    /**
     * @return array<int, float>
     */
    public function embed(string $text): array
    {
        $response = OpenAI::embeddings()->create([
            'model' => config('rag.openai.embedding_model'),
            'input' => $text,
        ]);

        return $response->embeddings[0]->embedding;
    }
}
