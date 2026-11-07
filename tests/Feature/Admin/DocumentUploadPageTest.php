<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Filament\Resources\DocumentResource\Pages\ManageDocuments;
use App\Jobs\ProcessDocumentJob;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\GeneratesTestPdf;
use Tests\Support\SeedsRoles;
use Tests\TestCase;

class DocumentUploadPageTest extends TestCase
{
    use GeneratesTestPdf, SeedsRoles;

    public function test_uploading_document_via_web_form_creates_pending_document(): void
    {
        $this->seedRoles();
        $admin = User::factory()->create();
        $admin->assignRole(UserRole::Admin);
        $this->actingAs($admin);

        Storage::fake('public');
        Bus::fake();

        $file = UploadedFile::fake()->createWithContent('manual.pdf', $this->validPdfContent());

        Livewire::test(ManageDocuments::class)
            ->callAction('upload', data: ['file' => $file]);

        $this->assertDatabaseHas('documents', [
            'original_name' => 'manual.pdf',
            'status' => 'pending',
        ]);

        Bus::assertDispatched(ProcessDocumentJob::class);
    }
}
