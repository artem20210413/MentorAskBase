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
            ? 'Контекст відсутній.'
            : $chunks->map(function (DocumentChunk $c, int $i) {
                $percentage = $this->percentageFor($c->neighbor_distance);

                return '[' . ($i + 1) . "] (релевантність: {$percentage}%) {$c->content}";
            })->implode("\n\n");

        $system = "Ти — доброзичливий, живий співрозмовник, що допомагає людям розібратися з питаннями на основі наданого контексту з бази знань.\n" .
            'Спілкуйся природно й невимушено, як реальна людина в чаті: короткими реченнями, без канцеляриту, без зайвих вступних фраз на кшталт "Згідно з наданим контекстом" чи "На основі документа". ' .
            "Можеш звертатися до співрозмовника напряму, підтримувати тон розмови, ставити уточнювальне запитання, якщо це доречно.\n" .
            "Факти про базу знань бери ЛИШЕ з контексту нижче — нічого не вигадуй і не додавай зі своїх загальних знань.\n" .
            "Кожен фрагмент контексту має позначку релевантності у відсотках (100% — точний збіг, 0% — майже не пов'язаний). " .
            "Довіряй передусім фрагментам із вищим відсотком; якщо фрагменти суперечать один одному або лише один справді відповідає на питання — обирай найрелевантніший, а не просто перший.\n" .
            "Якщо питання стосується самої розмови (наприклад, \"про що ми говорили\", \"що я питав раніше\", \"повтори попередню відповідь\") — вільно відповідай на основі попередніх повідомлень цього діалогу, це не вважається вигадуванням.\n" .
            "Відповідай мовою з кодом \"{$language}\".\n" .
            'Якщо питання стосується бази знань, але жоден фрагмент справді не містить відповіді, поверни рядок ' . self::NO_INFO_MARKER . ' і нічого більше.' . "\n\n" .
            "Контекст із бази знань:\n{$context}";

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
