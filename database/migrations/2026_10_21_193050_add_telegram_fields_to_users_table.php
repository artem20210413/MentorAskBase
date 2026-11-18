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
        Schema::table('users', function (Blueprint $table) {
            // FR-017a: номер телефону нормалізований до E.164, глобально унікальний (FR-003a)
            $table->string('phone')->nullable()->unique()->after('email');
            // FR-003/FR-009: статус доступу; "approved" = активний робочий стан
            $table->string('status')->default('pending')->after('phone');
            // FR-003: Telegram chat_id користувача
            $table->bigInteger('telegram_chat_id')->nullable()->unique()->after('status');
            // FR-004: ім'я користувача в Telegram для картки заявки
            $table->string('telegram_username')->nullable()->after('telegram_chat_id');
            // FR-011: обрана мова спілкування з ботом
            $table->string('language', 2)->default('uk')->after('telegram_username');
            // FR-006b: шкала очікування повторної заявки
            $table->unsignedInteger('rejection_count')->default(0)->after('language');
            $table->timestamp('last_rejected_at')->nullable()->after('rejection_count');
            // FR-023a: мідлвар порівнює з часом логіну в сесії
            $table->timestamp('sessions_invalidated_at')->nullable()->after('last_rejected_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'phone',
                'status',
                'telegram_chat_id',
                'telegram_username',
                'language',
                'rejection_count',
                'last_rejected_at',
                'sessions_invalidated_at',
            ]);
        });
    }
};
