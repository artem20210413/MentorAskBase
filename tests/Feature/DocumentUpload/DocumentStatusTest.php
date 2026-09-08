<?php

namespace Tests\Feature\DocumentUpload;

use App\Models\Document;
use Illuminate\Support\Str;
use Tests\TestCase;

class DocumentStatusTest extends TestCase
{
    public function test_document_status_endpoint_reflects_current_state(): void
    {
        $this->authenticateApiClient();

        $document = Document::factory()->pending()->create();

        $response = $this->getJson("/api/v1/documents/{$document->id}");

        $response->assertOk();
        $response->assertJsonPath('status', 'pending');

        $document->update(['status' => 'failed', 'failure_reason' => 'Тестова помилка обробки']);

        $response = $this->getJson("/api/v1/documents/{$document->id}");

        $response->assertOk();
        $response->assertJsonPath('status', 'failed');
        $response->assertJsonPath('failure_reason', 'Тестова помилка обробки');
    }

    public function test_unknown_document_status_returns_404(): void
    {
        $this->authenticateApiClient();

        $response = $this->getJson('/api/v1/documents/'.Str::uuid());

        $response->assertNotFound();
    }
}
