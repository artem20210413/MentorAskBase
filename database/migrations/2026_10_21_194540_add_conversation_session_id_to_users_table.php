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
            // FR-016: прив'язка діалогового контексту бота (ConversationSession
            // з фічі 001) до chat_id через власника-користувача.
            $table->uuid('conversation_session_id')->nullable()->after('sessions_invalidated_at');
            $table->foreign('conversation_session_id')->references('id')->on('conversation_sessions')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['conversation_session_id']);
            $table->dropColumn('conversation_session_id');
        });
    }
};
