<?php

namespace Tests\Feature\Telegram;

use App\Models\ConversationSession;
use App\Models\QueryLog;
use App\Models\User;
use App\Services\Rag\QuestionAnsweringService;
use App\Services\Telegram\TelegramClient;
use Telegram\Bot\Objects\Message;
use Tests\Support\PostsTelegramUpdates;
use Tests\TestCase;

/**
 * Захист бюджету OpenAI: кожне питання у вільному чаті — платний агентний
 * цикл, тож кількість і довжина питань від одного користувача обмежені.
 */
class FreeChatLimitsTest extends TestCase
{
    use PostsTelegramUpdates;

    public function test_questions_over_per_minute_limit_are_not_sent_to_llm(): void
    {
        config(['services.telegram.free_chat_per_minute' => 2, 'services.telegram.free_chat_per_day' => 100]);

        $user = User::factory()->create(['telegram_chat_id' => 960, 'language' => 'uk']);
        $this->expectAsk(times: 2);

        $sent = $this->captureSentMessages();

        foreach (['Питання 1', 'Питання 2', 'Питання 3'] as $question) {
            $this->postTelegramUpdate($this->telegramMessage(960, 960, $question))->assertOk();
        }

        $this->assertSame(__('telegram.free_chat_rate_limited_minute', [], 'uk'), end($sent->messages));
    }

    public function test_questions_over_daily_limit_are_not_sent_to_llm(): void
    {
        config(['services.telegram.free_chat_per_minute' => 100, 'services.telegram.free_chat_per_day' => 2]);

        User::factory()->create(['telegram_chat_id' => 961, 'language' => 'en']);
        $this->expectAsk(times: 2);

        $sent = $this->captureSentMessages();

        foreach (['Q1', 'Q2', 'Q3'] as $question) {
            $this->postTelegramUpdate($this->telegramMessage(961, 961, $question))->assertOk();
        }

        $this->assertSame(__('telegram.free_chat_rate_limited_day', [], 'en'), end($sent->messages));
    }

    public function test_limits_are_counted_per_user(): void
    {
        config(['services.telegram.free_chat_per_minute' => 1, 'services.telegram.free_chat_per_day' => 100]);

        User::factory()->create(['telegram_chat_id' => 962]);
        User::factory()->create(['telegram_chat_id' => 963]);
        $this->expectAsk(times: 2);
        $this->captureSentMessages();

        $this->postTelegramUpdate($this->telegramMessage(962, 962, 'Питання'))->assertOk();
        $this->postTelegramUpdate($this->telegramMessage(963, 963, 'Питання'))->assertOk();
    }

    public function test_too_long_question_is_rejected_and_not_counted(): void
    {
        config(['services.telegram.free_chat_per_minute' => 1, 'services.telegram.question_max_length' => 2000]);

        User::factory()->create(['telegram_chat_id' => 964, 'language' => 'uk']);
        $this->expectAsk(times: 1);

        $sent = $this->captureSentMessages();

        $this->postTelegramUpdate($this->telegramMessage(964, 964, str_repeat('а', 2001)))->assertOk();
        $this->assertSame(__('telegram.question_too_long', ['max' => 2000], 'uk'), end($sent->messages));

        // Задовге питання не з'їло хвилинний ліміт (1) — наступне проходить
        $this->postTelegramUpdate($this->telegramMessage(964, 964, 'Нормальне питання'))->assertOk();
    }

    private function expectAsk(int $times): void
    {
        $session = ConversationSession::factory()->create();

        $this->mock(QuestionAnsweringService::class, function ($mock) use ($times, $session) {
            $mock->shouldReceive('ask')->times($times)->andReturn(QueryLog::factory()->make([
                'conversation_session_id' => $session->id,
                'answer' => 'Відповідь',
            ]));
        });
    }

    private function captureSentMessages(): object
    {
        $sent = new class
        {
            public array $messages = [];
        };

        $this->mock(TelegramClient::class, function ($mock) use ($sent) {
            $mock->shouldReceive('sendMessage')->andReturnUsing(function ($chatId, string $text) use ($sent) {
                $sent->messages[] = $text;

                return new Message([]);
            });
        });

        return $sent;
    }
}
