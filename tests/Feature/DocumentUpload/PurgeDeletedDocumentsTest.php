<?php

namespace Tests\Feature\DocumentUpload;

use App\Models\Document;
use App\Models\DocumentChunk;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PurgeDeletedDocumentsTest extends TestCase
{
    public function test_documents_soft_deleted_longer_than_threshold_are_permanently_removed(): void
    {
        Storage::fake('public');
        config(['rag.permanent_deletion_after_days' => 30]);

        Storage::disk('public')->put('documents/old.pdf', 'content');

        $old = Document::factory()->create(['storage_path' => 'documents/old.pdf']);
        DocumentChunk::factory()->for($old)->create();
        $old->delete();
        $old->forceFill(['deleted_at' => now()->subDays(31)])->save();

        $recent = Document::factory()->create();
        $recent->delete(); // видалений щойно — не має бути очищений

        $this->artisan('rag:purge-deleted-documents')->assertSuccessful();

        $this->assertDatabaseMissing('documents', ['id' => $old->id]);
        $this->assertDatabaseCount('document_chunks', 0);
        $this->assertDatabaseHas('documents', ['id' => $recent->id]);
        Storage::disk('public')->assertMissing('documents/old.pdf');
    }

    public function test_file_is_kept_if_another_document_still_references_it(): void
    {
        Storage::fake('public');
        config(['rag.permanent_deletion_after_days' => 30]);

        Storage::disk('public')->put('documents/shared.pdf', 'content');

        $original = Document::factory()->create(['storage_path' => 'documents/shared.pdf']);
        $duplicate = Document::factory()->create([
            'storage_path' => 'documents/shared.pdf',
            'status' => 'duplicate',
            'duplicate_of_document_id' => $original->id,
        ]);

        $duplicate->delete();
        $duplicate->forceFill(['deleted_at' => now()->subDays(31)])->save();

        $this->artisan('rag:purge-deleted-documents')->assertSuccessful();

        $this->assertDatabaseMissing('documents', ['id' => $duplicate->id]);
        Storage::disk('public')->assertExists('documents/shared.pdf');
    }
}
