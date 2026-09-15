<?php

namespace Tests\Unit;

use Tests\TestCase;

class PromptLocalizationTest extends TestCase
{
    public function test_no_hardcoded_instruction_fragments_remain_in_rag_services(): void
    {
        // Характерні фрагменти наявних (раніше захардкоджених) промптів —
        // жодного з них не повинно бути прямо в коді сервісів (FR-009).
        $fingerprints = [
            'friendly, lively conversational partner',
            'help build a clear search query',
            '__NO_RELEVANT_INFO__',
        ];

        foreach (['AnswerGenerationService.php', 'QueryRewriter.php', 'AgentToolRunner.php', 'KnowledgeBaseSearchTool.php'] as $file) {
            $contents = file_get_contents(app_path("Services/Rag/{$file}"));

            foreach ($fingerprints as $fingerprint) {
                $this->assertStringNotContainsString(
                    $fingerprint,
                    $contents,
                    "Знайдено захардкоджений текст промпту \"{$fingerprint}\" у {$file}"
                );
            }
        }
    }

    public function test_bot_lang_file_contains_all_required_keys(): void
    {
        $bot = require base_path('resources/lang/en/bot.php');

        foreach ([
            'system_identity',
            'answer_style',
            'no_relevant_info_marker',
            'query_rewrite_instruction',
            'knowledge_base_tool_description',
            'web_search_tool_description',
            'medical_disclaimer_instruction',
        ] as $key) {
            $this->assertArrayHasKey($key, $bot);
            $this->assertNotEmpty($bot[$key]);
        }
    }

    public function test_adding_new_locale_changes_instruction_text_without_code_changes(): void
    {
        $langPath = lang_path('uk/bot.php');
        $shouldCleanup = ! is_dir(lang_path('uk'));

        if (! is_dir(lang_path('uk'))) {
            mkdir(lang_path('uk'), 0755, true);
        }

        file_put_contents($langPath, "<?php\n\nreturn ['answer_style' => 'Український стиль відповіді.'];\n");

        app()->setLocale('uk');
        // Симулюємо відсутні ключі через fallback на англійську (Laravel-стандарт),
        // головне — переконатися, що змінений ключ підхопився без правок коду.
        app('translator')->setLoaded([]);

        $this->assertSame('Український стиль відповіді.', __('bot.answer_style'));

        app()->setLocale('en');
        unlink($langPath);

        if ($shouldCleanup) {
            rmdir(lang_path('uk'));
        }
    }
}
