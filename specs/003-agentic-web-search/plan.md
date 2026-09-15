# Implementation Plan: Агентний бот з пошуком в інтернеті та локалізованими промптами

**Branch**: `003-agentic-web-search` | **Date**: 2026-09-13 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/003-agentic-web-search/spec.md`

## Summary

Перетворити наявний детермінований RAG-конвеєр відповіді
(`AnswerGenerationService`: завжди ембединг → векторний пошук → один
виклик LLM) на обмежений агентний цикл виклику інструментів через OpenAI
Responses API: модель самостійно вирішує, чи викликати
`search_knowledge_base` (обгортка над наявними
`EmbeddingService`/`VectorSearchService`) і вбудований інструмент
`web_search` (з фільтром дозволених доменів), у будь-якій комбінації, в
межах обмеженої кількості кроків. Бот отримує чітку вузьку спеціалізацію
(грудні імпланти, компанія Mentor) і інструкцію медичного застереження —
обидві як частину текстових інструкцій. Усі текстові інструкції (наявні й
нові) переносяться з хардкоджених рядків коду в `resources/lang/en/bot.php`
і використовуються через `__()`, мова інструкцій визначається
`config('app.locale')` (за замовчуванням `en`), незалежно від мови
відповіді користувачу (яка й далі визначається автоматично за питанням).

## Technical Context

**Language/Version**: PHP 8.3, Laravel 13.17 (без змін відносно
`001-rag-ai-bot-backend`/`002-word-txt-documents`)

**Primary Dependencies**:
- `openai-php/laravel` (наявний, без оновлення версії) — Responses API
  (`OpenAI::responses()->create()`) замість Chat Completions API для
  генерації відповіді; вбудований інструмент `web_search` з фільтром
  `allowed_domains`; кастомний function-tool `search_knowledge_base`
- Вбудовані Laravel-переклади (`resources/lang/en/bot.php`, `__()`) —
  локалізація промптів (без нових пакетів)
- Наявні `EmbeddingService`, `VectorSearchService`, `OpenAiRetry` —
  без зміни внутрішньої логіки, лише нова точка виклику (з функції-
  інструмента замість прямого виклику)

**Storage**: PostgreSQL з pgvector; `query_logs.source_document_ids`
розширюється новим полем `type` у межах наявного JSON-стовпця (без
міграції); додається одна нова таблиця `agent_tool_steps` (з міграцією,
зворотною через `down()`) для структурованого журналу кроків агента
(FR-013, див. `data-model.md`)

**Testing**: PHPUnit — Feature-тести на `POST /api/v1/queries` для кожного
User Story (веб-джерело в sources, комбінація БЗ+веб, allow-list доменів,
відмова поза спеціалізацією, медичне застереження, ліміт кроків), Unit-
тести на новий tool-calling цикл (мок Responses API через
`OpenAI::fake()`) і на парсинг конфігурації allow-list доменів

**Target Platform**: те саме середовище (Laravel Sail)

**Project Type**: web-service (без змін структури)

**Performance Goals**: відповідь на питання, що вимагає інструментів,
залишається в межах прийнятного часу синхронного HTTP-запиту — обмежено
FR-006 (`RAG_AGENT_MAX_STEPS`, за замовчуванням 4 кроки)

**Constraints**: той самий rate limit токена (FR-013a з
`001-rag-ai-bot-backend`); ліміт кроків агента як технічний запобіжник
(FR-006); allow-list доменів вебпошуку — конфігурація, не окремий UI
(Assumptions spec.md)

**Scale/Scope**: не змінює масштаб попередніх фіч; додає нову зовнішню
залежність часу виконання — виклик `web_search` (мережевий запит через
OpenAI, без нового постачальника)

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

| Принцип конституції | Перевірка | Статус |
|---|---|---|
| I. Українська мова спілкування | Уся плановна документація й подальші комунікації — українською | PASS |
| II. Test-First (NON-NEGOTIABLE) | Кожен новий FR отримає Feature/Unit-тест до або одночасно з реалізацією — деталі в `tasks.md` | PASS (буде забезпечено на етапі `/speckit-tasks`) |
| III. Simplicity (YAGNI) | Веб-пошук через уже встановлений `openai-php` (без нового постачальника/пакета); локалізація через вбудований Laravel `__()` (без кастомної інфраструктури); RAG-пошук стає інструментом без зміни `EmbeddingService`/`VectorSearchService`; нова таблиця `agent_tool_steps` — мінімальна, лише для явної вимоги FR-013 (структуроване логування кроків), а не передчасна абстракція | PASS |
| IV. Observability | Кожен крок циклу інструментів записується структуровано в `agent_tool_steps` (FR-013) і додатково логується через `Log::channel('rag')` для технічної діагностики — обидва механізми доповнюють, а не дублюють один одного | PASS (буде реалізовано, деталі — в tasks.md) |
| V. Versioning & Breaking Changes | Контракт `POST/GET /api/v1/queries` розширюється зворотно сумісно (новий необов'язковий `type` у `sources[]`, задокументовано як delta в `contracts/api.md`); нова таблиця `agent_tool_steps` додається через міграцію зі зворотним `down()`, не зачіпає наявні таблиці | PASS |

Порушень немає, `Complexity Tracking` не заповнюється.

**Повторна перевірка після Phase 1 (data-model.md, contracts/,
quickstart.md)**: дизайн підтвердив відсутність нових зовнішніх пакетів;
додано одну нову таблицю (`agent_tool_steps`) за прямим запитом
користувача щодо структурованого логування кроків агента (FR-013) —
мінімальна, ізольована зміна схеми зі зворотною міграцією, що не
суперечить Принципу III. Єдина суттєва архітектурна зміна поза цим — це
перехід `AnswerGenerationService` з Chat Completions на Responses API з
tool-calling, що є заміною виклику в межах уже використовуваного SDK, а
не новою залежністю. Усі принципи залишаються PASS.

## Project Structure

### Documentation (this feature)

```text
specs/003-agentic-web-search/
├── plan.md              # Цей файл (/speckit-plan)
├── research.md          # Phase 0 output
├── data-model.md        # Phase 1 output
├── quickstart.md        # Phase 1 output
├── contracts/
│   └── api.md           # Phase 1 output — delta до контракту 001-rag-ai-bot-backend
├── checklists/
│   └── requirements.md
└── tasks.md              # Phase 2 output (/speckit-tasks, ще не створено)
```

### Source Code (repository root)

Існуючий Laravel-моноліт — нові/змінювані файли в наявній структурі:

```text
app/
├── Models/
│   └── AgentToolStep.php                   # НОВИЙ — belongsTo QueryLog (FR-013)
├── Services/
│   └── Rag/
│       ├── AnswerGenerationService.php    # ІСТОТНО ЗМІНЮЄТЬСЯ: Chat Completions → Responses API + tool-calling цикл (FR-001-FR-008f)
│       ├── AgentToolRunner.php            # НОВИЙ — виконує обмежений цикл: викликає модель, розпізнає tool_calls, виконує search_knowledge_base, збирає web-джерела з annotations, зупиняється на RAG_AGENT_MAX_STEPS (FR-006); записує кожен крок у AgentToolStep (FR-013)
│       ├── KnowledgeBaseSearchTool.php    # НОВИЙ — function-tool wrapper над EmbeddingService+VectorSearchService (схема параметрів для Responses API)
│       ├── QueryRewriter.php              # промпт переноситься в bot.php (FR-009), логіка без змін
│       ├── EmbeddingService.php           # без змін
│       └── VectorSearchService.php        # без змін
├── Jobs/                                   # без змін (ця фіча не чіпає ingestion)
└── Http/Controllers/Api/
    └── QueryController.php                 # без змін контракту запиту; трансформація відповіді враховує sources[].type

resources/
└── lang/
    └── en/
        └── bot.php                          # НОВИЙ — усі текстові інструкції бота (FR-009-FR-012)

config/
└── rag.php                                  # розширюється: agent.max_tool_steps, web_search.allowed_domains

database/migrations/
└── xxxx_create_agent_tool_steps_table.php   # НОВИЙ — структурований журнал кроків агента (FR-013), зі зворотним down()

tests/
├── Feature/
│   └── Query/
│       ├── WebSearchSourceTest.php          # НОВИЙ — US1
│       ├── AllowedDomainsTest.php           # НОВИЙ — US1a
│       ├── OutOfScopeQuestionTest.php       # НОВИЙ — US1b
│       ├── MedicalDisclaimerTest.php        # НОВИЙ — US1c
│       ├── CombinedSourcesTest.php          # НОВИЙ — US2
│       ├── AgentStepLimitTest.php           # НОВИЙ — FR-006
│       └── AgentToolStepLogTest.php         # НОВИЙ — FR-013
└── Unit/
    ├── AgentToolRunnerTest.php               # НОВИЙ
    └── KnowledgeBaseSearchToolTest.php       # НОВИЙ
```

**Structure Decision**: Розширення наявного `app/Services/Rag/` без нових
проєктів/модулів — новий агентний цикл додається як два нові класи поруч
із наявними сервісами RAG, дотримуючись уже встановленого патерну "один
клас — одна відповідальність". Локалізація використовує стандартну
Laravel-директорію `resources/lang/`, яка в проєкті ще не використовувалась
(створюється вперше цією фічею). Відповідає Принципу III й наявній
структурі проєкту.

## Complexity Tracking

*Порушень Constitution Check немає — розділ не заповнюється.*
