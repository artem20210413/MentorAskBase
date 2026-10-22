<?php

namespace Tests\Feature\Admin;

use App\Filament\Resources\DocumentResource\Pages\ManageDocuments;
use App\Models\Document;
use App\Models\User;
use Livewire\Livewire;
use Tests\Support\SeedsRoles;
use Tests\TestCase;

class DocumentListPageTest extends TestCase
{
    use SeedsRoles;

    public function test_document_list_shows_uploaded_documents(): void
    {
        $this->seedRoles();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

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
