<?php

namespace Tests\Unit;

use App\Models\ConversationSession;
use App\Models\Document;
use App\Models\QueryLog;
use App\Services\Conversation\ConversationSessionService;
use App\Services\Rag\AnswerGenerationService;
use App\Services\Rag\QuestionAnsweringService;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class QuestionAnsweringServiceSourcesTest extends TestCase
{
    public function test_sources_treats_legacy_records_without_type_as_document(): void
    {
        Storage::fake('public');

        $document = Document::factory()->create(['original_name' => 'legacy.pdf', 'storage_path' => 'documents/legacy.pdf']);
        $session = ConversationSession::factory()->create();

        // Запис журналу "до цієї фічі" — без поля type у source_document_ids.
        $log = QueryLog::create([
            'conversation_session_id' => $session->id,
            'question' => 'Питання?',
            'answer' => 'Відповідь.',
            'detected_language' => 'uk',
            'answered_in_language' => 'uk',
            'source_document_ids' => [
                ['document_id' => $document->id, 'page_number' => 2, 'relevance' => 80],
            ],
        ]);

        $service = new QuestionAnsweringService(
            $this->createMock(AnswerGenerationService::class),
            $this->createMock(ConversationSessionService::class),
        );

        $sources = $service->sources($log);

        $this->assertCount(1, $sources);
        $this->assertSame('document', $sources[0]['type']);
        $this->assertSame($document->id, $sources[0]['document_id']);
        $this->assertSame(2, $sources[0]['page_number']);
    }
}
