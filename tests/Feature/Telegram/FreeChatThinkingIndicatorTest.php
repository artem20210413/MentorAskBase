<?php

namespace Tests\Feature\Telegram;

use App\Models\ConversationSession;
use App\Models\QueryLog;
use App\Models\User;
use App\Services\Rag\AgentToolRunner;
use App\Services\Rag\QuestionAnsweringService;
use App\Services\Rag\RagAnswerGenerationException;
use App\Services\Telegram\FreeChatService;
use App\Services\Telegram\TelegramClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Support\FakeTelegramClient;
use Tests\Support\PostsTelegramUpdates;
use Tests\TestCase;

/**
 * Індикатор "модель думає" + одне питання за раз на користувача
 * (docs/backlog.md).
 */
class FreeChatThinkingIndicatorTest extends TestCase
{
    use PostsTelegramUpdates;

    private FakeTelegramClient $telegram;

    protected function setUp(): void
    {
        parent::setUp();

        $this->telegram = new FakeTelegramClient;
        $this->app->instance(TelegramClient::class, $this->telegram);
    }

    public function test_placeholder_is_sent_first_and_then_replaced_with_answer(): void
    {
        User::factory()->create(['telegram_chat_id' => 970, 'language' => 'uk']);
        $this->answerWith('Гарантія 2 роки.');

        $this->postTelegramUpdate($this->telegramMessage(970, 970, 'Яка гарантія?'))->assertOk();

        $this->assertCount(1, $this->telegram->callsOf('sendChatAction'));

        $sent = $this->telegram->callsOf('sendMessage');
        $this->assertCount(1, $sent);
        $this->assertContains($sent[0]['text'], $this->phrases('thinking', 'uk'));

        $edits = $this->telegram->callsOf('editMessageText');
        $this->assertSame($sent[0]['message_id'], end($edits)['message_id']);
        $this->assertSame('Гарантія 2 роки.', end($edits)['text']);
    }

    public function test_placeholder_changes_on_each_agent_stage(): void
    {
        User::factory()->create(['telegram_chat_id' => 971, 'language' => 'en']);
        $this->answerWith('Answer.', stages: [
            AgentToolRunner::STAGE_SEARCHING_DOCUMENTS,
            AgentToolRunner::STAGE_SEARCHING_DOCUMENTS, // повтор того ж етапу — без зайвого редагування
            AgentToolRunner::STAGE_COMPOSING,
        ]);

        $this->postTelegramUpdate($this->telegramMessage(971, 971, 'Question?'))->assertOk();

        $edits = array_column($this->telegram->callsOf('editMessageText'), 'text');

        $this->assertCount(3, $edits);
        $this->assertContains($edits[0], $this->phrases('searching_documents', 'en'));
        $this->assertContains($edits[1], $this->phrases('composing', 'en'));
        $this->assertSame('Answer.', $edits[2]);
    }

    public function test_long_answer_replaces_placeholder_with_separate_messages(): void
    {
        User::factory()->create(['telegram_chat_id' => 972, 'language' => 'uk']);
        $longAnswer = str_repeat('Довга відповідь. ', 300).'Кінець.';
        $this->answerWith($longAnswer);

        $this->postTelegramUpdate($this->telegramMessage(972, 972, 'Розкажи все'))->assertOk();

        $placeholderId = $this->telegram->callsOf('sendMessage')[0]['message_id'];
        $this->assertSame($placeholderId, $this->telegram->callsOf('deleteMessage')[0]['message_id']);
        $this->assertSame($longAnswer, end($this->telegram->calls)['text']);
    }

    public function test_answer_is_still_delivered_when_placeholder_edit_fails(): void
    {
        User::factory()->create(['telegram_chat_id' => 973, 'language' => 'uk']);
        $this->telegram->failing = ['editMessageText'];
        $this->answerWith('Відповідь.', stages: [AgentToolRunner::STAGE_COMPOSING]);

        $this->postTelegramUpdate($this->telegramMessage(973, 973, 'Питання'))->assertOk();

        $this->assertSame('Відповідь.', end($this->telegram->calls)['text']);
        $this->assertSame('sendMessage', end($this->telegram->calls)['method']);
    }

    public function test_error_replaces_placeholder_with_error_message_and_releases_lock(): void
    {
        $user = User::factory()->create(['telegram_chat_id' => 974, 'language' => 'uk']);

        $this->mock(QuestionAnsweringService::class, function ($mock) {
            $mock->shouldReceive('ask')->once()->andThrow(new RagAnswerGenerationException('LLM down'));
        });

        $this->postTelegramUpdate($this->telegramMessage(974, 974, 'Питання'))->assertOk();

        $this->assertSame(__('telegram.free_chat_error', [], 'uk'), end($this->telegram->calls)['text']);
        $this->assertLockIsFree($user);
    }

    public function test_question_while_previous_is_in_progress_gets_busy_message(): void
    {
        $user = User::factory()->create(['telegram_chat_id' => 975, 'language' => 'uk']);
        $this->mock(QuestionAnsweringService::class, fn ($mock) => $mock->shouldNotReceive('ask'));

        $lock = Cache::lock(FreeChatService::lockKey($user), 60);
        $this->assertTrue($lock->get());

        $this->postTelegramUpdate($this->telegramMessage(975, 975, 'Ще одне питання'))->assertOk();

        $this->assertSame([__('telegram.free_chat_busy', [], 'uk')], $this->telegram->texts());
        // Відхилене через зайнятість питання не з'їдає ліміти
        $this->assertSame(0, RateLimiter::attempts('telegram-free-chat-minute:'.$user->id));

        $lock->release();
    }

    public function test_lock_is_released_after_answer(): void
    {
        $user = User::factory()->create(['telegram_chat_id' => 976, 'language' => 'uk']);
        $this->answerWith('Відповідь.');

        $this->postTelegramUpdate($this->telegramMessage(976, 976, 'Питання'))->assertOk();

        $this->assertLockIsFree($user);
    }

    public function test_lock_is_released_when_limits_reject_question(): void
    {
        config(['services.telegram.free_chat_per_minute' => 1]);
        $user = User::factory()->create(['telegram_chat_id' => 977, 'language' => 'uk']);
        RateLimiter::hit('telegram-free-chat-minute:'.$user->id, 60);
        $this->mock(QuestionAnsweringService::class, fn ($mock) => $mock->shouldNotReceive('ask'));

        $this->postTelegramUpdate($this->telegramMessage(977, 977, 'Питання'))->assertOk();

        $this->assertSame([__('telegram.free_chat_rate_limited_minute', [], 'uk')], $this->telegram->texts());
        $this->assertLockIsFree($user);
    }

    /**
     * @param  array<int, string>  $stages  етапи, про які "агент" повідомить під час відповіді
     */
    private function answerWith(string $answer, array $stages = []): void
    {
        $session = ConversationSession::factory()->create();

        $this->mock(QuestionAnsweringService::class, function ($mock) use ($answer, $stages, $session) {
            $mock->shouldReceive('ask')->once()->andReturnUsing(
                function (string $question, ?string $sessionId, ?callable $onProgress = null) use ($answer, $stages, $session) {
                    foreach ($stages as $stage) {
                        $onProgress($stage);
                    }

                    return QueryLog::factory()->make([
                        'conversation_session_id' => $session->id,
                        'answer' => $answer,
                    ]);
                }
            );
        });
    }

    /** @return array<int, string> */
    private function phrases(string $stage, string $locale): array
    {
        return __("telegram.thinking_phrases.{$stage}", [], $locale);
    }

    private function assertLockIsFree(User $user): void
    {
        $lock = Cache::lock(FreeChatService::lockKey($user), 1);
        $this->assertTrue($lock->get(), 'Lock користувача не звільнено');
        $lock->release();
    }
}
