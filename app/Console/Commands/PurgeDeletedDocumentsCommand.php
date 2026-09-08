<?php

namespace App\Console\Commands;

use App\Models\Document;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class PurgeDeletedDocumentsCommand extends Command
{
    protected $signature = 'rag:purge-deleted-documents';

    protected $description = 'Остаточно (фізично) видаляє документи, м\'яко видалені раніше, ніж config(rag.permanent_deletion_after_days) днів тому (FR-017a)';

    public function handle(): int
    {
        $days = config('rag.permanent_deletion_after_days');

        $documents = Document::onlyTrashed()
            ->where('deleted_at', '<=', now()->subDays($days))
            ->get();

        foreach ($documents as $document) {
            // Дублікати (FR-002/003) можуть посилатися на той самий
            // storage_path, що й оригінал — файл видаляємо з диска лише
            // якщо жоден інший запис (видалений чи ні) більше на нього не посилається.
            $isLastReference = Document::withTrashed()
                ->where('storage_path', $document->storage_path)
                ->where('id', '!=', $document->id)
                ->doesntExist();

            if ($isLastReference) {
                Storage::disk(config('rag.document_disk'))->delete($document->storage_path);
            }

            // FK document_chunks.document_id має ON DELETE CASCADE —
            // фрагменти й вектори видаляються автоматично на рівні БД.
            $document->forceDelete();
        }

        Log::channel('rag')->info('Остаточно видалено документів', ['count' => $documents->count()]);
        $this->info("Остаточно видалено документів: {$documents->count()}");

        return self::SUCCESS;
    }
}
