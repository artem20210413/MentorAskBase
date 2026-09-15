<?php

namespace Tests\Feature\Session;

use App\Models\Document;
use App\Models\DocumentChunk;
use App\Services\Rag\KnowledgeBaseSearchTool;
use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Responses\Chat\CreateResponse as ChatCreateResponse;
use OpenAI\Responses\Embeddings\CreateResponse as EmbeddingsCreateResponse;
use Tests\Support\FakesAgentResponses;
use Tests\TestCase;

class LanguageSwitchWithinSessionTest extends TestCase
{
    use FakesAgentResponses;

    /**
     * Edge case зі spec.md: мова визначається окремо для кожного питання
     * (FR-009a), тож відповідь на друге питання іншою мовою в тій самій
     * сесії відповідає новій мові, а не мові першого питання.
     */
    public function test_answer_language_follows_each_question_independently_within_same_session(): void
    {
        $this->authenticateApiClient();

        $dimensions = config('rag.openai.embedding_dimensions', 1536);
        $document = Document::factory()->create(['status' => 'processed']);
        DocumentChunk::factory()->for($document)->create([
            'embedding' => array_fill(0, $dimensions, 0.1),
        ]);

        OpenAI::fake([
            $this->fakeFunctionToolCall('call_1', KnowledgeBaseSearchTool::NAME, ['query' => 'Яка гарантія на виріб X?']),
            EmbeddingsCreateResponse::fake(['data' => [['embedding' => array_fill(0, $dimensions, 0.1)]]]),
            $this->fakeFinalAnswer('Гарантія 24 місяці.'),
            // Друге питання: спершу переформулювання запиту на основі історії (QueryRewriter)
            ChatCreateResponse::fake(['choices' => [['message' => ['role' => 'assistant', 'content' => 'What is the warranty for product X?']]]]),
            $this->fakeFunctionToolCall('call_2', KnowledgeBaseSearchTool::NAME, ['query' => 'What is the warranty for product X?']),
            EmbeddingsCreateResponse::fake(['data' => [['embedding' => array_fill(0, $dimensions, 0.1)]]]),
            $this->fakeFinalAnswer('The warranty is 24 months.'),
        ]);

        $first = $this->postJson('/api/v1/queries', ['question' => 'Яка гарантія на виріб X?']);
        $first->assertOk();
        $first->assertJsonPath('answer_language', 'uk');

        $second = $this->postJson('/api/v1/queries', [
            'question' => 'What is the warranty for product X?',
            'session_id' => $first->json('session_id'),
        ]);

        $second->assertOk();
        $second->assertJsonPath('answer_language', 'en');
        $this->assertSame($first->json('session_id'), $second->json('session_id'));
    }
}
