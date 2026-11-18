<?php

namespace Tests\Feature\DocumentUpload;

use App\Models\Document;
use App\Models\DocumentChunk;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Responses\Embeddings\CreateResponse as EmbeddingsCreateResponse;
use Tests\TestCase;

class TextUploadTest extends TestCase
{
    public function test_valid_utf8_txt_is_processed_into_chunks_without_page_number(): void
    {
        Storage::fake('public');
        $this->authenticateApiClient();

        $dimensions = config('rag.openai.embedding_dimensions', 1536);
        OpenAI::fake([
            EmbeddingsCreateResponse::fake([
                'data' => [['embedding' => array_fill(0, $dimensions, 0.1)]],
            ]),
        ]);

        $file = UploadedFile::fake()->createWithContent(
            'notes.txt',
            file_get_contents(base_path('tests/Fixtures/documents/notes.txt'))
        );

        $response = $this->postJson('/api/v1/documents', ['file' => $file]);

        $response->assertCreated();

        $documentId = $response->json('id');
        $this->assertDatabaseHas('documents', ['id' => $documentId, 'status' => 'processed']);

        $chunk = DocumentChunk::where('document_id', $documentId)->firstOrFail();
        $this->assertNull($chunk->page_number);
        $this->assertSame('text_layer', $chunk->source);
        $this->assertStringContainsString('тестовий текстовий файл', $chunk->content);
    }

    /**
     * FR-004b (змінено 2026-11-10): Windows-1251 перекодовується в UTF-8 —
     * і для пошуку, і у збереженому файлі (посилання на джерело відкриває
     * читабельний текст, а не "кракозябри").
     */
    public function test_windows_1251_txt_is_recoded_and_processed(): void
    {
        Storage::fake('public');
        $this->authenticateApiClient();

        $dimensions = config('rag.openai.embedding_dimensions', 1536);
        OpenAI::fake([
            EmbeddingsCreateResponse::fake(['data' => [['embedding' => array_fill(0, $dimensions, 0.1)]]]),
        ]);

        $original = file_get_contents(base_path('tests/Fixtures/documents/notes-cp1251.txt'));
        $file = UploadedFile::fake()->createWithContent('notes-cp1251.txt', $original);

        $response = $this->postJson('/api/v1/documents', ['file' => $file]);

        $response->assertCreated();
        $document = Document::findOrFail($response->json('id'));
        $this->assertSame('processed', $document->status);

        $chunk = DocumentChunk::where('document_id', $document->id)->firstOrFail();
        $this->assertStringContainsString('Це текст у кодуванні Windows-1251', $chunk->content);

        $stored = Storage::disk('public')->get($document->storage_path);
        $this->assertTrue(mb_check_encoding($stored, 'UTF-8'));
        $this->assertStringStartsWith("\xEF\xBB\xBF", $stored, 'UTF-8 BOM — щоб браузер правильно показав файл за посиланням');
        $this->assertStringContainsString('Це текст у кодуванні Windows-1251', $stored);

        // Дедуплікація — за вмістом оригінального файлу
        $this->assertSame(hash('sha256', $original), $document->content_hash);
    }

    public function test_binary_file_with_txt_extension_is_rejected(): void
    {
        Storage::fake('public');
        $this->authenticateApiClient();

        $file = UploadedFile::fake()->createWithContent('notes.txt', "text\x00\x00\x00more\x00binary");

        $response = $this->postJson('/api/v1/documents', ['file' => $file]);

        $response->assertUnprocessable();
        $this->assertDatabaseCount('documents', 0);
    }
}
