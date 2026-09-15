<?php

namespace App\Services\DocumentIngestion;

use App\Services\OpenAiRetry;
use OpenAI\Laravel\Facades\OpenAI;

class VisionTranscriber
{
    /**
     * FR-004a/FR-005: розпізнає текстовий вміст зображення (сторінка PDF або
     * зображення, вбудоване в .docx) через vision-модель.
     */
    public function transcribe(string $absoluteImagePath): string
    {
        $base64 = base64_encode(file_get_contents($absoluteImagePath));

        $response = OpenAiRetry::attempt(fn () => OpenAI::chat()->create([
            'model' => config('rag.openai.vision_model'),
            'messages' => [
                [
                    'role' => 'user',
                    'content' => [
                        [
                            'type' => 'text',
                            'text' => 'Transcribe this document page for a knowledge-base search system. Follow these rules exactly:'
                                ."\n\n"
                                .'1. TEXT: Transcribe every piece of written text on the page verbatim — headings, body text, '
                                .'labels, captions, footnotes, numbers, table contents — exactly as written, preserving reading '
                                .'order and structure (use Markdown: # for headings, - for lists, | for tables).'
                                ."\n\n"
                                .'2. VISUALS: For every image, photo, diagram, chart, icon, or illustration, insert a detailed '
                                .'description at the point where it appears on the page, wrapped like this: '
                                .'[VISUAL: describe what is shown, all visible text/labels/values inside it, and what point or '
                                .'information it conveys — do not just name the visual, explain its content in full]. '
                                .'Be exhaustive here: images often carry information not present anywhere in the surrounding text.'
                                ."\n\n"
                                .'3. Do not summarize, paraphrase, shorten, or skip anything. Do not add commentary, preamble, or '
                                .'meta-notes about the task itself — output only the transcription.',
                        ],
                        [
                            'type' => 'image_url',
                            'image_url' => ['url' => "data:image/jpeg;base64,{$base64}"],
                        ],
                    ],
                ],
            ],
        ]));

        return trim($response->choices[0]->message->content ?? '');
    }

    /**
     * Груба евристика: справжній розпізнаний текст зазвичай довший і не
     * складається переважно з типових фраз-відмов OpenAI.
     */
    public function looksLikeRefusal(string $text): bool
    {
        if (mb_strlen($text) > 200) {
            return false;
        }

        return (bool) preg_match(
            '/^(i\'?m (sorry|unable)|i can\'?t|i cannot|i am unable|sorry,)/i',
            trim($text)
        );
    }
}
