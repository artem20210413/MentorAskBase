<?php

namespace App\Jobs;

use App\Models\Document;
use App\Services\DocumentIngestion\ChunkingService;
use App\Services\DocumentIngestion\PdfTextExtractor;
use App\Services\DocumentIngestion\PdfVisionExtractor;
use App\Services\Rag\EmbeddingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ProcessDocumentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly string $documentId) {}

    /**
     * FR-004/FR-005/FR-006/FR-007: асинхронно розбиває документ на фрагменти
     * й векторизує. Кожна сторінка розпізнається через vision-модель (а не
     * через текстовий шар PDF) — на складних макетах (інфографіка, колонки,
     * маркетингові брошури) `smalot/pdfparser` витягує текст у порядку
     * об'єктів PDF-файлу, а не у візуальному порядку читання, що розриває
     * зв'язок між сусідніми фактами (наприклад, число й підпис до нього
     * опиняються в різних кінцях витягнутого рядка). Vision-модель читає
     * сторінку як зображення, зберігаючи природний порядок сприйняття.
     */
    public function handle(
        PdfTextExtractor $textExtractor,
        PdfVisionExtractor $visionExtractor,
        ChunkingService $chunkingService,
        EmbeddingService $embeddingService,
    ): void {
        $document = Document::findOrFail($this->documentId);

        if ($document->status !== 'pending') {
            return;
        }

        $document->update(['status' => 'processing']);

        try {
            $absolutePath = Storage::disk(config('rag.document_disk'))->path($document->storage_path);
            $pageCount = count($textExtractor->extract($absolutePath));

            $position = 0;

            for ($pageNumber = 1; $pageNumber <= $pageCount; $pageNumber++) {
                $pageText = $visionExtractor->extractPageText($absolutePath, $pageNumber);

                foreach ($chunkingService->chunk($pageText) as $chunkText) {
                    $document->chunks()->create([
                        'position' => $position++,
                        'page_number' => $pageNumber,
                        'content' => $chunkText,
                        'source' => 'vision_ocr',
                        'embedding' => $embeddingService->embed($chunkText),
                    ]);
                }
            }

            $document->update(['status' => 'processed']);
        } catch (Throwable $e) {
            Log::channel('rag')->error('Не вдалося обробити документ', [
                'document_id' => $document->id,
                'exception' => $e->getMessage(),
            ]);

            $document->update([
                'status' => 'failed',
                'failure_reason' => $e->getMessage(),
            ]);
        }
    }
}
