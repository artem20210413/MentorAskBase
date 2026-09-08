<?php

namespace App\Services\Rag;

use App\Models\DocumentChunk;
use App\Services\Conversation\LanguageDetectionService;
use Illuminate\Support\Collection;
use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Responses\Chat\CreateResponse;
use Throwable;

class AnswerGenerationService
{
    /** Відповідь, коли релевантної інформації не знайдено (FR-009). */
    private const NO_INFO_MARKER = '__NO_RELEVANT_INFO__';

    public function __construct(
        private readonly EmbeddingService         $embeddingService,
        private readonly VectorSearchService      $vectorSearchService,
        private readonly LanguageDetectionService $languageDetectionService,
        private readonly QueryRewriter            $queryRewriter,
    )
    {
    }

    /**
     * @param array<int, array{role: string, content: string}> $history
     * @return array{answer: string, language: string, sources: Collection<int, DocumentChunk>, match_score: ?int, input_tokens: ?int, output_tokens: ?int}
     */
    public function answer(string $question, array $history = []): array
    {
        $detectedLanguage = $this->languageDetectionService->detect($question);

        // Переформульовуємо питання для пошуку на основі історії розмови
        // (наприклад, "а другий?" → "яка гарантія на виріб Y?"); для першого
        // питання сесії (без історії) повертається без змін.
        $retrievalQuestion = $this->queryRewriter->rewriteForRetrieval($question, $history);
        $questionEmbedding = $this->embeddingService->embed($retrievalQuestion);
        $chunks = $this->vectorSearchService->search($questionEmbedding);

        // FR-009: коротка відповідь без виклику LLM — але лише якщо взагалі
        // немає на що спертися: ні релевантних фрагментів бази знань, ні
        // історії розмови (з якої можна було б відповісти на мета-питання
        // на кшталт "про що ми говорили?").
        if ($chunks->isEmpty() && empty($history)) {
            return [
                'answer' => $this->noInfoAnswer($detectedLanguage),
                'language' => $detectedLanguage,
                'sources' => collect(),
                'match_score' => null,
                'input_tokens' => null,
                'output_tokens' => null,
            ];
        }

        $matchScore = $chunks->isEmpty() ? null : $this->matchScore($chunks);

        $messages = $this->buildMessages($question, $chunks, $detectedLanguage, $history);
        [$response, $succeeded] = $this->requestWithSingleRetry($messages);

        if (!$succeeded) {
            throw new RagAnswerGenerationException('Зовнішній сервіс мовної моделі недоступний після повторної спроби.');
        }

        $answer = trim($response->choices[0]->message->content ?? '');
        $isNoInfo = str_contains($answer, self::NO_INFO_MARKER);

        return [
            'answer' => $isNoInfo ? $this->noInfoAnswer($detectedLanguage) : $answer,
            'language' => $detectedLanguage,
            'sources' => $isNoInfo ? collect() : $chunks,
            'match_score' => $isNoInfo ? null : $matchScore,
            'input_tokens' => $response->usage->promptTokens ?? null,
            'output_tokens' => $response->usage->completionTokens ?? null,
        ];
    }

    /**
     * Відсоток релевантності найкращого (найближчого) фрагмента серед
     * знайдених: косинусна відстань 0 → 100%, відстань 1+ → 0%.
     *
     * @param Collection<int, DocumentChunk> $chunks
     */
    private function matchScore(Collection $chunks): int
    {
        return $this->percentageFor($chunks->min('neighbor_distance'));
    }

    private function percentageFor(float $distance): int
    {
        return VectorSearchService::relevancePercent($distance);
    }

    /**
     * @param Collection<int, DocumentChunk> $chunks
     * @param array<int, array{role: string, content: string}> $history
     * @return array<int, array{role: string, content: string}>
     */
    private function buildMessages(string $question, Collection $chunks, string $language, array $history): array
    {
        // Кожен фрагмент супроводжується відсотком релевантності — LLM сама
        // вирішує, якому фрагменту довіряти більше, якщо вони різняться чи
        // суперечать один одному, замість сліпо покладатися на порядок.
        $context = $chunks->isEmpty()
            ? 'No context available.'
            : $chunks->map(function (DocumentChunk $c, int $i) {
                $percentage = $this->percentageFor($c->neighbor_distance);

                return '[' . ($i + 1) . "] (relevance: {$percentage}%) {$c->content}";
            })->implode("\n\n");

        $system = "You are a friendly, lively conversational partner who helps people with questions based on the provided knowledge-base context.\n" .
            'Speak naturally and casually, like a real person in a chat: short sentences, no corporate-speak, no filler intros like "According to the provided context" or "Based on the document". ' .
            "You can address the person directly, keep the conversational tone, and ask a clarifying question when it makes sense.\n" .
            "Take knowledge-base facts ONLY from the context below — don't make anything up or add from your general knowledge.\n" .
            "Each context fragment has a relevance percentage (100% — exact match, 0% — barely related). " .
            "Trust higher-percentage fragments first; if fragments contradict each other or only one actually answers the question — pick the most relevant one, not just the first one.\n" .
            "If the question is about the conversation itself (e.g. \"what did we talk about\", \"what did I ask earlier\", \"repeat the previous answer\") — answer freely based on the earlier messages in this dialogue, that's not considered making things up.\n" .
            "Answer in the language with code \"{$language}\".\n" .
            'If the question is about the knowledge base but no fragment actually contains the answer, return the string ' . self::NO_INFO_MARKER . ' and nothing else.' . "\n\n" .
            "Knowledge-base context:\n{$context}";

        return [
            ['role' => 'system', 'content' => $system],
            ...$history,
            ['role' => 'user', 'content' => $question],
        ];
    }

    /**
     * FR-009c: рівно одна автоматична повторна спроба при збої LLM.
     *
     * @param array<int, array{role: string, content: string}> $messages
     * @return array{0: ?CreateResponse, 1: bool}
     */
    private function requestWithSingleRetry(array $messages): array
    {
        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                $response = OpenAI::chat()->create([
                    'model' => config('rag.openai.chat_model'),
                    'messages' => $messages,
                ]);

                return [$response, true];
            } catch (Throwable $e) {
                if ($attempt === 1) {
                    return [null, false];
                }
            }
        }

        return [null, false];
    }

    private function noInfoAnswer(string $language): string
    {
        // FR-009b: якщо для мови немає готового тексту, фолбек на мову за
        // замовчуванням (перший елемент rag.supported_languages), а не на
        // жорстко зашиту англійську.
        return match ($language) {
            'uk' => 'Хм, у мене немає точної інформації з цього приводу в наявних документах. Спробуйте перефразувати питання або запитати про щось інше?',
            'ru' => 'Хм, у меня нет точной информации по этому поводу в имеющихся документах. Попробуйте перефразировать вопрос или спросить о чём-то другом?',
            'en' => "Hmm, I don't have solid information on that in the documents I have. Feel free to rephrase or ask something else!",
            default => $this->noInfoAnswer(config('rag.supported_languages')[0]),
        };
    }
}
