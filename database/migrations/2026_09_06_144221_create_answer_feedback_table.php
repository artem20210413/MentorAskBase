<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('answer_feedback', function (Blueprint $table) {
            $table->id();
            $table->uuid('query_log_id')->unique();
            $table->enum('rating', ['useful', 'not_useful']);
            $table->text('comment')->nullable();
            $table->timestamps();

            $table->foreign('query_log_id')->references('id')->on('query_logs')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('answer_feedback');
    }
};
