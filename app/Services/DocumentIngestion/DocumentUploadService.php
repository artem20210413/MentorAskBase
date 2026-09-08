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
    public function __construct(private readonly PdfTextExtractor $textExtractor) {}

    /**
     * FR-001/FR-001a/FR-001b/FR-002/FR-003/FR-003a: приймає файл, синхронно
     * валідує його, дедуплікує за хешем і за потреби ставить у чергу обробки.
     */
    public function upload(UploadedFile $file): Document
    {
        // FR-001b: синхронна перевірка цілісності/формату файлу
        $this->textExtractor->assertReadable($file->getRealPath());

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

                $storagePath = Storage::disk(config('rag.document_disk'))
                    ->putFileAs("documents/{$documentId}", $file, $safeName);

                if ($storagePath === false) {
                    throw new RuntimeException('Не вдалося зберегти файл документа.');
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
