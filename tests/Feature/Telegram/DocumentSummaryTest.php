<?php

namespace Tests\Feature\Telegram;

use App\Models\Document;
use App\Models\DocumentChunk;
use App\Models\DocumentSummary;
use App\Models\User;
use App\Services\Telegram\TelegramClient;
use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Resources\Chat;
use OpenAI\Responses\Chat\CreateResponse;
use Tests\Support\PostsTelegramUpdates;
use Tests\TestCase;

class DocumentSummaryTest extends TestCase
{
    use PostsTelegramUpdates;

    public function test_requesting_summary_generates_and_caches_it(): void
    {
        $user = User::factory()->create(['telegram_chat_id' => 920, 'language' => 'uk']);
        $document = Document::factory()->create();
        DocumentChunk::factory()->create(['document_id' => $document->id, 'content' => 'Текст документа.', 'position' => 0]);

        OpenAI::fake([
            CreateResponse::fake(['choices' => [['message' => ['role' => 'assistant', 'content' => 'Коротке резюме.']]]]),
        ]);

        $this->mock(TelegramClient::class, function ($mock) {
            $mock->shouldReceive('sendMessage')
                ->once()
                ->withArgs(fn (int $chatId, string $text) => $chatId === 920 && str_contains($text, 'Коротке резюме'));
        });

        $this->postTelegramUpdate($this->telegramMessage(920, 920, '1'))->assertOk();

        $this->assertDatabaseHas('document_summaries', [
            'document_id' => $document->id,
            'locale' => 'uk',
            'summary' => 'Коротке резюме.',
        ]);
    }

    public function test_second_request_uses_cache_without_calling_ai(): void
    {
        $user = User::factory()->create(['telegram_chat_id' => 921, 'language' => 'uk']);
        $document = Document::factory()->create();
        DocumentSummary::create([
            'document_id' => $document->id,
            'locale' => 'uk',
            'summary' => 'Вже закешоване резюме.',
        ]);

        OpenAI::fake();

        $this->mock(TelegramClient::class, function ($mock) {
            $mock->shouldReceive('sendMessage')
                ->once()
                ->withArgs(fn (int $chatId, string $text) => str_contains($text, 'Вже закешоване резюме'));
        });

        $this->postTelegramUpdate($this->telegramMessage(921, 921, '1'))->assertOk();

        OpenAI::assertNotSent(Chat::class);
    }

    public function test_summary_denied_for_soft_deleted_document(): void
    {
        $user = User::factory()->create(['telegram_chat_id' => 922]);
        $document = Document::factory()->create();
        $document->delete();

        $this->mock(TelegramClient::class, function ($mock) {
            $mock->shouldReceive('sendMessage')
                ->once()
                ->withArgs(fn (int $chatId, string $text) => str_contains($text, 'не знайдено'));
        });

        $this->postTelegramUpdate($this->telegramMessage(922, 922, '1'))->assertOk();
    }
}
