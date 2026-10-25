<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Діагностичний журнал вхідних Telegram-webhook: зберігаємо сирий payload,
        // щоб розбирати баги. Записи зберігаються тиждень (див. команду очищення).
        Schema::create('telegram_webhook_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('update_id')->nullable();
            $table->string('object_type')->nullable();
            $table->unsignedBigInteger('chat_id')->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('created_at')->useCurrent();

            // Очищення й повсякденні вибірки йдуть за часом надходження.
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('telegram_webhook_logs');
    }
};
