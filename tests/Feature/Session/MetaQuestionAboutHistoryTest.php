<?php

namespace Tests\Feature\Session;

use App\Models\Document;
use App\Models\DocumentChunk;
use App\Services\Rag\KnowledgeBaseSearchTool;
use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Resources\Chat;
use OpenAI\Responses\Chat\CreateResponse as ChatCreateResponse;
use OpenAI\Responses\Embeddings\CreateResponse as EmbeddingsCreateResponse;
use Tests\Support\FakesAgentResponses;
use Tests\TestCase;

class MetaQuestionAboutHistoryTest extends TestCase
{
    use FakesAgentResponses;

    /**
     * Мета-питання про саму розмову (наприклад, "про що ми говорили?") не
     * стосується бази знань — модель відповідає напряму з історії розмови,
     * не викликаючи search_knowledge_base (FR-013 Edge Case: жодних зайвих
     * пошукових кроків для таких питань).
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
            $this->fakeFunctionToolCall('call_1', KnowledgeBaseSearchTool::NAME, ['query' => 'Яка гарантія на виріб?']),
            EmbeddingsCreateResponse::fake(['data' => [['embedding' => array_fill(0, $dimensions, 0.9)]]]),
            $this->fakeFinalAnswer('Гарантія 24 місяці.'),
            // Друге (мета-)питання: переформулювання (QueryRewriter), потім
            // пряма відповідь з історії без виклику search_knowledge_base
            ChatCreateResponse::fake(['choices' => [['message' => ['role' => 'assistant', 'content' => 'Про що ми щойно говорили?']]]]),
            $this->fakeFinalAnswer('Ми говорили про гарантію на виріб.'),
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

        // Лише один виклик Chat-ресурсу за обидва питання — переформулювання другого питання
        // (перше без історії не потребує переформулювання).
        OpenAI::assertSent(Chat::class, 1);
    }
}
