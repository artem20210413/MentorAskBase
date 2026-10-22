<?php

namespace Tests\Feature\Admin;

use App\Filament\Resources\DocumentResource\Pages\ManageDocuments;
use App\Models\Document;
use App\Models\User;
use Livewire\Livewire;
use Tests\Support\SeedsRoles;
use Tests\TestCase;

class DocumentDeletePageTest extends TestCase
{
    use SeedsRoles;

    public function test_deleting_document_from_page_soft_deletes_it(): void
    {
        $this->seedRoles();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        $document = Document::factory()->create(['status' => 'processed']);

        Livewire::test(ManageDocuments::class)
            ->callTableAction('delete', $document);

        // FR-017a: м'яке видалення — запис лишається в БД, але позначений як видалений
        $this->assertSoftDeleted('documents', ['id' => $document->id]);

        Livewire::test(ManageDocuments::class)
            ->assertDontSee($document->original_name);
    }
}
