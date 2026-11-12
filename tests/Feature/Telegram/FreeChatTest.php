<?php

namespace Tests\Feature\Telegram;

use App\Models\ConversationSession;
use App\Models\QueryLog;
use App\Models\User;
use App\Services\Rag\QuestionAnsweringService;
use App\Services\Rag\RagAnswerGenerationException;
use App\Services\Telegram\TelegramClient;
use Tests\Support\FakeTelegramClient;
use Tests\Support\PostsTelegramUpdates;
use Tests\TestCase;

class FreeChatTest extends TestCase
{
    use PostsTelegramUpdates;

    public function test_unrecognized_text_is_forwarded_to_rag_pipeline(): void
    {
        $user = User::factory()->create(['telegram_chat_id' => 930, 'conversation_session_id' => null]);
        $session = ConversationSession::factory()->create();

        $this->mock(QuestionAnsweringService::class, function ($mock) use ($session) {
            $mock->shouldReceive('ask')
                ->once()
                ->withArgs(fn (string $question, ?string $sessionId) => $question === 'Яка гарантія?' && $sessionId === null)
                ->andReturn(QueryLog::factory()->make([
                    'conversation_session_id' => $session->id,
                    'answer' => 'Гарантія 2 роки.',
                ]));
        });

        $telegram = new FakeTelegramClient;
        $this->app->instance(TelegramClient::class, $telegram);

        $this->postTelegramUpdate($this->telegramMessage(930, 930, 'Яка гарантія?'))->assertOk();

        // Відповідь замінює заглушку "думаю…" в тому ж чаті
        $this->assertSame(930, last($telegram->calls)['chat_id']);
        $this->assertSame('Гарантія 2 роки.', last($telegram->texts()));

        $this->assertDatabaseHas('users', ['id' => $user->id, 'conversation_session_id' => $session->id]);
    }

    public function test_rag_failure_returns_friendly_error_message(): void
    {
        User::factory()->create(['telegram_chat_id' => 931]);

        $this->mock(QuestionAnsweringService::class, function ($mock) {
            $mock->shouldReceive('ask')->once()->andThrow(new RagAnswerGenerationException('LLM down'));
        });

        $telegram = new FakeTelegramClient;
        $this->app->instance(TelegramClient::class, $telegram);

        $this->postTelegramUpdate($this->telegramMessage(931, 931, 'Привіт, як справи?'))->assertOk();

        $this->assertSame(931, last($telegram->calls)['chat_id']);
        $this->assertStringContainsString('помилка', last($telegram->texts()));
    }
}
