<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_tool_steps', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('query_log_id');
            // Порядок кроку в межах обробки одного питання (0-based) — FR-013.
            $table->unsignedInteger('step_number');
            $table->enum('tool', ['search_knowledge_base', 'web_search']);
            $table->text('input');
            // null, якщо виклик інструмента завершився збоєм (FR-008) —
            // крок усе одно записується, щоб було видно спробу.
            $table->text('output')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('query_log_id')->references('id')->on('query_logs')->cascadeOnDelete();
            $table->index('query_log_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_tool_steps');
    }
};
