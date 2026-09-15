<?php

namespace Tests\Feature\DocumentUpload;

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

    public function test_non_utf8_txt_fails_processing_with_reason(): void
    {
        Storage::fake('public');
        $this->authenticateApiClient();

        $file = UploadedFile::fake()->createWithContent(
            'notes-cp1251.txt',
            file_get_contents(base_path('tests/Fixtures/documents/notes-cp1251.txt'))
        );

        $response = $this->postJson('/api/v1/documents', ['file' => $file]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('file');
        $this->assertDatabaseCount('documents', 0);
    }
}
