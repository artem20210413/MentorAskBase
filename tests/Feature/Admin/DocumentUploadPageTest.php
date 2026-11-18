<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Filament\Resources\DocumentResource\Pages\ManageDocuments;
use App\Jobs\ProcessDocumentJob;
use App\Models\Document;
use App\Models\DocumentChunk;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Responses\Embeddings\CreateResponse as EmbeddingsCreateResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\GeneratesTestPdf;
use Tests\Support\SeedsRoles;
use Tests\TestCase;

/**
 * FR-016: форма завантаження в адмінці приймає ті самі формати, що й API
 * (PDF, DOCX, TXT), і використовує ту саму логіку обробки.
 */
class DocumentUploadPageTest extends TestCase
{
    use GeneratesTestPdf, SeedsRoles;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        $admin = User::factory()->create();
        $admin->assignRole(UserRole::Admin);
        $this->actingAs($admin);

        Storage::fake('public');
    }

    public static function supportedFiles(): array
    {
        return [
            'pdf' => ['manual.pdf'],
            'docx' => ['handbook.docx'],
            'txt' => ['notes.txt'],
        ];
    }

    #[DataProvider('supportedFiles')]
    public function test_supported_file_creates_pending_document(string $name): void
    {
        Bus::fake();

        Livewire::test(ManageDocuments::class)
            ->callAction('upload', data: ['files' => [$this->file($name)]])
            ->assertHasNoActionErrors();

        $this->assertDatabaseHas('documents', ['original_name' => $name, 'status' => 'pending']);
        Bus::assertDispatched(ProcessDocumentJob::class);
    }

    /**
     * Повний шлях: форма → DocumentUploadService → ProcessDocumentJob
     * (sync-черга в тестах) → вилучення тексту → фрагменти з ембедингами.
     */
    public function test_docx_and_txt_are_processed_into_searchable_chunks(): void
    {
        $dimensions = config('rag.openai.embedding_dimensions', 1536);
        OpenAI::fake([
            EmbeddingsCreateResponse::fake(['data' => [['embedding' => array_fill(0, $dimensions, 0.1)]]]),
            EmbeddingsCreateResponse::fake(['data' => [['embedding' => array_fill(0, $dimensions, 0.1)]]]),
        ]);

        Livewire::test(ManageDocuments::class)
            ->callAction('upload', data: ['files' => [$this->file('handbook.docx'), $this->file('notes.txt')]])
            ->assertHasNoActionErrors()
            ->assertNotified(__('admin.documents.upload_success', ['count' => 2]));

        $docx = Document::where('original_name', 'handbook.docx')->firstOrFail();
        $txt = Document::where('original_name', 'notes.txt')->firstOrFail();

        $this->assertSame('processed', $docx->status);
        $this->assertSame('processed', $txt->status);

        $docxText = DocumentChunk::where('document_id', $docx->id)->pluck('content')->implode(' ');
        $txtText = DocumentChunk::where('document_id', $txt->id)->pluck('content')->implode(' ');

        $this->assertStringContainsString('Це тестовий документ Word', $docxText);
        $this->assertStringContainsString('Це тестовий текстовий файл', $txtText);

        // Файл збережено на диску під оригінальною назвою
        Storage::disk('public')->assertExists($docx->storage_path);
        $this->assertStringEndsWith('/handbook.docx', $docx->storage_path);
    }

    /**
     * Пошкоджений файл не валить усю форму (раніше — 500): решта файлів
     * завантажується, а адмін бачить, який файл і чому відхилено.
     */
    public function test_unreadable_file_is_reported_and_other_files_are_still_uploaded(): void
    {
        Bus::fake();

        $broken = UploadedFile::fake()->createWithContent('broken.docx', 'це не docx, а просто текст');

        Livewire::test(ManageDocuments::class)
            ->callAction('upload', data: ['files' => [$broken, $this->file('notes.txt')]]);

        // assertNotified() Filament забирає сповіщення з сесії — перевіряємо обидва за раз
        $notifications = collect(session('filament.notifications'))->keyBy('title');
        $this->assertTrue($notifications->has(__('admin.documents.upload_success', ['count' => 1])));
        $this->assertStringContainsString('«broken.docx»', $notifications[__('admin.documents.upload_failed')]['body']);

        $this->assertDatabaseMissing('documents', ['original_name' => 'broken.docx']);
        $this->assertDatabaseHas('documents', ['original_name' => 'notes.txt', 'status' => 'pending']);
    }

    public function test_txt_in_windows_1251_is_accepted(): void
    {
        Bus::fake();

        Livewire::test(ManageDocuments::class)
            ->callAction('upload', data: ['files' => [$this->file('notes-cp1251.txt', 'notes-cp1251.txt')]])
            ->assertNotified(__('admin.documents.upload_success', ['count' => 1]));

        $this->assertDatabaseHas('documents', ['original_name' => 'notes-cp1251.txt', 'status' => 'pending']);
    }

    public function test_unsupported_file_type_is_rejected_by_form_validation(): void
    {
        Bus::fake();

        Livewire::test(ManageDocuments::class)
            ->callAction('upload', data: ['files' => [UploadedFile::fake()->image('photo.png')]])
            ->assertHasActionErrors();

        $this->assertDatabaseCount('documents', 0);
    }

    private function file(string $name, ?string $fixture = null): UploadedFile
    {
        $content = match (pathinfo($name, PATHINFO_EXTENSION)) {
            'pdf' => $this->validPdfContent(),
            'docx' => file_get_contents(base_path('tests/Fixtures/documents/'.($fixture ?? 'document.docx'))),
            default => file_get_contents(base_path('tests/Fixtures/documents/'.($fixture ?? 'notes.txt'))),
        };

        return UploadedFile::fake()->createWithContent($name, $content);
    }
}
