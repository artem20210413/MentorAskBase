<?php

namespace Tests\Feature;

use App\Livewire\PublicChat;
use App\Models\Document;
use App\Models\DocumentChunk;
use App\Models\QueryLog;
use App\Services\Rag\KnowledgeBaseSearchTool;
use Livewire\Livewire;
use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Responses\Embeddings\CreateResponse as EmbeddingsCreateResponse;
use Tests\Support\FakesAgentResponses;
use Tests\TestCase;

class PublicChatTest extends TestCase
{
    use FakesAgentResponses;

    public function test_chat_page_is_accessible_without_authentication(): void
    {
        $this->get('/chat')->assertOk();
    }

    public function test_visitor_can_ask_a_question_and_receive_answer_with_sources(): void
    {
        $dimensions = config('rag.openai.embedding_dimensions', 1536);
        $document = Document::factory()->create(['status' => 'processed', 'original_name' => 'manual.pdf']);
        DocumentChunk::factory()->for($document)->create([
            'embedding' => array_fill(0, $dimensions, 0.1),
        ]);

        OpenAI::fake([
            $this->fakeFunctionToolCall('call_1', KnowledgeBaseSearchTool::NAME, ['query' => 'Питання про документ?']),
            EmbeddingsCreateResponse::fake(['data' => [['embedding' => array_fill(0, $dimensions, 0.1)]]]),
            $this->fakeFinalAnswer('Ось відповідь.'),
        ]);

        $component = Livewire::test(PublicChat::class)
            ->set('question', 'Питання про документ?')
            ->call('ask')
            ->assertSet('question', '')
            ->assertSee('Ось відповідь.')
            ->assertSee('manual.pdf');

        // Джерело має бути клікабельним посиланням, а не просто текстом
        $component->assertSeeHtml('href="');
        $this->assertSame('manual.pdf', $component->get('messages')[1]['sources'][0]['name']);
        $this->assertNotEmpty($component->get('messages')[1]['sources'][0]['url']);

        $this->assertDatabaseCount('query_logs', 1);
        $this->assertSame('Ось відповідь.', QueryLog::first()->answer);
    }
}
