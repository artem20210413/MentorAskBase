<?php

namespace App\Console\Commands;

use App\Services\Telegram\TelegramClient;
use Illuminate\Console\Command;

class TelegramWebhookSetCommand extends Command
{
    protected $signature = 'telegram:webhook-set {url}';

    protected $description = 'Встановити Telegram webhook URL (напр. https://<ngrok-host>/api/telegram/webhook/{secret})';

    public function handle(TelegramClient $client): int
    {
        $url = (string) $this->argument('url');

        $ok = $client->setWebhook($url);

        if ($ok) {
            $this->info("Webhook встановлено: {$url}");

            return self::SUCCESS;
        }

        $this->error('Не вдалося встановити webhook.');

        return self::FAILURE;
    }
}
