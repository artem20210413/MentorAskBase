<?php

namespace App\Services\DocumentIngestion;

use RuntimeException;

/**
 * FR-004/FR-004b: вміст `.txt`-файлу в UTF-8.
 *
 * Підтримувані кодування (змінено 2026-11-10 — раніше лише UTF-8):
 * - UTF-8, з BOM або без;
 * - UTF-16 LE/BE з BOM (Блокнот Windows, "Юнікод");
 * - Windows-1251 (Блокнот з української/російської локалі, "ANSI") —
 *   фолбек для будь-якого тексту, що не є валідним UTF-8.
 *
 * Двійкові файли (нульові байти без UTF-16 BOM, керівні символи)
 * відхиляються — це не текст, а, напр., картинка з розширенням .txt.
 */
class TextFileExtractor
{
    private const BOM_UTF8 = "\xEF\xBB\xBF";

    private const BOM_UTF16LE = "\xFF\xFE";

    private const BOM_UTF16BE = "\xFE\xFF";

    public function extract(string $absolutePath): string
    {
        return trim($this->toUtf8($this->read($absolutePath)));
    }

    /**
     * FR-003: синхронна перевірка під час завантаження.
     *
     * @throws RuntimeException якщо файл не читається або не є текстом
     */
    public function assertReadable(string $absolutePath): void
    {
        $this->toUtf8($this->read($absolutePath));
    }

    /**
     * Перекодовує вміст у UTF-8 (без BOM).
     *
     * @throws RuntimeException якщо вміст не схожий на текст
     */
    public function toUtf8(string $content): string
    {
        $text = match (true) {
            str_starts_with($content, self::BOM_UTF8) => substr($content, 3),
            str_starts_with($content, self::BOM_UTF16LE) => mb_convert_encoding(substr($content, 2), 'UTF-8', 'UTF-16LE'),
            str_starts_with($content, self::BOM_UTF16BE) => mb_convert_encoding(substr($content, 2), 'UTF-8', 'UTF-16BE'),
            mb_check_encoding($content, 'UTF-8') => $content,
            default => mb_convert_encoding($content, 'UTF-8', 'Windows-1251'),
        };

        if (! $this->looksLikeText($text)) {
            throw new RuntimeException(__('documents.unsupported_encoding'));
        }

        return $text;
    }

    private function read(string $absolutePath): string
    {
        $content = file_get_contents($absolutePath);

        if ($content === false) {
            throw new RuntimeException(__('documents.text_unreadable'));
        }

        return $content;
    }

    /**
     * Текст не містить керівних символів, окрім табуляції, переносів рядка
     * та розриву сторінки. Нульовий байт після декодування = двійковий файл
     * або UTF-16 без BOM (який надійно не розпізнати).
     */
    private function looksLikeText(string $text): bool
    {
        return preg_match('/[\x00-\x08\x0B\x0E-\x1F\x7F]/', $text) !== 1;
    }
}
