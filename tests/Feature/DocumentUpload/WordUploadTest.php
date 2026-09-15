<?php

namespace Tests\Feature\DocumentUpload;

use App\Models\DocumentChunk;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Responses\Chat\CreateResponse;
use OpenAI\Responses\Embeddings\CreateResponse as EmbeddingsCreateResponse;
use Tests\TestCase;

class WordUploadTest extends TestCase
{
    public function test_valid_docx_is_processed_into_chunks_without_page_number(): void
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
            'handbook.docx',
            file_get_contents(base_path('tests/Fixtures/documents/document.docx'))
        );

        $response = $this->postJson('/api/v1/documents', ['file' => $file]);

        $response->assertCreated();

        $documentId = $response->json('id');
        $this->assertDatabaseHas('documents', ['id' => $documentId, 'status' => 'processed']);

        $chunk = DocumentChunk::where('document_id', $documentId)->firstOrFail();
        $this->assertNull($chunk->page_number);
        $this->assertSame('text_layer', $chunk->source);
        $this->assertStringContainsString('тестовий документ Word', $chunk->content);
    }

    public function test_fake_docx_is_rejected_synchronously(): void
    {
        Storage::fake('public');
        $this->authenticateApiClient();

        $file = UploadedFile::fake()->createWithContent(
            'fake.docx',
            file_get_contents(base_path('tests/Fixtures/documents/fake.docx'))
        );

        $response = $this->postJson('/api/v1/documents', ['file' => $file]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('file');
        $this->assertDatabaseCount('documents', 0);
    }

    public function test_docx_with_embedded_image_produces_vision_ocr_chunk(): void
    {
        Storage::fake('public');
        $this->authenticateApiClient();

        $dimensions = config('rag.openai.embedding_dimensions', 1536);
        OpenAI::fake([
            CreateResponse::fake([
                'choices' => [
                    ['message' => ['role' => 'assistant', 'content' => 'Розпізнаний текст зі вбудованого зображення']],
                ],
            ]),
            EmbeddingsCreateResponse::fake([
                'data' => [['embedding' => array_fill(0, $dimensions, 0.1)]],
            ]),
        ]);

        $file = UploadedFile::fake()->createWithContent(
            'with-image.docx',
            file_get_contents(base_path('tests/Fixtures/documents/document-with-image.docx'))
        );

        $response = $this->postJson('/api/v1/documents', ['file' => $file]);

        $response->assertCreated();

        $documentId = $response->json('id');
        $this->assertDatabaseHas('documents', ['id' => $documentId, 'status' => 'processed']);
        $visionChunk = DocumentChunk::where('document_id', $documentId)->where('source', 'vision_ocr')->first();

        $this->assertNotNull($visionChunk);
        $this->assertNull($visionChunk->page_number);
        $this->assertStringContainsString('Розпізнаний текст', $visionChunk->content);
    }
}
