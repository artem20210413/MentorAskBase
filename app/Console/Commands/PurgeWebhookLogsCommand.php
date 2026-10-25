<?php

namespace App\Console\Commands;

use App\Models\TelegramWebhookLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class PurgeWebhookLogsCommand extends Command
{
    protected $signature = 'telegram:purge-webhook-logs {days? : Скільки днів зберігати (за замовчуванням '.TelegramWebhookLog::DEFAULT_RETENTION_DAYS.')}';

    protected $description = 'Видаляє діагностичні записи Telegram-webhook, старші за вказану кількість днів';

    public function handle(): int
    {
        $days = (int) ($this->argument('days') ?? TelegramWebhookLog::DEFAULT_RETENTION_DAYS);

        $deleted = TelegramWebhookLog::where('created_at', '<', now()->subDays($days))->delete();

        Log::channel('rag')->info('Видалено застарілих Telegram-webhook логів', ['count' => $deleted, 'days' => $days]);
        $this->info("Видалено записів (старших за {$days} дн.): {$deleted}");

        return self::SUCCESS;
    }
}
