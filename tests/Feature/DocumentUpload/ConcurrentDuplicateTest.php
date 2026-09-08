<?php

namespace Tests\Feature\DocumentUpload;

use App\Jobs\ProcessDocumentJob;
use App\Models\Document;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Tests\Support\GeneratesTestPdf;
use Tests\TestCase;

class ConcurrentDuplicateTest extends TestCase
{
    use GeneratesTestPdf;

    public function test_second_upload_of_file_still_processing_does_not_start_parallel_processing(): void
    {
        Storage::fake('public');
        Bus::fake();
        $this->authenticateApiClient();

        $content = $this->validPdfContent();
        $hash = hash('sha256', $content);

        // FR-003a: оригінал ще обробляється (не 'processed' і не 'duplicate')
        $original = Document::factory()->processing()->create(['content_hash' => $hash]);

        $file = UploadedFile::fake()->createWithContent('manual-copy.pdf', $content);

        $response = $this->postJson('/api/v1/documents', ['file' => $file]);

        $response->assertOk();
        $response->assertJsonPath('status', 'duplicate');
        $response->assertJsonPath('duplicate_of_document_id', $original->id);

        Bus::assertNotDispatched(ProcessDocumentJob::class);
    }
}
