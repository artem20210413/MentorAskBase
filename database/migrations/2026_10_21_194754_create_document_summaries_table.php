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
        Schema::create('document_summaries', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('document_id')->constrained('documents');
            $table->string('locale', 2);
            $table->text('summary');
            $table->timestamps();

            // FR-013/FR-014: одне резюме на пару документ+мова
            $table->unique(['document_id', 'locale']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('document_summaries');
    }
};
