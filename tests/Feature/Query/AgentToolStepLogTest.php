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

class AgentToolStepLogTest extends TestCase
{
    use FakesAgentResponses;

    public function test_tool_steps_are_persisted_and_accessible_via_query_log(): void
    {
        $this->authenticateApiClient();

        $dimensions = config('rag.openai.embedding_dimensions', 1536);
        $document = Document::factory()->create(['status' => 'processed']);
        DocumentChunk::factory()->for($document)->create([
            'embedding' => array_fill(0, $dimensions, 0.1),
        ]);

        OpenAI::fake([
            $this->fakeFunctionToolCall('call_1', KnowledgeBaseSearchTool::NAME, ['query' => 'Питання про гарантію?']),
            EmbeddingsCreateResponse::fake(['data' => [['embedding' => array_fill(0, $dimensions, 0.1)]]]),
            $this->fakeFinalAnswer('Відповідь.'),
        ]);

        $response = $this->postJson('/api/v1/queries', ['question' => 'Питання про гарантію?']);
        $response->assertOk();

        $log = QueryLog::findOrFail($response->json('query_log_id'));

        $this->assertCount(1, $log->toolSteps);
        $this->assertSame('search_knowledge_base', $log->toolSteps->first()->tool);
        $this->assertSame('Питання про гарантію?', $log->toolSteps->first()->input);
        $this->assertNotNull($log->toolSteps->first()->output);
    }
}
