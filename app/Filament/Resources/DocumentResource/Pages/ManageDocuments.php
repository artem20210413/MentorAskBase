<?php

namespace App\Filament\Resources\DocumentResource\Pages;

use App\Filament\Resources\DocumentResource;
use App\Services\DocumentIngestion\DocumentUploadService;
use Filament\Actions;
use Filament\Forms\Components\FileUpload;
use Filament\Resources\Pages\ManageRecords;
use Illuminate\Http\UploadedFile;

class ManageDocuments extends ManageRecords
{
    protected static string $resource = DocumentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // FR-016: форма завантаження використовує ту саму логіку
            // обробки, що й API-завантаження (DocumentUploadService).
            Actions\Action::make('upload')
                ->label('Завантажити документи')
                ->form([
                    FileUpload::make('files')
                        ->label('PDF-файли')
                        ->required()
                        ->multiple()
                        ->acceptedFileTypes(['application/pdf'])
                        ->maxSize(config('rag.max_upload_size_mb') * 1024)
                        ->storeFiles(false),
                ])
                ->action(function (array $data): void {
                    /** @var array<int, UploadedFile> $files */
                    $files = $data['files'];

                    foreach ($files as $file) {
                        app(DocumentUploadService::class)->upload($file);
                    }
                }),
        ];
    }
}
