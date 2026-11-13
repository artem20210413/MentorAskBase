<?php

namespace Tests\Feature\Telegram;

use App\Models\Document;
use App\Models\DocumentSummary;
use App\Models\User;
use App\Services\Telegram\TelegramClient;
use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Resources\Chat;
use OpenAI\Resources\Responses;
use OpenAI\Responses\Chat\CreateResponse as ChatCreateResponse;
use Tests\Support\FakesAgentResponses;
use Tests\Support\FakeTelegramClient;
use Tests\Support\PostsTelegramUpdates;
use Tests\TestCase;

/**
 * Резюме документа з меню бота — частина розмови: уточнення у вільному чаті
 * ("а що там описано?") мають розумітися в контексті цього документа.
 */
class DocumentSummaryContextTest extends TestCase
{
    use FakesAgentResponses, PostsTelegramUpdates;

    public function test_summary_is_recorded_in_users_conversation_session(): void
    {
        $user = User::factory()->create(['telegram_chat_id' => 940, 'language' => 'uk', 'conversation_session_id' => null]);
        $document = Document::factory()->create(['original_name' => 'Buccheri Blueprint.pdf']);
        DocumentSummary::create(['document_id' => $document->id, 'locale' => 'uk', 'summary' => 'Дослідження імплантів CPG.']);

        $this->app->instance(TelegramClient::class, new FakeTelegramClient);

        $this->postTelegramUpdate($this->telegramMessage(940, 940, '1'))->assertOk();

        $user->refresh();
        $this->assertNotNull($user->conversation_session_id);
        $this->assertDatabaseHas('query_logs', [
            'conversation_session_id' => $user->conversation_session_id,
            'question' => 'Розкажи про документ «Buccheri Blueprint.pdf»',
            'answer' => 'Дослідження імплантів CPG.',
        ]);
    }

    public function test_follow_up_question_sees_summary_in_history(): void
    {
        User::factory()->create(['telegram_chat_id' => 941, 'language' => 'uk', 'conversation_session_id' => null]);
        $document = Document::factory()->create(['original_name' => 'Buccheri Blueprint.pdf']);
        DocumentSummary::create(['document_id' => $document->id, 'locale' => 'uk', 'summary' => 'Дослідження імплантів CPG.']);

        OpenAI::fake([
            // переформулювання уточнення з урахуванням історії
            ChatCreateResponse::fake(['choices' => [['message' => ['role' => 'assistant', 'content' => 'Що описано в документі Buccheri Blueprint?']]]]),
            $this->fakeFinalAnswer('У документі описано…'),
        ]);

        $telegram = new FakeTelegramClient;
        $this->app->instance(TelegramClient::class, $telegram);

        $this->postTelegramUpdate($this->telegramMessage(941, 941, '1'))->assertOk();
        $this->postTelegramUpdate($this->telegramMessage(941, 941, 'А что там описано?'))->assertOk();

        OpenAI::assertSent(Chat::class, fn (string $method, array $params) => str_contains(json_encode($params['messages'], JSON_UNESCAPED_UNICODE), 'Buccheri Blueprint.pdf'));

        OpenAI::assertSent(Responses::class, function (string $method, array $params) {
            $input = json_encode($params['input'], JSON_UNESCAPED_UNICODE);

            return str_contains($input, 'Дослідження імплантів CPG.')
                && str_contains($input, 'Що описано в документі Buccheri Blueprint?');
        });

        $this->assertSame('У документі описано…', last($telegram->texts()));
    }
}
