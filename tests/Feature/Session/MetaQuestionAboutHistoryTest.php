<?php

namespace Tests\Feature\Session;

use App\Models\Document;
use App\Models\DocumentChunk;
use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Resources\Chat;
use OpenAI\Responses\Chat\CreateResponse;
use OpenAI\Responses\Embeddings\CreateResponse as EmbeddingsCreateResponse;
use Tests\TestCase;

class MetaQuestionAboutHistoryTest extends TestCase
{
    /**
     * Мета-питання про саму розмову (наприклад, "про що ми говорили?") не
     * стосується бази знань, тому пошук по документах нічого не знаходить.
     * Раніше система через це одразу повертала "не знайдено", навіть не
     * звертаючись до LLM з історією діалогу. Тепер LLM MUST викликатись,
     * якщо є історія розмови, навіть без релевантних чанків.
     */
    public function test_meta_question_about_conversation_uses_llm_with_history_instead_of_short_circuiting(): void
    {
        $this->authenticateApiClient();

        $dimensions = config('rag.openai.embedding_dimensions', 1536);
        $document = Document::factory()->create(['status' => 'processed']);
        DocumentChunk::factory()->for($document)->create([
            'embedding' => array_fill(0, $dimensions, 0.9),
        ]);

        OpenAI::fake([
            // Перше питання: релевантний чанк знайдено
            EmbeddingsCreateResponse::fake(['data' => [['embedding' => array_fill(0, $dimensions, 0.9)]]]),
            CreateResponse::fake(['choices' => [['message' => ['role' => 'assistant', 'content' => 'Гарантія 24 місяці.']]]]),
            // Друге (мета-)питання: ембединг зовсім не схожий на чанк → пошук нічого не знайде
            EmbeddingsCreateResponse::fake(['data' => [['embedding' => array_fill(0, $dimensions, -0.9)]]]),
            CreateResponse::fake(['choices' => [['message' => ['role' => 'assistant', 'content' => 'Ми говорили про гарантію на виріб.']]]]),
        ]);

        $first = $this->postJson('/api/v1/queries', ['question' => 'Яка гарантія на виріб?']);
        $first->assertOk();
        $sessionId = $first->json('session_id');

        $second = $this->postJson('/api/v1/queries', [
            'question' => 'Про що ми щойно говорили?',
            'session_id' => $sessionId,
        ]);

        $second->assertOk();
        $second->assertJsonPath('answer', 'Ми говорили про гарантію на виріб.');
        $second->assertJsonPath('sources', []);

        OpenAI::assertSent(Chat::class, 2);
    }
}
