<?php

namespace Tests\Feature;

use App\Livewire\PublicChat;
use App\Models\Document;
use App\Models\DocumentChunk;
use App\Models\QueryLog;
use Livewire\Livewire;
use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Responses\Chat\CreateResponse;
use OpenAI\Responses\Embeddings\CreateResponse as EmbeddingsCreateResponse;
use Tests\TestCase;

class PublicChatTest extends TestCase
{
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
            EmbeddingsCreateResponse::fake(['data' => [['embedding' => array_fill(0, $dimensions, 0.1)]]]),
            CreateResponse::fake(['choices' => [['message' => ['role' => 'assistant', 'content' => 'Ось відповідь.']]]]),
        ]);

        Livewire::test(PublicChat::class)
            ->set('question', 'Питання про документ?')
            ->call('ask')
            ->assertSet('question', '')
            ->assertSee('Ось відповідь.')
            ->assertSee('manual.pdf');

        $this->assertDatabaseCount('query_logs', 1);
        $this->assertSame('Ось відповідь.', QueryLog::first()->answer);
    }
}
