<?php

namespace App\Services\DocumentIngestion;

use App\Services\OpenAiRetry;
use OpenAI\Laravel\Facades\OpenAI;
use Spatie\PdfToImage\Enums\OutputFormat;
use Spatie\PdfToImage\Pdf;

class PdfVisionExtractor
{
    /**
     * FR-005: розпізнає текстовий вміст сторінки PDF (переважно зображення/скан)
     * через vision-модель, коли текстовий шар відсутній або недостатній.
     */
    public function extractPageText(string $absolutePath, int $pageNumber): string
    {
        $tempImage = tempnam(sys_get_temp_dir(), 'rag_page_').'.jpg';

        try {
            (new Pdf($absolutePath))
                ->format(OutputFormat::Jpg)
                ->selectPages($pageNumber)
                ->save($tempImage);

            $base64 = base64_encode(file_get_contents($tempImage));

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
        } finally {
            if (file_exists($tempImage)) {
                unlink($tempImage);
            }
        }
    }
}
