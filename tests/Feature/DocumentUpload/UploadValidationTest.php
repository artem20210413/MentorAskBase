<?php

namespace Tests\Feature\DocumentUpload;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class UploadValidationTest extends TestCase
{
    public function test_corrupted_pdf_is_rejected_synchronously(): void
    {
        Storage::fake('public');
        $this->authenticateApiClient();

        // Файл із правильним MIME/розширенням, але непридатною структурою PDF (FR-001b)
        $file = UploadedFile::fake()->createWithContent('broken.pdf', 'not a real pdf structure');

        $response = $this->postJson('/api/v1/documents', ['file' => $file]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('file');
        $this->assertDatabaseCount('documents', 0);
    }

    public function test_non_pdf_file_is_rejected(): void
    {
        Storage::fake('public');
        $this->authenticateApiClient();

        $file = UploadedFile::fake()->create('image.png', 10, 'image/png');

        $response = $this->postJson('/api/v1/documents', ['file' => $file]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('file');
        $this->assertDatabaseCount('documents', 0);
    }
}
