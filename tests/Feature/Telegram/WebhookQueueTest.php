<?php

namespace Tests\Feature\Telegram;

use App\Jobs\ProcessTelegramUpdateJob;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Tests\Support\PostsTelegramUpdates;
use Tests\TestCase;

/**
 * Webhook лише приймає й дедуплікує update, а обробка (зокрема довгі
 * виклики OpenAI) іде поза HTTP-запитом Telegram — у черзі або після
 * відправлення відповіді (services.telegram.updates_via_queue).
 */
class WebhookQueueTest extends TestCase
{
    use PostsTelegramUpdates;

    public function test_update_is_dispatched_to_telegram_queue_in_queue_mode(): void
    {
        config(['services.telegram.updates_via_queue' => true]);
        Queue::fake();

        $update = $this->telegramMessage(800, 800, 'Привіт');

        $this->postTelegramUpdate($update)->assertOk();

        Queue::assertPushedOn('telegram', ProcessTelegramUpdateJob::class, function (ProcessTelegramUpdateJob $job) use ($update) {
            return $job->payload['update_id'] === $update['update_id'];
        });
    }

    public function test_update_is_processed_after_response_without_queue(): void
    {
        config(['services.telegram.updates_via_queue' => false]);
        Bus::fake();

        $update = $this->telegramMessage(803, 803, 'Привіт');

        $this->postTelegramUpdate($update)->assertOk();

        Bus::assertDispatchedAfterResponse(ProcessTelegramUpdateJob::class, function (ProcessTelegramUpdateJob $job) use ($update) {
            return $job->payload['update_id'] === $update['update_id'];
        });
    }

    public function test_redelivered_update_is_dispatched_only_once(): void
    {
        config(['services.telegram.updates_via_queue' => true]);
        Queue::fake();

        $update = $this->telegramMessage(801, 801, 'Привіт');

        $this->postTelegramUpdate($update)->assertOk();
        $this->postTelegramUpdate($update)->assertOk();

        Queue::assertPushed(ProcessTelegramUpdateJob::class, 1);
    }

    public function test_redelivered_update_is_processed_only_once_without_queue(): void
    {
        config(['services.telegram.updates_via_queue' => false]);
        Bus::fake();

        $update = $this->telegramMessage(804, 804, 'Привіт');

        $this->postTelegramUpdate($update)->assertOk();
        $this->postTelegramUpdate($update)->assertOk();

        Bus::assertDispatchedAfterResponseTimes(ProcessTelegramUpdateJob::class, 1);
    }

    public function test_invalid_secret_does_not_dispatch_anything(): void
    {
        Bus::fake();

        $this->postJson('/api/telegram/webhook/wrong-secret', $this->telegramMessage(802, 802, 'x'))->assertNotFound();

        Bus::assertNothingDispatched();
        Bus::assertNotDispatchedAfterResponse(ProcessTelegramUpdateJob::class);
    }
}
