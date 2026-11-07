<?php

namespace App\Services\Telegram;

use App\Models\Document;
use App\Models\DocumentSummary;
use App\Services\LlmOptions;
use OpenAI\Laravel\Facades\OpenAI;

/**
 * FR-012b/FR-013/FR-014: AI-резюме документа з кешем за документ+мова.
 */
class DocumentSummaryService
{
    /**
     * @return string|null null, якщо документ видалений або без тексту (FR-012b)
     */
    public function summaryFor(Document $document, string $locale): ?string
    {
        if ($document->trashed()) {
            return null;
        }

        $cached = DocumentSummary::where('document_id', $document->id)
            ->where('locale', $locale)
            ->first();

        if ($cached) {
            return $cached->summary;
        }

        $text = $document->chunks()->orderBy('position')->pluck('content')->implode("\n\n");

        if ($text === '') {
            return null;
        }

        $summary = $this->generate($text, $locale);

        DocumentSummary::create([
            'document_id' => $document->id,
            'locale' => $locale,
            'summary' => $summary,
        ]);

        return $summary;
    }

    private function generate(string $text, string $locale): string
    {
        $localeName = match ($locale) {
            'ru' => 'російською мовою',
            'en' => 'English',
            default => 'українською мовою',
        };

        $response = OpenAI::chat()->create([
            'model' => config('rag.openai.chat_model'),
            'messages' => [
                [
                    'role' => 'system',
                    'content' => "Стисло (3-5 речень) підсумуй зміст наведеного документа {$localeName}.",
                ],
                ['role' => 'user', 'content' => $text],
            ],
            ...LlmOptions::forChat(),
        ]);

        return trim($response->choices[0]->message->content ?? '');
    }
}
