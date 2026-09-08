<?php

namespace Tests\Feature\Session;

use App\Models\Document;
use App\Models\DocumentChunk;
use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Resources\Chat;
use OpenAI\Responses\Chat\CreateResponse;
use OpenAI\Responses\Embeddings\CreateResponse as EmbeddingsCreateResponse;
use Tests\TestCase;

class ContextContinuityTest extends TestCase
{
    public function test_second_question_in_session_includes_previous_history_in_llm_request(): void
    {
        $this->authenticateApiClient();

        $dimensions = config('rag.openai.embedding_dimensions', 1536);
        $document = Document::factory()->create(['status' => 'processed']);
        DocumentChunk::factory()->for($document)->create([
            'embedding' => array_fill(0, $dimensions, 0.1),
        ]);

        OpenAI::fake([
            EmbeddingsCreateResponse::fake(['data' => [['embedding' => array_fill(0, $dimensions, 0.1)]]]),
            CreateResponse::fake(['choices' => [['message' => ['role' => 'assistant', 'content' => 'Гарантія 24 місяці.']]]]),
            // Друге питання: спершу переформулювання запиту на основі історії (QueryRewriter)
            CreateResponse::fake(['choices' => [['message' => ['role' => 'assistant', 'content' => 'Яка гарантія на виріб Y?']]]]),
            EmbeddingsCreateResponse::fake(['data' => [['embedding' => array_fill(0, $dimensions, 0.1)]]]),
            CreateResponse::fake(['choices' => [['message' => ['role' => 'assistant', 'content' => 'А для виробу Y — 12 місяців.']]]]),
        ]);

        $first = $this->postJson('/api/v1/queries', ['question' => 'Яка гарантія на виріб X?']);
        $first->assertOk();
        $sessionId = $first->json('session_id');

        $second = $this->postJson('/api/v1/queries', [
            'question' => 'А для виробу Y?',
            'session_id' => $sessionId,
        ]);

        $second->assertOk();
        $this->assertSame($sessionId, $second->json('session_id'));

        OpenAI::assertSent(Chat::class, function (string $method, array $params) {
            $messages = $params['messages'] ?? [];

            return $method === 'create' && collect($messages)->contains(
                fn ($m) => ($m['role'] ?? null) === 'user' && ($m['content'] ?? null) === 'Яка гарантія на виріб X?'
            ) && collect($messages)->last()['content'] === 'А для виробу Y?';
        });
    }
}
