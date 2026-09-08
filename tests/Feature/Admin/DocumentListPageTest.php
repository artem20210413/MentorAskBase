<?php

namespace Tests\Feature\Admin;

use App\Filament\Resources\DocumentResource\Pages\ManageDocuments;
use App\Models\Document;
use Livewire\Livewire;
use Tests\TestCase;

class DocumentListPageTest extends TestCase
{
    public function test_document_list_shows_uploaded_documents(): void
    {
        $document = Document::factory()->create([
            'original_name' => 'policy.pdf',
            'status' => 'processed',
        ]);

        Livewire::test(ManageDocuments::class)
            ->assertOk()
            ->assertSee('policy.pdf')
            ->assertSee('Оброблено');
    }
}
