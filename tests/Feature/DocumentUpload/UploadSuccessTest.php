<?php

namespace Tests\Feature\DocumentUpload;

use App\Jobs\ProcessDocumentJob;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Tests\Support\GeneratesTestPdf;
use Tests\TestCase;

class UploadSuccessTest extends TestCase
{
    use GeneratesTestPdf;

    public function test_new_document_upload_is_accepted_and_queued_for_processing(): void
    {
        Storage::fake('public');
        Bus::fake();
        $this->authenticateApiClient();

        $file = UploadedFile::fake()->createWithContent('manual.pdf', $this->validPdfContent());

        $response = $this->postJson('/api/v1/documents', ['file' => $file]);

        $response->assertCreated();
        $response->assertJsonPath('status', 'pending');
        $this->assertDatabaseHas('documents', [
            'id' => $response->json('id'),
            'status' => 'pending',
        ]);

        Bus::assertDispatched(ProcessDocumentJob::class);
    }
}
