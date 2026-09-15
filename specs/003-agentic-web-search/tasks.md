# Tasks: Агентний бот з пошуком в інтернеті та локалізованими промптами

**Input**: Design documents from `/specs/003-agentic-web-search/`

**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/api.md, quickstart.md

**Tests**: Конституція проєкту (Принцип II, Test-First, NON-NEGOTIABLE) вимагає тестів для кожної бізнес-логіки — тестові завдання включені й обов'язкові, а не опційні.

**Organization**: Завдання згруповано за користувацькими історіями зі spec.md: US1 (P1, веб-пошук за потреби), US1a (P1, allow-list доменів), US1b (P1, вузька спеціалізація), US1c (P1, медичне застереження), US2 (P2, комбінування джерел), US3 (P3, локалізація промптів).

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Можна виконувати паралельно (різні файли, без залежностей)
- **[Story]**: US1/US1a/US1b/US1c/US2/US3 — відповідність користувацькій історії зі spec.md
- Точні шляхи файлів вказані в кожному завданні

## Path Conventions

Існуючий Laravel-моноліт — `app/`, `resources/`, `config/`, `tests/` у корені репозиторію.

---

## Phase 1: Setup

**Purpose**: Конфігурація й базова структура локалізації, спільні для всіх історій

- [X] T001 [P] Додати до `config/rag.php` секцію `'agent' => ['max_tool_steps' => (int) env('RAG_AGENT_MAX_STEPS', 4)]` (FR-006)
- [X] T002 [P] Додати до `config/rag.php` секцію `'web_search' => ['allowed_domains' => array_filter(array_map('trim', explode(',', env('RAG_WEB_SEARCH_ALLOWED_DOMAINS', ''))))]` (FR-008a)
- [X] T003 [P] Додати `RAG_AGENT_MAX_STEPS=4` і `RAG_WEB_SEARCH_ALLOWED_DOMAINS=` у `.env.example` з коментарем про формат (comma-separated домени, порожньо = без обмежень)
- [X] T004 [P] Створити `resources/lang/en/bot.php`, що повертає масив із ключами `answer_style` (наявний стиль відповіді з поточного системного промпту `AnswerGenerationService`), `no_relevant_info_marker` (наявний `NO_INFO_MARKER`), `query_rewrite_instruction` (наявний системний промпт `QueryRewriter`) — перенесення наявного тексту без змістовних змін (FR-009)

**Checkpoint**: Конфігурація й базовий файл локалізації готові для використання у Foundational-фазі

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: Побудова агентного циклу викликів інструментів — центральний механізм, від якого залежать усі користувацькі історії

**⚠️ CRITICAL**: Жодна з користувацьких історій не починається, доки ця фаза не завершена

### Тести для Foundational ⚠️

> Написати ці тести ПЕРШИМИ, переконатися що вони падають (Red) до реалізації

- [X] T005 [P] Unit-тест `KnowledgeBaseSearchTool` (схема інструмента, `execute()` повертає нормалізовані `document`-джерела на основі `VectorSearchService`) у `tests/Unit/KnowledgeBaseSearchToolTest.php`
- [X] T006 [P] Unit-тест `AgentToolRunner`: цикл викликає `OpenAI::responses()->create()` (мок через `OpenAI::fake()`), розпізнає `tool_calls`, виконує `KnowledgeBaseSearchTool`, збирає `web`-джерела з `annotations` фінальної відповіді, зупиняється після `config('rag.agent.max_tool_steps')` кроків у `tests/Unit/AgentToolRunnerTest.php`
- [X] T006a [P] Unit-тест: `AgentToolRunner` при винятку під час виклику `web_search` (мережевий збій/недоступність) перехоплює помилку, продовжує цикл лише з `search_knowledge_base`, і повертає відповідь на основі вже зібраних `document`-джерел замість провалу (FR-008) у `tests/Unit/AgentToolRunnerTest.php`
- [X] T006b [P] Unit-тест: `QuestionAnsweringService::sources()` для `QueryLog` зі "старим" (до цієї фічі) форматом `source_document_ids`, де немає поля `type`, повертає такі елементи як `type: "document"` (зворотна сумісність, `data-model.md`) у `tests/Unit/QuestionAnsweringServiceSourcesTest.php`
- [X] T006c [P] Unit-тест: `AgentToolRunner` повертає в результаті масив `tool_steps` із записом кожного виконаного кроку (`step_number`, `tool`, `input`, `output`) — включно з кроком, де `output = null` через збій інструмента (FR-013) у `tests/Unit/AgentToolRunnerTest.php`

### Реалізація Foundational

- [X] T006d Створити міграцію `database/migrations/xxxx_create_agent_tool_steps_table.php` — таблиця `agent_tool_steps` (`id` uuid PK, `query_log_id` uuid FK на `query_logs` з `cascadeOnDelete()`, `step_number` unsignedInteger, `tool` enum `['search_knowledge_base', 'web_search']`, `input` text, `output` text nullable, `created_at`), індекс на `query_log_id`, зворотний `down()` (FR-013, `data-model.md`)
- [X] T006e [P] Створити модель `app/Models/AgentToolStep.php` (`belongsTo(QueryLog::class)`, `$fillable`, без `updated_at`) і зв'язок `QueryLog::toolSteps(): HasMany` у `app/Models/QueryLog.php`
- [X] T007 Створити `app/Services/Rag/KnowledgeBaseSearchTool.php` — обгортка над наявними `EmbeddingService`+`VectorSearchService`: метод `schema(): array` (опис інструмента й параметрів для Responses API, текст опису з `__('bot.knowledge_base_tool_description')`) та `execute(string $query): array` (повертає масив нормалізованих `document`-джерел, як описано в `data-model.md`)
- [X] T008 Додати ключ `knowledge_base_tool_description` і `web_search_tool_description` у `resources/lang/en/bot.php` (короткі інструкції для моделі, коли викликати який інструмент — FR-001, FR-002, FR-005)
- [X] T009 Створити `app/Services/Rag/AgentToolRunner.php` — обмежений цикл: викликає `OpenAI::responses()->create()` з інструментами `web_search` (з `filters.allowed_domains` із `config('rag.web_search.allowed_domains')`, якщо непорожній — FR-008a/b) та кастомним `search_knowledge_base` (через `KnowledgeBaseSearchTool`); на кожен `tool_calls` виконує відповідний інструмент і накопичує в пам'яті запис кроку (`step_number`, `tool`, `input`, `output`) — **не пише в БД напряму** (у момент виконання циклу `QueryLog` для цього питання ще не створено, див. T011); перехоплює виняток виклику `web_search`, фіксує крок з `output = null` і в такому разі продовжує роботу лише з `search_knowledge_base`-джерелами замість провалу (FR-008); зупиняється після `config('rag.agent.max_tool_steps')` кроків, повертаючи найкращу доступну відповідь (FR-006); логує кожен крок через `Log::channel('rag')` (Принцип IV); повертає `{answer: string, sources: array, tool_steps: array}` — джерела обох типів і зібрані кроки (`data-model.md`)
- [X] T010 Рефакторити `app/Services/Rag/AnswerGenerationService.php::answer()` — замінити прямий виклик `EmbeddingService`+`VectorSearchService`+`OpenAI::chat()` на виклик `AgentToolRunner`; зберегти визначення мови (`LanguageDetectionService`), переформулювання питання (`QueryRewriter`), обробку `NO_INFO_MARKER` і семантику однієї повторної спроби (FR-009c) навколо виклику `AgentToolRunner`; системний промпт формується з `__('bot.answer_style')` замість хардкодженого рядка; повертає `tool_steps` разом із рештою результату (пробрасує з `AgentToolRunner`)
- [X] T011 Оновити `app/Services/Rag/QuestionAnsweringService.php::ask()`/`sourceReferences()`/`sources()` — приймати нормалізований масив джерел з полем `type` (`document`/`web`) замість `Collection<DocumentChunk>`; зберігати в `query_logs.source_document_ids` з полем `type`; при читанні застарілих записів без `type` трактувати як `document` (зворотна сумісність, `data-model.md`); одразу після створення `QueryLog` — записати накопичені `tool_steps` через `$log->toolSteps()->createMany(...)` (FR-013)
- [X] T012 Перевірити `app/Http/Controllers/Api/QueryController.php` — підтвердити, що відповідь `sources[]` коректно передає нове поле `type` без додаткових змін контролера (delta-контракт `contracts/api.md`); за потреби скоригувати
- [X] T012a Feature-тест: після `POST /api/v1/queries`, що викликало хоча б один інструмент, `QueryLog::find($query_log_id)->toolSteps` містить записи з `tool`/`input`/`output` для кожного виконаного кроку, доступні через Eloquent (FR-013, SC-009) у `tests/Feature/Query/AgentToolStepLogTest.php`

**Checkpoint**: Агентний цикл працює, RAG — інструмент за вибором моделі, веб-пошук доступний з фільтром доменів, джерела типізовані, кроки роздумів структуровано записуються — усі користувацькі історії можуть починатися

---

## Phase 3: User Story 1 - Бот самостійно вирішує, коли шукати в інтернеті (Priority: P1) 🎯 MVP

**Goal**: Питання без відповіді в базі знань отримують змістовну відповідь на основі пошуку в інтернеті; питання, покриті базою знань, не викликають зайвого пошуку.

**Independent Test**: Поставити питання поза базою знань — переконатися, що `sources` містить елемент `type: web`. Поставити питання, повністю покрите базою знань — переконатися, що веб-пошук не викликався.

### Tests for User Story 1 ⚠️

- [X] T013 [P] [US1] Feature-тест: питання без релевантних документів → відповідь містить `sources` з елементом `type: web` і посиланням, `OpenAI::assertSent` підтверджує виклик з інструментом `web_search` у `tests/Feature/Query/WebSearchSourceTest.php`
- [X] T014 [P] [US1] Feature-тест: питання, повністю покрите базою знань → `sources` містить лише `type: document`, інструмент `web_search` не викликається (перевірка через мок) у `tests/Feature/Query/WebSearchSourceTest.php`
- [X] T015 [P] [US1] Feature-тест: пошук в інтернеті не дав результатів → чесна відповідь "не знаю" (наявний `NO_INFO_MARKER`-механізм), а не вигадка, у `tests/Feature/Query/WebSearchSourceTest.php`

### Implementation for User Story 1

- [X] T016 [US1] Уточнити формулювання `web_search_tool_description`/`knowledge_base_tool_description` у `resources/lang/en/bot.php` за результатами тестів T013-T015, щоб модель надійно викликала `web_search` лише за відсутності релевантних `document`-джерел (тюнінг промпту, FR-001, FR-005)

**Checkpoint**: US1 повністю функціональна й тестована незалежно — MVP готовий

---

## Phase 4: User Story 1a - Пошук в інтернеті обмежується довіреними сайтами (Priority: P1)

**Goal**: Коли оператор задає непорожній перелік дозволених доменів, веб-пошук використовує лише їх; порожній перелік — необмежений пошук.

**Independent Test**: Встановити `RAG_WEB_SEARCH_ALLOWED_DOMAINS`, поставити питання, що вимагає пошуку — переконатися, що виклик `web_search` містить `filters.allowed_domains`, які відповідають конфігурації.

### Tests for User Story 1a ⚠️

- [X] T017 [P] [US1a] Unit-тест: парсинг `RAG_WEB_SEARCH_ALLOWED_DOMAINS` — непорожній comma-separated рядок → масив доменів; порожній рядок → порожній масив у `tests/Unit/RagConfigTest.php`
- [X] T018 [P] [US1a] Feature-тест: з непорожнім `config('rag.web_search.allowed_domains')` виклик `web_search` в `OpenAI::responses()->create()` містить `filters.allowed_domains`, що збігається з конфігурацією, у `tests/Feature/Query/AllowedDomainsTest.php`
- [X] T019 [P] [US1a] Feature-тест: з порожнім переліком (за замовчуванням) виклик `web_search` не містить обмеження `filters.allowed_domains` у `tests/Feature/Query/AllowedDomainsTest.php`

### Implementation for User Story 1a

- [X] T020 [US1a] Підтвердити (за потреби — виправити) в `app/Services/Rag/AgentToolRunner.php`, що `filters.allowed_domains` додається до визначення інструмента `web_search` лише коли `config('rag.web_search.allowed_domains')` непорожній (FR-008a/b) — за результатами T017-T019

**Checkpoint**: US1a повністю функціональна — allow-list доменів працює незалежно

---

## Phase 5: User Story 1b - Бот дотримується своєї спеціалізації (Priority: P1)

**Goal**: Питання поза вузькою спеціалізацією бота (не про грудні імпланти чи Mentor) отримують чесну відмову замість пошуку в інтернеті на довільну тему.

**Independent Test**: Поставити питання, не пов'язане з імплантами/Mentor (наприклад, рецепт борщу) — переконатися, що відповідь повідомляє про межі спеціалізації, `sources` порожній, `web_search` не викликається.

### Tests for User Story 1b ⚠️

- [X] T021 [P] [US1b] Feature-тест: питання поза спеціалізацією (наприклад, "рецепт борщу") → відповідь повідомляє про межі компетенції, `sources` — порожній масив, `web_search` не викликається у `tests/Feature/Query/OutOfScopeQuestionTest.php`
- [X] T022 [P] [US1b] Feature-тест: питання про суміжну тему (загальна пластична хірургія, не Mentor) → так само трактується як поза межами (вузьке трактування) у `tests/Feature/Query/OutOfScopeQuestionTest.php`
- [X] T023 [P] [US1b] Feature-тест: питання про грудні імпланти/Mentor, що вимагає пошуку → обробляється нормально (регрес-перевірка, що звуження спеціалізації не зламало US1) у `tests/Feature/Query/OutOfScopeQuestionTest.php`

### Implementation for User Story 1b

- [X] T024 [US1b] Додати ключ `system_identity` у `resources/lang/en/bot.php` — вузьке визначення спеціалізації бота (грудні імпланти, компанія Mentor; суміжні теми поза межами) з чіткою інструкцією чесно відмовляти на сторонні питання без виклику інструментів (FR-008c/FR-008d)
- [X] T025 [US1b] Підключити `__('bot.system_identity')` до побудови системного промпту в `app/Services/Rag/AnswerGenerationService.php` (перед описом інструментів/контексту)

**Checkpoint**: US1b повністю функціональна — бот відмовляється від сторонніх тем незалежно

---

## Phase 6: User Story 1c - Бот застерігає, що він не заміна лікаря (Priority: P1)

**Goal**: Серйозні/особисті медичні питання супроводжуються нагадуванням, що бот — ШІ-асистент, з порадою звернутися до лікаря; прості фактологічні питання — без нав'язливого застереження.

**Independent Test**: Поставити серйозне медичне питання (особисті ризики) — переконатися, що відповідь містить застереження. Поставити просте фактологічне питання — переконатися, що застереження відсутнє чи мінімальне.

### Tests for User Story 1c ⚠️

- [X] T026 [P] [US1c] Feature-тест: серйозне/особисте медичне питання (наприклад, "чи підходить мені операція при моєму стані здоров'я?") → відповідь містить нагадування про ШІ-асистента й пораду звернутися до лікаря у `tests/Feature/Query/MedicalDisclaimerTest.php`
- [X] T027 [P] [US1c] Feature-тест: просте фактологічне питання (наприклад, "з чого виготовлені імпланти MemoryGel?") → відповідь не містить нав'язливого застереження на кожне речення у `tests/Feature/Query/MedicalDisclaimerTest.php`

### Implementation for User Story 1c

- [X] T028 [US1c] Додати ключ `medical_disclaimer_instruction` у `resources/lang/en/bot.php` — інструкція для моделі додавати нагадування про ШІ-асистента й пораду звернутися до лікаря лише для серйозних/особистих медичних питань (FR-008e/FR-008f)
- [X] T029 [US1c] Підключити `__('bot.medical_disclaimer_instruction')` до побудови системного промпту в `app/Services/Rag/AnswerGenerationService.php`

**Checkpoint**: US1c повністю функціональна — медичне застереження працює незалежно

---

## Phase 7: User Story 2 - Бот поєднує базу знань і пошук в інтернеті за потреби (Priority: P2)

**Goal**: Для одного питання бот може використати і `document`-, і `web`-джерела в межах ліміту кроків, коли жодне окремо не покриває питання повністю.

**Independent Test**: Поставити питання, частина відповіді на яке в документах, а частина вимагає свіжої інформації з інтернету — переконатися, що `sources` містить обидва типи.

### Tests for User Story 2 ⚠️

- [X] T030 [P] [US2] Feature-тест: питання, що вимагає і бази знань, і інтернету → `sources` містить елементи обох типів (`document` і `web`) в межах одного `config('rag.agent.max_tool_steps')` у `tests/Feature/Query/CombinedSourcesTest.php`
- [X] T031 [P] [US2] Feature-тест: питання, повністю покрите базою знань → `web_search` не викликається (регрес-перевірка FR-005 разом із можливістю комбінування) у `tests/Feature/Query/CombinedSourcesTest.php`

### Implementation for User Story 2

- [X] T032 [US2] Перевірити й за потреби виправити агрегацію джерел у `app/Services/Rag/AgentToolRunner.php`, щоб цикл коректно накопичував джерела з кількох різних викликів інструментів (а не лише останнього) перед поверненням фінальної відповіді (FR-004)

**Checkpoint**: US2 повністю функціональна — комбінування джерел працює незалежно

---

## Phase 8: User Story 3 - Промпти бота зберігаються як локалізовані тексти (Priority: P3)

**Goal**: Жодна інструкція, що керує рішеннями бота, не залишається "зашитою" в коді сервісів — усе в `resources/lang/en/bot.php`.

**Independent Test**: Пошук по `app/Services/Rag/*.php` не знаходить текстових промптів англійською мовою — увесь такий текст лише в `resources/lang/en/bot.php`.

### Tests for User Story 3 ⚠️

- [X] T033 [P] [US3] Тест, що перевіряє відсутність захардкоджених інструкцій бота в `app/Services/Rag/AnswerGenerationService.php` і `app/Services/Rag/QueryRewriter.php` (наприклад, пошук характерних фрагментів наявного промпту) і підтверджує, що всі ключі `bot.php` використовуються через `__()` у `tests/Unit/PromptLocalizationTest.php`
- [X] T033a [P] [US3] Тест: додавання `resources/lang/uk/bot.php` і `app()->setLocale('uk')` змінює текст системних інструкцій без жодної правки сервісного коду (FR-011, SC-005) у `tests/Unit/PromptLocalizationTest.php`
- [X] T033b [P] [US3] Regression-тест: питання українською мовою → системні інструкції (`bot.php`, `config('app.locale')`) лишаються англійською, а сама відповідь користувачу — українською, тобто дві "мови" незалежні одна від одної (FR-010, FR-012) у `tests/Feature/Query/WebSearchSourceTest.php`

### Implementation for User Story 3

- [X] T034 [US3] Фінальний прохід по `app/Services/Rag/AnswerGenerationService.php` і `app/Services/Rag/QueryRewriter.php` — перенести будь-які текстові фрагменти інструкцій, що лишилися захардкодженими після T007-T029, у `resources/lang/en/bot.php` (FR-009-FR-012)

**Checkpoint**: Усі шість користувацьких історій повністю функціональні й незалежно тестовані

---

## Phase 9: Polish & Cross-Cutting Concerns

**Purpose**: Захист від регресій і фінальна перевірка якості понад усі користувацькі історії

**⚠️ КРИТИЧНО**: Перехід з Chat Completions на Responses API в `AnswerGenerationService` (T010) ламає мокінг (`OpenAI::fake([CreateResponse::fake(...), EmbeddingsCreateResponse::fake(...)])`) у всіх наявних тестах RAG-конвеєра — їх потрібно узгодити з новим форматом Responses API

- [X] T034a Feature-тест: з `RAG_AGENT_MAX_STEPS=1` складне питання (що потребує і бази знань, і інтернету) усе одно повертає `200 OK` з відповіддю за прийнятний час, без тайм-ауту — цикл примусово завершується після ліміту кроків (FR-006, SC-003) у `tests/Feature/Query/AgentStepLimitTest.php`
- [X] T035 Оновити мокінг OpenAI у наявних тестах RAG-конвеєра (`tests/Feature/Query/AnswerFromKnowledgeBaseTest.php`, `AnswerSourcesTest.php`, `SourceLinkTest.php`, `MatchScoreTest.php`, `NoRelevantInfoTest.php`, `QueryLoggingTest.php`, `LlmFailureRetryTest.php`, `LanguageMatchTest.php`, `EmptySourcesTest.php`, `tests/Feature/Session/*.php`, `tests/Feature/PublicChatTest.php`, `tests/Feature/Performance/ConcurrentLoadTest.php`) з `OpenAI::fake([CreateResponse::fake(...), ...])` (Chat Completions) на формат Responses API — без зміни сценаріїв/очікувань самих тестів, лише механізму моку
- [X] T036 Запустити повний набір тестів (`php artisan test`) і усунути всі регресії, спричинені переходом на Responses API та агентний цикл
- [X] T037 [P] Оновити докблоки/коментарі в `app/Services/Rag/AnswerGenerationService.php`, `AgentToolRunner.php`, `KnowledgeBaseSearchTool.php`, що описують новий агентний конвеєр (Принцип IV, Observability)
- [X] T038 Виконати сценарії `specs/003-agentic-web-search/quickstart.md` вручну проти локального середовища (Sail) і підтвердити відповідність очікуваним результатам

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: без залежностей — старт одразу
- **Foundational (Phase 2)**: залежить від Setup — блокує всі користувацькі історії (T009/T010 — центральний агентний цикл, від якого залежить будь-яка функціональність)
- **US1 (Phase 3)**: залежить від Foundational — MVP
- **US1a (Phase 4)**: залежить від Foundational; незалежна від US1 (може виконуватися паралельно)
- **US1b (Phase 5)**: залежить від Foundational; незалежна від US1/US1a
- **US1c (Phase 6)**: залежить від Foundational; незалежна від US1/US1a/US1b
- **US2 (Phase 7)**: залежить від Foundational; практично зручніше виконувати після US1 (перевикористовує ті самі тестові сценарії веб-пошуку), але функціонально незалежна
- **US3 (Phase 8)**: залежить від того, що всі попередні історії вже додали свої ключі в `bot.php` (T008 у Foundational, T024, T028) — фінальна перевірка повноти локалізації
- **Polish (Phase 9)**: залежить від завершення всіх користувацьких історій

### Within Each User Story

- Тести пишуться першими й мають падати (Red) до реалізації (Принцип II конституції)
- US1b і US1c торкаються тих самих двох файлів (`bot.php`, `AnswerGenerationService.php`) — виконувати послідовно, не паралельно між собою, навіть попри різні ключі/блоки коду

### Parallel Opportunities

- T001-T004 (Setup) — усі паралельно (різні файли/секції)
- T005-T006, T006a-T006c (Foundational тести) — паралельно; T006e (модель) — паралельно з T007-T008, але після T006d (міграція)
- Тести в межах кожної окремої користувацької історії (T013-T015, T017-T019, T021-T023, T026-T027, T030-T031, T033/T033a/T033b) — паралельно між собою
- US1a (Phase 4) і US1b (Phase 5) можуть виконуватися паралельно двома розробниками одразу після Foundational — різні файли (`AgentToolRunner.php` конфіг-читання vs `bot.php`+`AnswerGenerationService.php` системний промпт); US1c краще виконувати після US1b послідовно через спільні файли

---

## Parallel Example: User Story 1b + User Story 1a

```bash
# Одночасно двома розробниками після Foundational:
# Розробник A (US1a):
Task: "Unit-тест парсингу RAG_WEB_SEARCH_ALLOWED_DOMAINS у tests/Unit/RagConfigTest.php"
Task: "Feature-тест allow-list доменів у tests/Feature/Query/AllowedDomainsTest.php"

# Розробник B (US1b):
Task: "Feature-тест відмови на сторонні питання у tests/Feature/Query/OutOfScopeQuestionTest.php"
Task: "Додати system_identity у resources/lang/en/bot.php"
```

---

## Implementation Strategy

### MVP First (Foundational + User Story 1)

1. Завершити Phase 1: Setup
2. Завершити Phase 2: Foundational (агентний цикл, KnowledgeBaseSearchTool, AgentToolRunner)
3. Завершити Phase 3: US1 (веб-пошук за потреби)
4. **СТОП і ПЕРЕВІРИТИ**: Незалежний тест US1 — це вже відповідає основному запиту користувача ("бот, що лазить в інтернет за потреби")
5. US1a/US1b/US1c — критичні для безпечного продакшн-запуску (allow-list, вузька спеціалізація, медичне застереження) — виконати одразу після MVP, до релізу

### Incremental Delivery

1. Setup + Foundational → агентний фундамент готовий
2. US1 → тест незалежно → технічний MVP
3. US1a + US1b + US1c (можна паралельно) → тест кожної незалежно → безпечний для продакшну реліз
4. US2 → тест незалежно → повніша якість відповідей
5. US3 → підтвердження повноти локалізації
6. Polish → міграція наявних тестів на Responses API, повний прогін, ручна перевірка quickstart.md

---

## Notes

- [P] завдання = різні файли, без залежностей одне від одного
- Мітка [Story] прив'язує завдання до конкретної користувацької історії зі spec.md
- Тести обов'язкові (Принцип II конституції) — писати до реалізації, переконатися що падають
- Комітити після кожного завдання чи логічної групи
- Зупинятися на кожному чекпоінті, щоб перевірити історію незалежно
- US1a/US1b/US1c мають однаковий пріоритет P1 зі spec.md — усі три критичні для безпечного (не лише функціонального) запуску, попри технічну незалежність від "базового" US1
