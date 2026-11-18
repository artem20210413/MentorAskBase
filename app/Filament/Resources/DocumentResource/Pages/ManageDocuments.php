<?php

namespace App\Filament\Resources\DocumentResource\Pages;

use App\Filament\Resources\DocumentResource;
use App\Services\DocumentIngestion\DocumentUploadService;
use Filament\Actions;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use Illuminate\Http\UploadedFile;
use RuntimeException;

class ManageDocuments extends ManageRecords
{
    protected static string $resource = DocumentResource::class;

    private const ACCEPTED_MIME_TYPES = [
        'application/pdf',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'text/plain',
    ];

    protected function getHeaderActions(): array
    {
        return [
            // FR-016: форма завантаження використовує ту саму логіку
            // обробки, що й API-завантаження (DocumentUploadService).
            Actions\Action::make('upload')
                ->label(__('admin.documents.upload'))
                ->form([
                    FileUpload::make('files')
                        ->label(__('admin.documents.files'))
                        ->required()
                        ->multiple()
                        // Ті самі формати, що й API (UploadDocumentRequest: pdf, docx, txt)
                        ->acceptedFileTypes(self::ACCEPTED_MIME_TYPES)
                        ->maxSize(config('rag.max_upload_size_mb') * 1024)
                        ->storeFiles(false),
                ])
                ->action(fn (array $data) => $this->uploadFiles($data['files'])),
        ];
    }

    /**
     * Кожен файл завантажується окремо: пошкоджений файл (FR-001b — та сама
     * синхронна перевірка, що в API повертає 422) не зриває завантаження
     * решти, а адмін бачить, який файл і чому відхилено.
     *
     * @param  array<int, UploadedFile>  $files
     */
    private function uploadFiles(array $files): void
    {
        $uploaded = 0;
        $failed = [];

        foreach ($files as $file) {
            try {
                app(DocumentUploadService::class)->upload($file);
                $uploaded++;
            } catch (RuntimeException $e) {
                $failed[] = __('admin.documents.upload_failed_item', [
                    'name' => $file->getClientOriginalName(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($uploaded > 0) {
            Notification::make()
                ->success()
                ->title(__('admin.documents.upload_success', ['count' => $uploaded]))
                ->send();
        }

        if ($failed !== []) {
            Notification::make()
                ->danger()
                ->title(__('admin.documents.upload_failed'))
                ->body(implode("\n", $failed))
                ->persistent()
                ->send();
        }
    }
}
