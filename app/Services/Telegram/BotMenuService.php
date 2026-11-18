<?php

namespace App\Services\Telegram;

use App\Models\Document;
use App\Models\User;
use App\Services\Rag\QuestionAnsweringService;
use Illuminate\Support\Facades\Storage;

/**
 * FR-010/FR-011/FR-012/FR-012a/FR-012b: головне меню, мова, список
 * документів з пагінацією — усе локалізоване за `users.language`.
 */
class BotMenuService
{
    /** @var array<string, string> label (рідною мовою, завжди незмінний) => locale */
    public const LANGUAGES = [
        'Українська' => 'uk',
        'Русский' => 'ru',
        'English' => 'en',
    ];

    public function __construct(
        private TelegramClient $telegram,
        private DocumentSummaryService $summaries,
        private QuestionAnsweringService $questionAnswering,
    ) {}

    public function sendMainMenu(int $chatId, string $locale): void
    {
        $this->telegram->sendMessage($chatId, __('telegram.menu_title', [], $locale), [
            'keyboard' => [
                [$this->btnLanguage($locale), $this->btnHelp($locale)],
                [$this->btnDocs($locale), $this->btnDocInfo($locale)],
            ],
            'resize_keyboard' => true,
        ]);
    }

    /**
     * Повертає true, якщо текст розпізнано і обробку завершено.
     */
    public function handle(User $user, string $text): bool
    {
        $locale = $user->language;
        $chatId = (int) $user->telegram_chat_id;

        if (in_array($text, ['/start', '/menu'], true)) {
            $this->sendMainMenu($chatId, $locale);

            return true;
        }

        if ($text === $this->btnLanguage($locale)) {
            $this->telegram->sendMessage($chatId, __('telegram.choose_language', [], $locale), [
                'keyboard' => [array_keys(self::LANGUAGES)],
                'resize_keyboard' => true,
            ]);

            return true;
        }

        if (array_key_exists($text, self::LANGUAGES)) {
            $newLocale = self::LANGUAGES[$text];
            $user->update(['language' => $newLocale]);
            $this->telegram->sendMessage($chatId, __('telegram.language_saved', [], $newLocale));
            $this->sendMainMenu($chatId, $newLocale);

            return true;
        }

        if ($text === $this->btnHelp($locale)) {
            $this->telegram->sendMessage($chatId, __('telegram.help_text', [], $locale));

            return true;
        }

        if ($text === $this->btnDocs($locale)) {
            $this->sendDocumentsPage($chatId, 1, $locale);

            return true;
        }

        if ($text === $this->btnDocInfo($locale)) {
            $this->telegram->sendMessage($chatId, __('telegram.doc_info_prompt', [], $locale));

            return true;
        }

        if (ctype_digit($text)) {
            $this->sendDocumentSummary($user, (int) $text);

            return true;
        }

        return false;
    }

    public function sendDocumentsPage(int $chatId, int $page, string $locale): void
    {
        [$text, $replyMarkup] = $this->buildDocumentsPage($page, $locale);

        $this->telegram->sendMessage($chatId, $text, $replyMarkup);
    }

    public function editDocumentsPage(int $chatId, int $messageId, int $page, string $locale): void
    {
        [$text, $replyMarkup] = $this->buildDocumentsPage($page, $locale);

        $this->telegram->editMessageText($chatId, $messageId, $text, $replyMarkup);
    }

    /**
     * @return array{0: string, 1: ?array}
     */
    private function buildDocumentsPage(int $page, string $locale): array
    {
        $pageSize = (int) config('services.telegram.docs_page_size');
        $total = Document::count();
        $documents = Document::orderBy('created_at')
            ->skip(($page - 1) * $pageSize)
            ->take($pageSize)
            ->get();

        if ($documents->isEmpty()) {
            return [__('telegram.docs_empty', [], $locale), null];
        }

        $lines = [];
        foreach ($documents as $index => $document) {
            $number = ($page - 1) * $pageSize + $index + 1;
            $url = Storage::disk(config('rag.document_disk'))->url($document->storage_path);
            $lines[] = "{$number}. {$document->original_name} — {$url}";
        }

        $buttons = [];
        if ($page > 1) {
            $buttons[] = ['text' => __('telegram.docs_nav_back', [], $locale), 'callback_data' => 'docs_page:'.($page - 1)];
        }
        if ($page * $pageSize < $total) {
            $buttons[] = ['text' => __('telegram.docs_nav_next', [], $locale), 'callback_data' => 'docs_page:'.($page + 1)];
        }

        return [implode("\n", $lines), $buttons ? ['inline_keyboard' => [$buttons]] : null];
    }

    private function sendDocumentSummary(User $user, int $number): void
    {
        $document = Document::orderBy('created_at')->skip($number - 1)->take(1)->first();

        if (! $document) {
            $this->telegram->sendMessage((int) $user->telegram_chat_id, __('telegram.doc_not_found', [], $user->language));

            return;
        }

        $summary = $this->summaries->summaryFor($document, $user->language);

        $this->telegram->sendMessage(
            (int) $user->telegram_chat_id,
            $summary ?? __('telegram.doc_summary_unavailable', [], $user->language),
        );

        if ($summary !== null) {
            $this->rememberInConversation($user, $document, $summary);
        }
    }

    /**
     * Резюме стає частиною розмови: уточнення на кшталт «а що там описано?»
     * у вільному чаті розуміються в контексті цього документа, а пошук у базі
     * знань переформульовується з урахуванням його назви.
     */
    private function rememberInConversation(User $user, Document $document, string $summary): void
    {
        $log = $this->questionAnswering->recordExchange(
            __('telegram.doc_summary_question', ['name' => $document->original_name], $user->language),
            $summary,
            $user->language,
            $user->conversation_session_id,
            $document,
        );

        if ($user->conversation_session_id !== $log->conversation_session_id) {
            $user->update(['conversation_session_id' => $log->conversation_session_id]);
        }
    }

    private function btnLanguage(string $locale): string
    {
        return __('telegram.btn_menu_language', [], $locale);
    }

    private function btnHelp(string $locale): string
    {
        return __('telegram.btn_menu_help', [], $locale);
    }

    private function btnDocs(string $locale): string
    {
        return __('telegram.btn_menu_docs', [], $locale);
    }

    private function btnDocInfo(string $locale): string
    {
        return __('telegram.btn_menu_doc_info', [], $locale);
    }
}
