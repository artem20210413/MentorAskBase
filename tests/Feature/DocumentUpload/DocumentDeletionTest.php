<?php

namespace Tests\Feature\DocumentUpload;

use App\Models\Document;
use Illuminate\Support\Str;
use Tests\TestCase;

class DocumentDeletionTest extends TestCase
{
    public function test_document_can_be_soft_deleted_via_api(): void
    {
        $this->authenticateApiClient();

        $document = Document::factory()->create(['status' => 'processed']);

        $response = $this->deleteJson("/api/v1/documents/{$document->id}");

        $response->assertNoContent();
        $this->assertSoftDeleted('documents', ['id' => $document->id]);
    }

    public function test_deleting_unknown_document_returns_404(): void
    {
        $this->authenticateApiClient();

        $response = $this->deleteJson('/api/v1/documents/'.Str::uuid());

        $response->assertNotFound();
    }

    public function test_deleted_document_no_longer_appears_in_list(): void
    {
        $this->authenticateApiClient();

        $document = Document::factory()->create(['status' => 'processed']);
        $document->delete();

        $response = $this->getJson('/api/v1/documents');

        $response->assertOk();
        $response->assertJsonMissing(['id' => $document->id]);
    }
}
