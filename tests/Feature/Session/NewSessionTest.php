<?php

namespace Tests\Feature\Session;

use App\Models\Document;
use App\Models\DocumentChunk;
use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Responses\Chat\CreateResponse;
use OpenAI\Responses\Embeddings\CreateResponse as EmbeddingsCreateResponse;
use Tests\TestCase;

class NewSessionTest extends TestCase
{
    public function test_first_question_creates_new_session(): void
    {
        $this->authenticateApiClient();

        $dimensions = config('rag.openai.embedding_dimensions', 1536);
        $document = Document::factory()->create(['status' => 'processed']);
        DocumentChunk::factory()->for($document)->create([
            'embedding' => array_fill(0, $dimensions, 0.1),
        ]);

        OpenAI::fake([
            EmbeddingsCreateResponse::fake(['data' => [['embedding' => array_fill(0, $dimensions, 0.1)]]]),
            CreateResponse::fake(['choices' => [['message' => ['role' => 'assistant', 'content' => 'Відповідь.']]]]),
        ]);

        $response = $this->postJson('/api/v1/queries', ['question' => 'Перше питання розмови?']);

        $response->assertOk();
        $this->assertNotEmpty($response->json('session_id'));
        $this->assertDatabaseHas('conversation_sessions', ['id' => $response->json('session_id')]);
    }
}
