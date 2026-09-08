<?php

namespace Tests\Feature\DocumentUpload;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class UploadSizeLimitTest extends TestCase
{
    public function test_file_larger_than_50mb_is_rejected(): void
    {
        Storage::fake('public');
        $this->authenticateApiClient();

        // FR-001a: ліміт 50 МБ = 51200 КБ; беремо трохи більше
        $file = UploadedFile::fake()->create('big.pdf', 51201, 'application/pdf');

        $response = $this->postJson('/api/v1/documents', ['file' => $file]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('file');
        $this->assertDatabaseCount('documents', 0);
    }
}
