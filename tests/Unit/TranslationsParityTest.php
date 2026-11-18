<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Усі мови інтерфейсу (uk/ru/en) мають однаковий набір ключів і однакові
 * плейсхолдери — інакше користувач побачить сирий ключ або фолбек на іншу
 * мову, а підстановка (:max, :date…) не спрацює.
 */
class TranslationsParityTest extends TestCase
{
    private const LOCALES = ['uk', 'ru', 'en'];

    /** Промпти для LLM — свідомо лише англійською (див. PromptLocalizationTest). */
    private const SINGLE_LOCALE_FILES = ['bot'];

    public static function translationFiles(): array
    {
        $files = [];

        foreach (self::LOCALES as $locale) {
            foreach (glob(dirname(__DIR__, 2)."/resources/lang/{$locale}/*.php") as $path) {
                $name = basename($path, '.php');

                if (! in_array($name, self::SINGLE_LOCALE_FILES, true)) {
                    $files[$name] = [$name];
                }
            }
        }

        return $files;
    }

    #[DataProvider('translationFiles')]
    public function test_file_has_same_keys_and_placeholders_in_all_locales(string $file): void
    {
        $reference = null;

        foreach (self::LOCALES as $locale) {
            $path = lang_path("{$locale}/{$file}.php");
            $this->assertFileExists($path, "Немає перекладу {$file}.php для {$locale}");

            $flat = $this->flatten(require $path);

            if ($reference === null) {
                $reference = $flat;

                continue;
            }

            $this->assertSame(array_keys($reference), array_keys($flat), "Ключі {$file}.php для {$locale} не збігаються з uk");

            foreach ($reference as $key => $value) {
                $this->assertSame(
                    $this->placeholders($value),
                    $this->placeholders($flat[$key]),
                    "Плейсхолдери {$file}.{$key} для {$locale} не збігаються з uk",
                );
            }
        }
    }

    /** @return array<string, string> */
    private function flatten(array $array, string $prefix = ''): array
    {
        $result = [];

        foreach ($array as $key => $value) {
            if (is_array($value) && ! array_is_list($value)) {
                $result += $this->flatten($value, "{$prefix}{$key}.");
            } else {
                // Списки (напр. thinking_phrases) порівнюємо як множину плейсхолдерів
                $result["{$prefix}{$key}"] = is_array($value) ? implode("\n", $value) : (string) $value;
            }
        }

        ksort($result);

        return $result;
    }

    /** @return array<int, string> */
    private function placeholders(string $text): array
    {
        preg_match_all('/:([a-z_]+)/', $text, $matches);
        $names = array_values(array_unique($matches[1]));
        sort($names);

        return $names;
    }
}
