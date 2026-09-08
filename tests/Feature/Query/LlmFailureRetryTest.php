<?php

namespace Tests\Feature\Query;

use App\Models\Document;
use App\Models\DocumentChunk;
use Exception;
use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Responses\Chat\CreateResponse;
use OpenAI\Responses\Embeddings\CreateResponse as EmbeddingsCreateResponse;
use Tests\TestCase;

class LlmFailureRetryTest extends TestCase
{
    public function test_single_retry_then_success(): void
    {
        $this->authenticateApiClient();

        $dimensions = config('rag.openai.embedding_dimensions', 1536);
        $document = Document::factory()->create(['status' => 'processed']);
        DocumentChunk::factory()->for($document)->create([
            'embedding' => array_fill(0, $dimensions, 0.1),
        ]);

        OpenAI::fake([
            EmbeddingsCreateResponse::fake(['data' => [['embedding' => array_fill(0, $dimensions, 0.1)]]]),
            new Exception('Тимчасова недоступність LLM'),
            CreateResponse::fake(['choices' => [['message' => ['role' => 'assistant', 'content' => 'Відповідь після ретраю.']]]]),
        ]);

        $response = $this->postJson('/api/v1/queries', ['question' => 'Питання?']);

        $response->assertOk();
        $response->assertJsonPath('answer', 'Відповідь після ретраю.');
    }

    public function test_failure_after_retry_returns_clear_error(): void
    {
        $this->authenticateApiClient();

        $dimensions = config('rag.openai.embedding_dimensions', 1536);
        $document = Document::factory()->create(['status' => 'processed']);
        DocumentChunk::factory()->for($document)->create([
            'embedding' => array_fill(0, $dimensions, 0.1),
        ]);

        OpenAI::fake([
            EmbeddingsCreateResponse::fake(['data' => [['embedding' => array_fill(0, $dimensions, 0.1)]]]),
            new Exception('Перший збій'),
            new Exception('Другий збій (після ретраю)'),
        ]);

        $response = $this->postJson('/api/v1/queries', ['question' => 'Питання?']);

        $response->assertStatus(502);
        $this->assertDatabaseCount('query_logs', 0);
    }
}
