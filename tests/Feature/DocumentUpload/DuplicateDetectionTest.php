<?php

namespace Tests\Feature\DocumentUpload;

use App\Jobs\ProcessDocumentJob;
use App\Models\Document;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Tests\Support\GeneratesTestPdf;
use Tests\TestCase;

class DuplicateDetectionTest extends TestCase
{
    use GeneratesTestPdf;

    public function test_reuploading_processed_document_is_detected_as_duplicate(): void
    {
        Storage::fake('public');
        Bus::fake();
        $this->authenticateApiClient();

        $content = $this->validPdfContent();
        $hash = hash('sha256', $content);

        $original = Document::factory()->create([
            'content_hash' => $hash,
            'status' => 'processed',
        ]);

        $file = UploadedFile::fake()->createWithContent('manual-copy.pdf', $content);

        $response = $this->postJson('/api/v1/documents', ['file' => $file]);

        $response->assertOk();
        $response->assertJsonPath('status', 'duplicate');
        $response->assertJsonPath('duplicate_of_document_id', $original->id);

        Bus::assertNotDispatched(ProcessDocumentJob::class);
    }
}
