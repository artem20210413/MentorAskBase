<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Telegram\Bot\Objects\Update;

/**
 * Діагностичний запис вхідного Telegram-webhook — незмінний, лише created_at.
 * За замовчуванням зберігається тиждень, далі видаляється командою
 * telegram:purge-webhook-logs.
 */
#[Fillable(['update_id', 'object_type', 'chat_id', 'payload'])]
class TelegramWebhookLog extends Model
{
    public const UPDATED_AT = null;

    /** Скільки днів зберігати логи, якщо команді очищення не передано інше. */
    public const DEFAULT_RETENTION_DAYS = 7;

    protected $casts = [
        'payload' => 'array',
        'created_at' => 'datetime',
    ];

    /**
     * Зберігає сирий payload вхідного webhook, витягуючи корисні для пошуку
     * поля (update_id, тип об'єкта, chat_id) незалежно від типу апдейту.
     */
    public static function record(Update $update, array $payload): self
    {
        $chatId = data_get($payload, 'message.chat.id')
            ?? data_get($payload, 'callback_query.message.chat.id')
            ?? data_get($payload, 'edited_message.chat.id');

        return self::create([
            'update_id' => $payload['update_id'] ?? null,
            'object_type' => $update->objectType(),
            'chat_id' => $chatId,
            'payload' => $payload,
        ]);
    }
}
