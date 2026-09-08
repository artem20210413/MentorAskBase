<?php

namespace Tests\Feature\Query;

use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Responses\Embeddings\CreateResponse as EmbeddingsCreateResponse;
use Tests\TestCase;

class EmptySourcesTest extends TestCase
{
    public function test_sources_are_empty_when_no_relevant_information_found(): void
    {
        $this->authenticateApiClient();

        $dimensions = config('rag.openai.embedding_dimensions', 1536);

        OpenAI::fake([
            EmbeddingsCreateResponse::fake(['data' => [['embedding' => array_fill(0, $dimensions, 0.1)]]]),
        ]);

        $response = $this->postJson('/api/v1/queries', ['question' => 'Питання без документів у базі?']);

        $response->assertOk();
        $response->assertJsonPath('sources', []);
    }
}
