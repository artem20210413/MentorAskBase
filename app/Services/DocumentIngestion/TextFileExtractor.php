<?php

namespace App\Services\DocumentIngestion;

use RuntimeException;

class TextFileExtractor
{
    /**
     * FR-004: читає вміст `.txt`-файлу. FR-004b: гарантована підтримка лише UTF-8.
     */
    public function extract(string $absolutePath): string
    {
        return trim($this->readUtf8($absolutePath));
    }

    /**
     * FR-003/FR-004b: синхронна перевірка кодування під час завантаження.
     *
     * @throws RuntimeException якщо файл не читається або не в UTF-8
     */
    public function assertReadable(string $absolutePath): void
    {
        $this->readUtf8($absolutePath);
    }

    private function readUtf8(string $absolutePath): string
    {
        $content = file_get_contents($absolutePath);

        if ($content === false) {
            throw new RuntimeException('Не вдалося прочитати текстовий файл.');
        }

        if (! mb_check_encoding($content, 'UTF-8')) {
            throw new RuntimeException('Непідтримуване кодування текстового файлу: очікується UTF-8.');
        }

        return $content;
    }
}
