<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('query_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('conversation_session_id');
            $table->text('question');
            $table->text('answer');
            $table->string('detected_language', 10)->nullable();
            $table->string('answered_in_language', 10)->nullable();
            $table->json('source_document_ids')->default('[]');
            $table->unsignedInteger('llm_input_tokens')->nullable();
            $table->unsignedInteger('llm_output_tokens')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('conversation_session_id')->references('id')->on('conversation_sessions')->cascadeOnDelete();
            $table->index('conversation_session_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('query_logs');
    }
};
