<?php

namespace Tests\Feature\Query;

use App\Models\Document;
use App\Models\DocumentChunk;
use App\Models\QueryLog;
use App\Services\Rag\KnowledgeBaseSearchTool;
use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Responses\Embeddings\CreateResponse as EmbeddingsCreateResponse;
use Tests\Support\FakesAgentResponses;
use Tests\TestCase;

class QueryLoggingTest extends TestCase
{
    use FakesAgentResponses;

    public function test_every_query_is_recorded_in_the_log_with_session_and_timestamp(): void
    {
        $this->authenticateApiClient();

        $dimensions = config('rag.openai.embedding_dimensions', 1536);
        $document = Document::factory()->create(['status' => 'processed']);
        DocumentChunk::factory()->for($document)->create([
            'embedding' => array_fill(0, $dimensions, 0.1),
        ]);

        OpenAI::fake([
            $this->fakeFunctionToolCall('call_1', KnowledgeBaseSearchTool::NAME, ['query' => 'Питання?']),
            EmbeddingsCreateResponse::fake(['data' => [['embedding' => array_fill(0, $dimensions, 0.1)]]]),
            $this->fakeFinalAnswer('Відповідь.'),
        ]);

        $response = $this->postJson('/api/v1/queries', ['question' => 'Питання?']);
        $response->assertOk();

        $log = QueryLog::first();

        $this->assertNotNull($log);
        $this->assertSame($response->json('session_id'), $log->conversation_session_id);
        $this->assertNotNull($log->created_at);

        // FR-010b: токен-метрика зберігається внутрішньо, але не потрапляє у відповідь API
        $this->assertArrayNotHasKey('llm_input_tokens', $response->json());
        $this->assertArrayNotHasKey('llm_output_tokens', $response->json());
    }
}
