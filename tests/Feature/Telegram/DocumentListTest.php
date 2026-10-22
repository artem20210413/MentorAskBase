<?php

namespace Tests\Feature\Telegram;

use App\Models\Document;
use App\Models\User;
use App\Services\Telegram\TelegramClient;
use Tests\Support\PostsTelegramUpdates;
use Tests\TestCase;

class DocumentListTest extends TestCase
{
    use PostsTelegramUpdates;

    public function test_document_list_shows_links_and_excludes_deleted(): void
    {
        $user = User::factory()->create(['telegram_chat_id' => 910]);
        $visible = Document::factory()->create(['original_name' => 'visible.pdf']);
        $deleted = Document::factory()->create(['original_name' => 'deleted.pdf']);
        $deleted->delete();

        $this->mock(TelegramClient::class, function ($mock) {
            $mock->shouldReceive('sendMessage')
                ->once()
                ->withArgs(fn (int $chatId, string $text) => $chatId === 910
                    && str_contains($text, 'visible.pdf')
                    && ! str_contains($text, 'deleted.pdf'));
        });

        $this->postTelegramUpdate($this->telegramMessage(910, 910, '📄 Список документів'))->assertOk();
    }

    public function test_document_list_paginates_when_more_than_page_size(): void
    {
        config(['services.telegram.docs_page_size' => 2]);

        $user = User::factory()->create(['telegram_chat_id' => 911]);
        Document::factory()->count(3)->create();

        $this->mock(TelegramClient::class, function ($mock) {
            $mock->shouldReceive('sendMessage')
                ->once()
                ->withArgs(fn (int $chatId, string $text, ?array $markup) => $markup['inline_keyboard'][0][0]['callback_data'] === 'docs_page:2');
        });

        $this->postTelegramUpdate($this->telegramMessage(911, 911, '📄 Список документів'))->assertOk();
    }
}
