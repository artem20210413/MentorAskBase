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
        private readonly EmbeddingService $embeddingService,
        private readonly VectorSearchService $vectorSearchService,
        private readonly LanguageDetectionService $languageDetectionService,
    ) {}

    /**
     * @param  array<int, array{role: string, content: string}>  $history
     * @return array{answer: string, language: string, sources: Collection<int, DocumentChunk>, match_score: ?int, input_tokens: ?int, output_tokens: ?int}
     */
    public function answer(string $question, array $history = []): array
    {
        $detectedLanguage = $this->languageDetectionService->detect($question);
        $questionEmbedding = $this->embeddingService->embed($question);
        $chunks = $this->vectorSearchService->search($questionEmbedding);

        // FR-009: немає сенсу звертатися до LLM, якщо жодного релевантного
        // фрагмента не знайдено — одразу чесна відповідь без витрат на виклик.
        if ($chunks->isEmpty()) {
            return [
                'answer' => $this->noInfoAnswer($detectedLanguage),
                'language' => $detectedLanguage,
                'sources' => collect(),
                'match_score' => null,
                'input_tokens' => null,
                'output_tokens' => null,
            ];
        }

        $matchScore = $this->matchScore($chunks);

        $messages = $this->buildMessages($question, $chunks, $detectedLanguage, $history);

        [$response, $succeeded] = $this->requestWithSingleRetry($messages);

        if (! $succeeded) {
            throw new RagAnswerGenerationException('Зовнішній сервіс мовної моделі недоступний після повторної спроби.');
        }

        $answer = trim($response->choices[0]->message->content ?? '');
        $isNoInfo = str_contains($answer, self::NO_INFO_MARKER) || $chunks->isEmpty();

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
     * @param  Collection<int, DocumentChunk>  $chunks
     */
    private function matchScore(Collection $chunks): int
    {
        $bestDistance = $chunks->min('neighbor_distance');

        return (int) round(max(0, 1 - $bestDistance) * 100);
    }

    /**
     * @param  Collection<int, DocumentChunk>  $chunks
     * @param  array<int, array{role: string, content: string}>  $history
     * @return array<int, array{role: string, content: string}>
     */
    private function buildMessages(string $question, Collection $chunks, string $language, array $history): array
    {
        $context = $chunks->isEmpty()
            ? 'Контекст відсутній.'
            : $chunks->map(fn (DocumentChunk $c, int $i) => '['.($i + 1).'] '.$c->content)->implode("\n\n");

        $system = "Ти — асистент, що відповідає на питання виключно на основі наданого контексту з бази знань.\n".
            "Відповідай мовою з кодом \"{$language}\".\n".
            'Якщо контекст не містить достатньо інформації для відповіді, поверни рядок '.self::NO_INFO_MARKER.' і нічого більше.'."\n\n".
            "Контекст:\n{$context}";

        return [
            ['role' => 'system', 'content' => $system],
            ...$history,
            ['role' => 'user', 'content' => $question],
        ];
    }

    /**
     * FR-009c: рівно одна автоматична повторна спроба при збої LLM.
     *
     * @param  array<int, array{role: string, content: string}>  $messages
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
        return match ($language) {
            'uk' => 'На жаль, у базі знань немає достатньо релевантної інформації для відповіді на це питання.',
            default => 'Unfortunately, the knowledge base does not contain enough relevant information to answer this question.',
        };
    }
}
