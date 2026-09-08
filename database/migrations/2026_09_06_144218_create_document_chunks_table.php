<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_chunks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('document_id');
            $table->unsignedInteger('position');
            $table->text('content');
            $table->enum('source', ['text_layer', 'vision_ocr']);
            $table->vector('embedding', config('rag.openai.embedding_dimensions', 1536))->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('document_id')->references('id')->on('documents')->cascadeOnDelete();
            $table->index('document_id');
        });

        // HNSW-індекс для пошуку найближчих сусідів (cosine distance — узгоджено
        // з VectorSearchService). pgvector дозволяє HNSW лише до 2000 вимірів —
        // якщо обрана модель ембедингів більша (наприклад, text-embedding-3-large
        // з повними 3072), індекс пропускається, пошук іде повним перебором.
        if ((int) config('rag.openai.embedding_dimensions', 1536) <= 2000) {
            DB::statement(
                'CREATE INDEX document_chunks_embedding_hnsw ON document_chunks USING hnsw (embedding vector_cosine_ops)'
            );
        }
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS document_chunks_embedding_hnsw');
        Schema::dropIfExists('document_chunks');
    }
};
