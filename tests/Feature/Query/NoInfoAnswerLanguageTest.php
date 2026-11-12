<?php

namespace Tests\Feature\Query;

use App\Services\Conversation\LanguageDetectionService;
use App\Services\Rag\AgentToolRunner;
use App\Services\Rag\AnswerGenerationService;
use App\Services\Rag\QueryRewriter;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * FR-009b: відповідь "немає інформації" — з файлів перекладу answers.php
 * мовою питання; для непідтримуваної мови — мова за замовчуванням
 * (перший елемент rag.supported_languages).
 */
class NoInfoAnswerLanguageTest extends TestCase
{
    public static function languages(): array
    {
        return [
            'uk' => ['uk', 'uk'],
            'ru' => ['ru', 'ru'],
            'en' => ['en', 'en'],
            'непідтримувана → за замовчуванням' => ['de', 'uk'],
        ];
    }

    #[DataProvider('languages')]
    public function test_no_info_answer_is_taken_from_translations(string $detected, string $expectedLocale): void
    {
        config(['rag.supported_languages' => ['uk', 'en', 'ru'], 'rag.llm.query_refinement_enabled' => false]);

        $this->mock(LanguageDetectionService::class, fn ($mock) => $mock->shouldReceive('detect')->andReturn($detected));
        $this->mock(AgentToolRunner::class, fn ($mock) => $mock->shouldReceive('run')->andReturn([
            'answer' => __('bot.no_relevant_info_marker'),
            'sources' => [],
            'tool_steps' => [],
            'input_tokens' => null,
            'output_tokens' => null,
        ]));
        $this->mock(QueryRewriter::class);

        $result = app(AnswerGenerationService::class)->answer('питання');

        $this->assertSame(__('answers.no_relevant_info', [], $expectedLocale), $result['answer']);
    }
}
