<?php

namespace Tests\Feature\Admin;

use App\Filament\Resources\DocumentResource\Pages\ManageDocuments;
use App\Jobs\ProcessDocumentJob;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\GeneratesTestPdf;
use Tests\TestCase;

class DocumentUploadPageTest extends TestCase
{
    use GeneratesTestPdf;

    public function test_uploading_document_via_web_form_creates_pending_document(): void
    {
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
