<?php

namespace Tests\Feature\Session;

use App\Models\Document;
use App\Models\DocumentChunk;
use App\Services\Rag\KnowledgeBaseSearchTool;
use Illuminate\Support\Str;
use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Responses\Embeddings\CreateResponse as EmbeddingsCreateResponse;
use Tests\Support\FakesAgentResponses;
use Tests\TestCase;

class UnknownSessionTest extends TestCase
{
    use FakesAgentResponses;

    public function test_unknown_session_id_creates_new_session_without_error(): void
    {
        $this->authenticateApiClient();

        $dimensions = config('rag.openai.embedding_dimensions', 1536);
        $document = Document::factory()->create(['status' => 'processed']);
        DocumentChunk::factory()->for($document)->create([
            'embedding' => array_fill(0, $dimensions, 0.1),
        ]);

        OpenAI::fake([
            $this->fakeFunctionToolCall('call_1', KnowledgeBaseSearchTool::NAME, ['query' => 'Питання з вигаданою сесією?']),
            EmbeddingsCreateResponse::fake(['data' => [['embedding' => array_fill(0, $dimensions, 0.1)]]]),
            $this->fakeFinalAnswer('Відповідь.'),
        ]);

        $fakeSessionId = (string) Str::uuid();

        $response = $this->postJson('/api/v1/queries', [
            'question' => 'Питання з вигаданою сесією?',
            'session_id' => $fakeSessionId,
        ]);

        $response->assertOk();
        $this->assertNotSame($fakeSessionId, $response->json('session_id'));
        $this->assertDatabaseHas('conversation_sessions', ['id' => $response->json('session_id')]);
    }
}
