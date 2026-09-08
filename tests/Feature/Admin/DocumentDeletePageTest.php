<?php

namespace Tests\Feature\Admin;

use App\Filament\Resources\DocumentResource\Pages\ManageDocuments;
use App\Models\Document;
use Livewire\Livewire;
use Tests\TestCase;

class DocumentDeletePageTest extends TestCase
{
    public function test_deleting_document_from_page_soft_deletes_it(): void
    {
        $document = Document::factory()->create(['status' => 'processed']);

        Livewire::test(ManageDocuments::class)
            ->callTableAction('delete', $document);

        // FR-017a: м'яке видалення — запис лишається в БД, але позначений як видалений
        $this->assertSoftDeleted('documents', ['id' => $document->id]);

        Livewire::test(ManageDocuments::class)
            ->assertDontSee($document->original_name);
    }
}
