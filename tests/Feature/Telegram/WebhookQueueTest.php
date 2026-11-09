<?php

namespace Tests\Feature\Telegram;

use App\Jobs\ProcessTelegramUpdateJob;
use Illuminate\Support\Facades\Queue;
use Tests\Support\PostsTelegramUpdates;
use Tests\TestCase;

/**
 * Webhook лише приймає й дедуплікує update, а обробка (зокрема довгі
 * виклики OpenAI) іде в черзі — інакше Telegram чекає на відповідь і
 * затримує доставку наступних оновлень усім користувачам бота.
 */
class WebhookQueueTest extends TestCase
{
    use PostsTelegramUpdates;

    public function test_update_is_dispatched_to_telegram_queue(): void
    {
        Queue::fake();

        $update = $this->telegramMessage(800, 800, 'Привіт');

        $this->postTelegramUpdate($update)->assertOk();

        Queue::assertPushedOn('telegram', ProcessTelegramUpdateJob::class, function (ProcessTelegramUpdateJob $job) use ($update) {
            return $job->payload['update_id'] === $update['update_id'];
        });
    }

    public function test_redelivered_update_is_dispatched_only_once(): void
    {
        Queue::fake();

        $update = $this->telegramMessage(801, 801, 'Привіт');

        $this->postTelegramUpdate($update)->assertOk();
        $this->postTelegramUpdate($update)->assertOk();

        Queue::assertPushed(ProcessTelegramUpdateJob::class, 1);
    }

    public function test_invalid_secret_does_not_dispatch_anything(): void
    {
        Queue::fake();

        $this->postJson('/api/telegram/webhook/wrong-secret', $this->telegramMessage(802, 802, 'x'))->assertNotFound();

        Queue::assertNothingPushed();
    }
}
