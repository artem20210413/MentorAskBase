<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('original_name');
            $table->string('content_hash', 64);
            $table->string('storage_path');
            $table->unsignedBigInteger('size_bytes');
            $table->enum('status', ['pending', 'processing', 'processed', 'failed', 'duplicate'])
                ->default('pending');
            $table->text('failure_reason')->nullable();
            $table->uuid('duplicate_of_document_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        // Self-referencing FK MUST бути додано окремою командою після створення
        // таблиці — в межах одного CREATE TABLE Postgres не бачить власний PK
        // як "referenced table" для одночасно визначеного self-FK.
        Schema::table('documents', function (Blueprint $table) {
            $table->foreign('duplicate_of_document_id')->references('id')->on('documents')->nullOnDelete();
        });

        // FR-002/FR-003: унікальність хешу серед не-видалених "оригінальних"
        // документів (тих, що самі проходять/пройшли обробку). Записи зі
        // статусом 'duplicate' навмисно виключені з унікальності — кожен
        // повторний upload того самого хешу створює власний трекінг-запис,
        // що посилається на оригінал через duplicate_of_document_id (FR-003a).
        DB::statement(
            "CREATE UNIQUE INDEX documents_content_hash_not_deleted_unique ON documents (content_hash) WHERE deleted_at IS NULL AND status <> 'duplicate'"
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS documents_content_hash_not_deleted_unique');
        Schema::dropIfExists('documents');
    }
};
