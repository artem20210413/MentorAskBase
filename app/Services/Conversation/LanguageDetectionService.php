<?php

namespace App\Services\Conversation;

use LanguageDetection\Language;

class LanguageDetectionService
{
    /**
     * FR-009a/b: визначає мову тексту; повертає код мови зі списку
     * підтримуваних або мову за замовчуванням (перший елемент конфігурації),
     * якщо визначена мова не підтримується.
     */
    public function detect(string $text): string
    {
        $supported = config('rag.supported_languages');
        $default = $supported[0];

        // Детектор навмисно навчається на ВСІХ доступних мовах (а не лише на
        // списку підтримуваних), інакше він завжди повертав би одну з
        // підтримуваних навіть для тексту зовсім іншою мовою — і FR-009b
        // (фолбек на мову за замовчуванням) ніколи б не спрацьовував.
        $detector = new Language;
        $result = $detector->detect($text)->bestResults()->close();

        $detected = array_key_first($result) ?: null;

        return in_array($detected, $supported, true) ? $detected : $default;
    }
}
