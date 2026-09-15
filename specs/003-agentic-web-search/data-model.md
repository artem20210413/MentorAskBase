# Data Model: Агентний бот з пошуком в інтернеті та локалізованими промптами

Ця фіча розширює внутрішнє представлення "джерела відповіді", додає
конфігурацію й текстові ресурси локалізації, а також **одну нову
таблицю** для структурованого журналу кроків агента (FR-013, рішення
прийняте після початкового Phase 1 — див. `AgentToolStep` нижче).
Розширюються сутності `QueryLog` (з
`specs/001-rag-ai-bot-backend/data-model.md`) і сервісний шар RAG.

## QueryLog (без зміни схеми БД)

`source_document_ids` (JSON-масив, уже існує) — кожен елемент тепер має
поле `type`:

- **`type: "document"`** (як і зараз) — `document_id`, `page_number`
  (nullable), `relevance` (0-100).
- **`type: "web"`** (нове) — `url`, `title`, `relevance` (nullable, якщо
  модель пошуку не повертає оцінку релевантності).

Записи журналу, створені до цієї фічі, не мають поля `type` — при
читанні (`QuestionAnsweringService::sources()`) відсутність `type`
трактується як `"document"` для зворотної сумісності.

## Нормалізоване джерело відповіді (Answer Source) — не БД-сутність

Внутрішнє представлення, що формується в `AnswerGenerationService` після
завершення циклу інструментів, перш ніж повернутися до
`QuestionAnsweringService`:

```text
{
  type: "document" | "web",
  // для type=document:
  document_id?: string,
  page_number?: int|null,
  // для type=web:
  url?: string,
  title?: string,
  // спільне:
  relevance?: int|null,
}
```

Це замінює поточне жорстке `Collection<DocumentChunk>` у сигнатурі
`AnswerGenerationService::answer()` — повертається вже нормалізований
масив джерел обох типів, а не лише чанки документів.

## AgentToolStep (НОВА таблиця, FR-013)

Структурований запис одного кроку циклу інструментів — на відміну від
`source_document_ids` (лише фінальні джерела фінальної відповіді),
фіксує **кожен** виклик інструмента під час обробки питання, незалежно
від того, чи потрапив він зрештою у фінальні джерела.

```text
Schema::create('agent_tool_steps', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->uuid('query_log_id');
    $table->unsignedInteger('step_number');          // порядок кроку в межах одного питання (0-based)
    $table->enum('tool', ['search_knowledge_base', 'web_search']);
    $table->text('input');                            // запит, переданий інструменту (пошуковий текст)
    $table->text('output')->nullable();                // отриманий результат (стислий підсумок/сніпет; null при збої)
    $table->timestamp('created_at')->useCurrent();

    $table->foreign('query_log_id')->references('id')->on('query_logs')->cascadeOnDelete();
    $table->index('query_log_id');
});
```

- `query_log_id` — зв'язок `AgentToolStep belongsTo QueryLog` /
  `QueryLog hasMany AgentToolStep`.
- `output` — навмисно `nullable`: якщо виклик інструмента завершився
  збоєм (наприклад, недоступність `web_search`, FR-008), крок усе одно
  записується (`output = null`), щоб було видно, що спроба була, але не
  вдалася.
- Записується `AgentToolRunner` одразу після виконання кожного
  інструмента (у межах того самого запиту, синхронно — без черги),
  окремо від диагностичного `Log::channel('rag')`, який лишається для
  технічної діагностики помилок (`down()` — просте `dropIfExists`,
  зворотна міграція, Принцип V).

## Нова конфігурація (`config/rag.php`)

- **`agent.max_tool_steps`** (int, `env('RAG_AGENT_MAX_STEPS', 4)`) —
  FR-006, ліміт кроків циклу виклику інструментів на одне питання.
- **`web_search.allowed_domains`** (array доменів,
  `env('RAG_WEB_SEARCH_ALLOWED_DOMAINS', '')`, парситься як
  comma-separated список, порожній рядок → порожній масив) — FR-008a/b;
  порожній масив передається у виклик `web_search` без фільтра доменів
  (необмежений пошук).

## Нові текстові ресурси (`resources/lang/en/bot.php`)

Не БД-сутність, але ключова частина моделі даних цієї фічі — файл
повертає асоціативний масив із ключами (орієнтовний перелік, остаточні
формулювання — на етапі реалізації):

- `system_identity` — визначення "хто я" (спеціалізація на грудних
  імплантах/Mentor, вузькі межі) — FR-008c/FR-008d.
- `medical_disclaimer_instruction` — коли й як додавати застереження про
  звернення до лікаря — FR-008e/FR-008f.
- `answer_style` — наявний стиль відповіді (дружній, розмовний тощо, з
  поточного системного промпту `AnswerGenerationService`).
- `no_relevant_info_marker` — внутрішній маркер відсутності інформації
  (наявний `NO_INFO_MARKER`).
- `query_rewrite_instruction` — наявна інструкція `QueryRewriter`.
- `knowledge_base_tool_description` / `web_search_tool_description` —
  описи інструментів, що передаються в Responses API для того, щоб
  модель розуміла, коли який інструмент викликати (FR-001, FR-002,
  FR-004, FR-005).

## Незмінні сутності

`Document`, `DocumentChunk`, `ConversationSession`, `AnswerFeedback` —
без змін. `EmbeddingService`, `VectorSearchService` — без змін внутрішньої
логіки, лише новий виклик через функцію-інструмент замість прямого виклику
з `AnswerGenerationService::answer()`.
