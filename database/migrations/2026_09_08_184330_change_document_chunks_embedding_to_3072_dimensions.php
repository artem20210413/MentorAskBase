<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Перехід на text-embedding-3-large (3072 виміри замість 1536 у
     * text-embedding-3-small). Таблиця на момент міграції порожня — якщо
     * колись знадобиться повторити це на БД із даними, старі вектори
     * потрібно спершу перерахувати новою моделлю (вектори різних моделей
     * несумісні одне з одним навіть за однакової розмірності).
     *
     * ВАЖЛИВО: HNSW-індекс pgvector підтримує максимум 2000 вимірів —
     * 3072 перевищує цей ліміт, тому індекс навмисно НЕ створюється.
     * Пошук (`VectorSearchService`) виконує повний перебір (brute-force)
     * по колонці `embedding` — прийнятно для масштабу SC-008 (~1000
     * документів), але не масштабується так само добре, як HNSW.
     */
    public function up(): void
    {
        DB::statement('DROP INDEX IF EXISTS document_chunks_embedding_hnsw');
        DB::statement('TRUNCATE TABLE document_chunks');
        DB::statement('ALTER TABLE document_chunks ALTER COLUMN embedding TYPE vector(3072)');
    }

    public function down(): void
    {
        DB::statement('TRUNCATE TABLE document_chunks');
        DB::statement('ALTER TABLE document_chunks ALTER COLUMN embedding TYPE vector(1536)');
        DB::statement('CREATE INDEX document_chunks_embedding_hnsw ON document_chunks USING hnsw (embedding vector_cosine_ops)');
    }
};
