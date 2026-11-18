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
        Schema::create('user_approval_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users');
            $table->string('status')->default('pending');
            $table->foreignId('decided_by_user_id')->nullable()->constrained('users');
            $table->timestamp('decided_at')->nullable();
            // FR-004a: чат, куди надіслано картку
            $table->bigInteger('moderation_chat_id')->nullable();
            // FR-008: оновлюється при кожному нагадуванні
            $table->bigInteger('moderation_message_id')->nullable();
            $table->timestamp('last_reminder_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_approval_requests');
    }
};
