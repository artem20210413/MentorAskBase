<?php

namespace App\Services\DocumentIngestion;

use PhpOffice\PhpWord\Element\AbstractContainer;
use PhpOffice\PhpWord\Element\Image;
use PhpOffice\PhpWord\Element\ListItem;
use PhpOffice\PhpWord\Element\Table;
use PhpOffice\PhpWord\Element\Text;
use PhpOffice\PhpWord\Element\TextRun;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use RuntimeException;
use Throwable;

class WordTextExtractor
{
    /**
     * FR-004: вилучає текст параграфів/таблиць `.docx` у порядку читання
     * документа. FR-004a: додатково повертає тимчасові файли вбудованих
     * зображень для подальшого OCR через VisionTranscriber.
     *
     * @return array{text: string, image_paths: array<int, string>}
     */
    public function extract(string $absolutePath): array
    {
        $document = IOFactory::load($absolutePath, 'Word2007');

        $textParts = [];
        $imagePaths = [];

        foreach ($document->getSections() as $section) {
            $this->walkElements($section, $textParts, $imagePaths);
        }

        return [
            'text' => trim(implode("\n\n", array_filter($textParts, fn ($p) => trim($p) !== ''))),
            'image_paths' => $imagePaths,
        ];
    }

    /**
     * FR-003: синхронна перевірка цілісності/формату файлу під час завантаження.
     *
     * @throws RuntimeException якщо файл пошкоджений, захищений паролем або не є `.docx`, який вдається розпарсити
     */
    public function assertReadable(string $absolutePath): void
    {
        try {
            IOFactory::load($absolutePath, 'Word2007');
        } catch (Throwable $e) {
            throw new RuntimeException('Файл пошкоджений, захищений паролем або має непідтримуваний формат: '.$e->getMessage(), previous: $e);
        }
    }

    /**
     * @param  array<int, string>  $textParts
     * @param  array<int, string>  $imagePaths
     */
    private function walkElements(AbstractContainer|PhpWord $container, array &$textParts, array &$imagePaths): void
    {
        foreach ($container->getElements() as $element) {
            if ($element instanceof Text) {
                $textParts[] = $element->getText();

                continue;
            }

            if ($element instanceof ListItem) {
                $textParts[] = $element->getText();

                continue;
            }

            if ($element instanceof Image) {
                $imagePaths[] = $this->extractImageToTempFile($element);

                continue;
            }

            if ($element instanceof Table) {
                foreach ($element->getRows() as $row) {
                    foreach ($row->getCells() as $cell) {
                        $this->walkElements($cell, $textParts, $imagePaths);
                    }
                }

                continue;
            }

            if ($element instanceof TextRun || $element instanceof AbstractContainer) {
                $this->walkElements($element, $textParts, $imagePaths);
            }
        }
    }

    private function extractImageToTempFile(Image $image): string
    {
        $extension = $image->getImageExtension() ?: 'png';
        $path = tempnam(sys_get_temp_dir(), 'rag_docx_image_').'.'.$extension;

        file_put_contents($path, $image->getImageString());

        return $path;
    }
}
