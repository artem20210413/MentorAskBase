<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Робить update_id унікальним — це перетворює журнал на атомарний бар'єр
     * дедуплікації: повторна доставка того самого update_id від Telegram
     * падає на unique-порушенні й не обробляється вдруге.
     */
    public function up(): void
    {
        // Прибираємо вже накопичені дублікати (залишаємо найраніший запис),
        // інакше створення unique-індексу впаде. NULL-update_id не чіпаємо —
        // такі записи не дедуплікуються.
        DB::statement(<<<'SQL'
            DELETE FROM telegram_webhook_logs a
            USING telegram_webhook_logs b
            WHERE a.id > b.id
              AND a.update_id = b.update_id
              AND a.update_id IS NOT NULL
        SQL);

        Schema::table('telegram_webhook_logs', function (Blueprint $table) {
            $table->unique('update_id');
        });
    }

    public function down(): void
    {
        Schema::table('telegram_webhook_logs', function (Blueprint $table) {
            $table->dropUnique(['update_id']);
        });
    }
};
