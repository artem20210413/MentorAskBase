<?php

namespace App\Services\DocumentIngestion;

use App\Jobs\ProcessDocumentJob;
use App\Models\Document;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class DocumentUploadService
{
    public function __construct(
        private readonly PdfTextExtractor $textExtractor,
        private readonly WordTextExtractor $wordTextExtractor,
        private readonly TextFileExtractor $textFileExtractor,
    ) {}

    /**
     * FR-001/FR-001a/FR-001b/FR-002/FR-003/FR-003a: приймає файл, синхронно
     * валідує його, дедуплікує за хешем і за потреби ставить у чергу обробки.
     */
    public function upload(UploadedFile $file): Document
    {
        // FR-001b/FR-003: синхронна перевірка цілісності/формату файлу,
        // специфічна для розширення (pdf/docx/txt)
        $this->assertReadable($file);

        $hash = hash_file('sha256', $file->getRealPath());

        // FR-003a: критична секція "перевірити хеш і створити запис" захищена
        // блокуванням, щоб паралельні запити з тим самим хешем не запускали
        // окрему обробку одночасно.
        return Cache::lock("document-upload:{$hash}", 10)->block(5, function () use ($file, $hash) {
            $document = DB::transaction(function () use ($file, $hash) {
                $existing = Document::query()
                    ->where('content_hash', $hash)
                    ->where('status', '!=', 'duplicate')
                    ->first();

                if ($existing) {
                    return Document::create([
                        'original_name' => $file->getClientOriginalName(),
                        'content_hash' => $hash,
                        'storage_path' => $existing->storage_path,
                        'size_bytes' => $existing->size_bytes,
                        'status' => 'duplicate',
                        'duplicate_of_document_id' => $existing->id,
                    ]);
                }

                // Кожен документ зберігається у власній папці (за його ID), а
                // файл всередині — під оригінальною (санітизованою) назвою.
                // Це дає читабельне пряме посилання (FR-009d) замість хешу,
                // й водночас унікальність шляху гарантується папкою, а не
                // випадковим ім'ям файлу.
                $documentId = (string) Str::uuid();
                $safeName = $this->sanitizeFilename($file->getClientOriginalName());

                $storagePath = $this->store($file, "documents/{$documentId}", $safeName);

                if ($storagePath === false) {
                    throw new RuntimeException(__('documents.store_failed'));
                }

                return Document::create([
                    'id' => $documentId,
                    'original_name' => $file->getClientOriginalName(),
                    'content_hash' => $hash,
                    'storage_path' => $storagePath,
                    'size_bytes' => $file->getSize(),
                    'status' => 'pending',
                ]);
            });

            if ($document->status === 'pending') {
                ProcessDocumentJob::dispatch($document->id);
            }

            return $document;
        });
    }

    /**
     * .txt зберігається перекодованим у UTF-8 з BOM: пряме посилання на
     * джерело (FR-009d) віддається як text/plain без charset, і без BOM
     * браузери часто вгадують кодування неправильно ("кракозябри").
     * Решта форматів — як є. Хеш дедуплікації рахується до цього, з оригіналу.
     */
    private function store(UploadedFile $file, string $directory, string $name): string|false
    {
        $disk = Storage::disk(config('rag.document_disk'));

        if ($this->extension($file) !== 'txt') {
            return $disk->putFileAs($directory, $file, $name);
        }

        $utf8 = $this->textFileExtractor->toUtf8((string) file_get_contents($file->getRealPath()));
        $path = "{$directory}/{$name}";

        return $disk->put($path, "\xEF\xBB\xBF".$utf8) ? $path : false;
    }

    private function extension(UploadedFile $file): string
    {
        return strtolower($file->getClientOriginalExtension());
    }

    /**
     * FR-003: делегує синхронну перевірку цілісності/формату відповідному
     * екстрактору залежно від розширення файлу.
     */
    private function assertReadable(UploadedFile $file): void
    {
        match ($this->extension($file)) {
            'docx' => $this->wordTextExtractor->assertReadable($file->getRealPath()),
            'txt' => $this->textFileExtractor->assertReadable($file->getRealPath()),
            default => $this->textExtractor->assertReadable($file->getRealPath()),
        };
    }

    /**
     * Прибирає символи, небезпечні для імені файлу на диску та в URL
     * (шляхи, лапки тощо), зберігаючи читабельність оригінальної назви.
     */
    private function sanitizeFilename(string $name): string
    {
        $name = basename($name);
        $name = preg_replace('/[^\p{L}\p{N}\s._-]/u', '', $name) ?? $name;
        $name = trim(preg_replace('/\s+/', ' ', $name) ?? $name);

        return $name !== '' ? $name : 'document.pdf';
    }
}
