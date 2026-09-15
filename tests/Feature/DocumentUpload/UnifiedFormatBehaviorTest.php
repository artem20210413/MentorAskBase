<?php

namespace Tests\Feature\DocumentUpload;

use App\Models\Document;
use App\Models\DocumentChunk;
use App\Services\Rag\VectorSearchService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Responses\Embeddings\CreateResponse as EmbeddingsCreateResponse;
use Tests\TestCase;

class UnifiedFormatBehaviorTest extends TestCase
{
    public function test_documents_of_all_formats_appear_in_a_single_list_with_same_fields(): void
    {
        $this->authenticateApiClient();

        Document::factory()->create(['original_name' => 'manual.pdf', 'status' => 'processed']);
        Document::factory()->create(['original_name' => 'handbook.docx', 'status' => 'processed']);
        Document::factory()->create(['original_name' => 'notes.txt', 'status' => 'processed']);

        $response = $this->getJson('/api/v1/documents');

        $response->assertOk();
        $response->assertJsonCount(3, 'data');

        foreach ($response->json('data') as $item) {
            $this->assertArrayHasKey('original_name', $item);
            $this->assertArrayHasKey('status', $item);
            $this->assertArrayHasKey('failure_reason', $item);
            $this->assertArrayHasKey('created_at', $item);
        }
    }

    public function test_reuploading_docx_with_identical_content_is_marked_duplicate(): void
    {
        Storage::fake('public');
        $this->authenticateApiClient();

        $content = file_get_contents(base_path('tests/Fixtures/documents/document.docx'));
        $hash = hash('sha256', $content);

        $original = Document::factory()->create([
            'content_hash' => $hash,
            'status' => 'processed',
        ]);

        $file = UploadedFile::fake()->createWithContent('copy.docx', $content);

        $response = $this->postJson('/api/v1/documents', ['file' => $file]);

        $response->assertOk();
        $response->assertJsonPath('status', 'duplicate');
        $response->assertJsonPath('duplicate_of_document_id', $original->id);
    }

    public function test_soft_deleted_docx_chunks_are_excluded_from_vector_search(): void
    {
        $document = Document::factory()->create(['original_name' => 'handbook.docx', 'status' => 'processed']);
        $dimensions = config('rag.openai.embedding_dimensions', 1536);

        DocumentChunk::factory()->for($document)->create([
            'content' => 'Унікальний факт лише у видаленому Word-документі.',
            'embedding' => array_fill(0, $dimensions, 0.1),
        ]);

        $document->delete();

        $results = (new VectorSearchService)->search(array_fill(0, $dimensions, 0.1));

        $this->assertCount(0, $results);
    }
}
