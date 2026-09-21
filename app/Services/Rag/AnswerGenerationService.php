<?php

namespace App\Services\Rag;

use App\Services\Conversation\LanguageDetectionService;
use Throwable;

class AnswerGenerationService
{
    public function __construct(
        private readonly LanguageDetectionService $languageDetectionService,
        private readonly QueryRewriter            $queryRewriter,
        private readonly AgentToolRunner          $agentToolRunner,
    )
    {
    }

    /**
     * FR-001/FR-002/FR-004/FR-005/FR-006/FR-007/FR-008: формує відповідь
     * через обмежений агентний цикл (AgentToolRunner) — модель сама вирішує,
     * чи звертатися до бази знань і/або інтернету, замість фіксованого
     * "завжди спершу RAG".
     *
     * @param array<int, array{role: string, content: string}> $history
     * @return array{answer: string, language: string, sources: array<int, array<string, mixed>>, match_score: ?int, input_tokens: ?int, output_tokens: ?int, tool_steps: array<int, array<string, mixed>>}
     */
    public function answer(string $question, array $history = []): array
    {
        $detectedLanguage = $this->languageDetectionService->detect($question);

        // Переформульовуємо питання для пошуку на основі історії розмови
        // (наприклад, "а другий?" → "яка гарантія на виріб Y?"); для першого
        // питання сесії (без історії) повертається без змін. Модель бачить
        // обидва варіанти — оригінальне питання (для тону) і самодостатнє
        // формулювання (щоб точніше сформувати запит до search_knowledge_base).
        $retrievalQuestion = $this->queryRewriter->rewriteForRetrieval($question, $history);

        $input = [...$history];

        if ($retrievalQuestion !== $question) {
            $input[] = [
                'role' => 'developer',
                'content' => "Self-contained version of the user's next question, for search purposes only: {$retrievalQuestion}",
            ];
        }

        $input[] = ['role' => 'user', 'content' => $question];

        $instructions = $this->buildInstructions($detectedLanguage);

        [$result, $succeeded] = $this->runWithSingleRetry($instructions, $input);

        if (!$succeeded) {
            throw new RagAnswerGenerationException('Зовнішній сервіс мовної моделі недоступний після повторної спроби.');
        }

        $answer = $result['answer'];
        $noInfoMarker = __('bot.no_relevant_info_marker');
        $isNoInfo = $answer === '' || str_contains($answer, $noInfoMarker);

        return [
            'answer' => $isNoInfo ? $this->noInfoAnswer($detectedLanguage) : $answer,
            'language' => $detectedLanguage,
            'sources' => $isNoInfo ? [] : $result['sources'],
            'match_score' => $isNoInfo ? null : $this->matchScore($result['sources']),
            'input_tokens' => $result['input_tokens'],
            'output_tokens' => $result['output_tokens'],
            'tool_steps' => $result['tool_steps'],
        ];
    }

    /**
     * Найвищий відсоток релевантності серед джерел-документів, використаних
     * у відповіді (null, якщо жодного документа не було використано).
     *
     * @param array<int, array<string, mixed>> $sources
     */
    private function matchScore(array $sources): ?int
    {
        $relevances = array_filter(array_map(
            fn(array $s) => $s['type'] === 'document' ? $s['relevance'] : null,
            $sources
        ), fn($r) => $r !== null);

        return $relevances === [] ? null : max($relevances);
    }

    private function buildInstructions(string $language): string
    {
        $parts = [
            __('bot.system_identity'),
            __('bot.answer_style'),
            __('bot.medical_disclaimer_instruction'),
            __('bot.tool_usage_instruction'),
            "Answer in the language with code \"{$language}\".",
            'If, after using the available tools, no source actually contains the answer, return the string ' . __('bot.no_relevant_info_marker') . ' and nothing else.',
        ];

        return implode("\n\n", $parts);
    }

    /**
     * FR-009c: рівно одна автоматична повторна спроба при збої LLM.
     *
     * @param array<int, mixed> $input
     * @return array{0: ?array<string, mixed>, 1: bool}
     */
    private function runWithSingleRetry(string $instructions, array $input): array
    {
        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                return [$this->agentToolRunner->run($instructions, $input), true];
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
