<?php

namespace App\Jobs;

use App\Models\Document;
use App\Services\DocumentIngestion\ChunkingService;
use App\Services\DocumentIngestion\PdfTextExtractor;
use App\Services\DocumentIngestion\PdfVisionExtractor;
use App\Services\DocumentIngestion\TextFileExtractor;
use App\Services\DocumentIngestion\VisionTranscriber;
use App\Services\DocumentIngestion\WordTextExtractor;
use App\Services\Rag\EmbeddingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class ProcessDocumentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // Документ обробляється сторінка за сторінкою через vision-модель
    // послідовними запитами до OpenAI — на багатосторінкових файлах дефолтний
    // таймаут воркера (60с) не вистачає.
    public $timeout = 600;

    public function __construct(public readonly string $documentId) {}

    /**
     * FR-004/FR-004a/FR-004b/FR-006/FR-007: асинхронно розбиває документ на
     * фрагменти й векторизує. Формат файлу (pdf/docx/txt) визначається за
     * розширенням `original_name` (уже валідованим при завантаженні, FR-003)
     * і визначає, який конвеєр вилучення тексту застосовується нижче.
     */
    public function handle(
        PdfTextExtractor $textExtractor,
        PdfVisionExtractor $visionExtractor,
        VisionTranscriber $visionTranscriber,
        WordTextExtractor $wordTextExtractor,
        TextFileExtractor $textFileExtractor,
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
            $extension = strtolower(pathinfo($document->original_name, PATHINFO_EXTENSION));

            $chunksCreated = match ($extension) {
                'docx' => $this->processWord($document, $absolutePath, $wordTextExtractor, $visionTranscriber, $chunkingService, $embeddingService),
                'txt' => $this->processText($document, $absolutePath, $textFileExtractor, $chunkingService, $embeddingService),
                default => $this->processPdf($document, $absolutePath, $textExtractor, $visionExtractor, $visionTranscriber, $chunkingService, $embeddingService),
            };

            if ($chunksCreated === 0) {
                throw new RuntimeException('З документа не вдалося вилучити придатний для пошуку текст.');
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

    /**
     * FR-005: кожна сторінка PDF розпізнається через vision-модель (а не
     * через текстовий шар) — на складних макетах (інфографіка, колонки,
     * маркетингові брошури) `smalot/pdfparser` витягує текст у порядку
     * об'єктів PDF-файлу, а не у візуальному порядку читання, що розриває
     * зв'язок між сусідніми фактами (наприклад, число й підпис до нього
     * опиняються в різних кінцях витягнутого рядка). Vision-модель читає
     * сторінку як зображення, зберігаючи природний порядок сприйняття.
     */
    private function processPdf(
        Document $document,
        string $absolutePath,
        PdfTextExtractor $textExtractor,
        PdfVisionExtractor $visionExtractor,
        VisionTranscriber $visionTranscriber,
        ChunkingService $chunkingService,
        EmbeddingService $embeddingService,
    ): int {
        $textLayerPages = $textExtractor->extract($absolutePath);
        $pageCount = count($textLayerPages);

        $position = 0;
        $chunksCreated = 0;

        for ($pageNumber = 1; $pageNumber <= $pageCount; $pageNumber++) {
            $pageText = $visionExtractor->extractPageText($absolutePath, $pageNumber);
            $source = 'vision_ocr';

            // Vision-модель іноді відмовляється розпізнавати сторінку
            // (наприклад, зображення людини на маркетинговому буклеті
            // сприймається як чутливий контент) і повертає коротку
            // відмову замість тексту. У такому разі краще взяти те, що
            // реально є в текстовому шарі PDF, ніж заембедити відмову.
            if ($visionTranscriber->looksLikeRefusal($pageText)) {
                $pageText = $textLayerPages[$pageNumber - 1] ?? '';
                $source = 'text_layer';
            }

            foreach ($chunkingService->chunk($pageText) as $chunkText) {
                $document->chunks()->create([
                    'position' => $position++,
                    'page_number' => $pageNumber,
                    'content' => $chunkText,
                    'source' => $source,
                    'embedding' => $embeddingService->embed($chunkText),
                ]);
                $chunksCreated++;
            }
        }

        return $chunksCreated;
    }

    /**
     * FR-004/FR-004a: текст параграфів/таблиць вилучається напряму
     * (`source = text_layer`), а вбудовані зображення розпізнаються через
     * ту саму vision-модель, що й скановані сторінки PDF
     * (`source = vision_ocr`). Номер сторінки не застосовний до `.docx`
     * (FR-004b/Key Entities spec.md) — усі фрагменти отримують `page_number = null`.
     */
    private function processWord(
        Document $document,
        string $absolutePath,
        WordTextExtractor $wordTextExtractor,
        VisionTranscriber $visionTranscriber,
        ChunkingService $chunkingService,
        EmbeddingService $embeddingService,
    ): int {
        $extracted = $wordTextExtractor->extract($absolutePath);
        $position = 0;
        $chunksCreated = 0;

        foreach ($chunkingService->chunk($extracted['text']) as $chunkText) {
            $document->chunks()->create([
                'position' => $position++,
                'page_number' => null,
                'content' => $chunkText,
                'source' => 'text_layer',
                'embedding' => $embeddingService->embed($chunkText),
            ]);
            $chunksCreated++;
        }

        foreach ($extracted['image_paths'] as $imagePath) {
            try {
                $imageText = $visionTranscriber->transcribe($imagePath);

                if ($imageText === '' || $visionTranscriber->looksLikeRefusal($imageText)) {
                    continue;
                }

                foreach ($chunkingService->chunk($imageText) as $chunkText) {
                    $document->chunks()->create([
                        'position' => $position++,
                        'page_number' => null,
                        'content' => $chunkText,
                        'source' => 'vision_ocr',
                        'embedding' => $embeddingService->embed($chunkText),
                    ]);
                    $chunksCreated++;
                }
            } finally {
                if (file_exists($imagePath)) {
                    unlink($imagePath);
                }
            }
        }

        return $chunksCreated;
    }

    /**
     * FR-004/FR-004b: увесь вміст `.txt` — це вже готовий текст
     * (`source = text_layer`); номер сторінки не застосовний, `page_number = null`.
     */
    private function processText(
        Document $document,
        string $absolutePath,
        TextFileExtractor $textFileExtractor,
        ChunkingService $chunkingService,
        EmbeddingService $embeddingService,
    ): int {
        $text = $textFileExtractor->extract($absolutePath);
        $position = 0;
        $chunksCreated = 0;

        foreach ($chunkingService->chunk($text) as $chunkText) {
            $document->chunks()->create([
                'position' => $position++,
                'page_number' => null,
                'content' => $chunkText,
                'source' => 'text_layer',
                'embedding' => $embeddingService->embed($chunkText),
            ]);
            $chunksCreated++;
        }

        return $chunksCreated;
    }
}
