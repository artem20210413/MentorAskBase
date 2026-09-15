<?php

namespace Tests\Feature\Query;

use App\Services\Rag\KnowledgeBaseSearchTool;
use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Responses\Embeddings\CreateResponse as EmbeddingsCreateResponse;
use Tests\Support\FakesAgentResponses;
use Tests\TestCase;

class NoRelevantInfoTest extends TestCase
{
    use FakesAgentResponses;

    public function test_question_with_no_matching_documents_returns_honest_no_info_answer(): void
    {
        $this->authenticateApiClient();

        $dimensions = config('rag.openai.embedding_dimensions', 1536);

        OpenAI::fake([
            $this->fakeFunctionToolCall('call_1', KnowledgeBaseSearchTool::NAME, ['query' => 'Питання без жодного релевантного документа?']),
            EmbeddingsCreateResponse::fake([
                'data' => [['embedding' => array_fill(0, $dimensions, 0.1)]],
            ]),
            $this->fakeFinalAnswer(__('bot.no_relevant_info_marker')),
        ]);

        $response = $this->postJson('/api/v1/queries', ['question' => 'Питання без жодного релевантного документа?']);

        $response->assertOk();
        $response->assertJsonPath('sources', []);
        $this->assertNotEmpty($response->json('answer'));
    }
}
