---

description: "Task list for RAG AI Bot Backend (API MVP)"
---

# Tasks: RAG AI Bot Backend (API MVP)

**Input**: Design documents from `/specs/001-rag-ai-bot-backend/`

**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/api.md, quickstart.md

**Tests**: Обов'язкові (не опційні) — Принцип II конституції проєкту
(Test-First, NON-NEGOTIABLE) вимагає тестів до або одночасно з реалізацією
для кожної бізнес-логіки.

**Organization**: Задачі згруповано за User Story зі spec.md (US1–US6) для
незалежної реалізації й тестування кожної.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Можна виконувати паралельно (різні файли, немає залежності від
  незавершених задач)
- **[Story]**: До якої user story зі spec.md належить задача (US1–US6)
- Точні шляхи файлів вказано в описі кожної задачі

## Path Conventions

Один Laravel-застосунок (Structure Decision у plan.md): `app/`, `routes/`,
`database/migrations/`, `tests/` у корені репозиторію.

---

## Phase 1: Setup (Shared Infrastructure)

**Purpose**: Підняття середовища й залежностей (research.md)

- [X] T001 Встановити та налаштувати Laravel Sail з `pgsql` і `redis`
      (`composer require laravel/sail --dev`, `php artisan sail:install --with=pgsql,redis`),
      оновити `.env`/`.env.example` (`DB_CONNECTION=pgsql`, `DB_HOST=pgsql`)
- [X] T002 [P] Встановити Composer-залежності: `laravel/sanctum`,
      `theodo-group/llphant`, `openai-php/laravel`, `smalot/pdfparser`,
      `spatie/pdf-to-image`, `patrickschur/language-detection`,
      `filament/filament` — оновити `composer.json`
      (примітка: openai-php/laravel зафіксовано на ^0.19.1 і llphant на ^1.0
      через реальний конфлікт версій guzzle/openai-php-client з Laravel 13 —
      composer.json відображає фактично сумісні версії)
- [X] T003 [P] Створити `config/rag.php` зі значеннями `supported_languages`,
      `session_timeout_minutes`, `max_upload_size_mb` (=50),
      `rate_limit_per_minute`, читаними з `.env` (FR-001a, FR-009a/b,
      FR-012a, FR-013a)
- [X] T004 [P] Додати команду увімкнення розширення `pgvector` при старті
      середовища — оновити `README.md`/скрипт налаштування Sail (задокументовано
      в `quickstart.md`); базовий образ `pgsql` у `compose.yaml` замінено на
      `pgvector/pgvector:pg18` (офіційний postgres:18-alpine не містить
      розширення vector); розширення увімкнено командою `CREATE EXTENSION vector`
- [X] T005 [P] Опублікувати конфігурацію Sanctum (`php artisan vendor:publish --provider="Laravel\Sanctum\SanctumServiceProvider"`)

**Checkpoint**: Середовище піднято, залежності встановлено.

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: Схема БД, моделі, автентифікація, rate limiting, логування —
без цього жодна user story не може стартувати

**⚠️ CRITICAL**: Жодна робота над user story не починається, поки ця фаза не
завершена

> **Конституція, Принцип V (Versioning & Breaking Changes)**: кожна міграція
> нижче MUST мати коректний `down()`, що повністю скасовує `up()` — включно з
> нестандартними типами (`enum`, `vector(N)` через pgvector, частковий
> unique-індекс по `content_hash` серед не-видалених). Перевірити це окремо
> для T006-T010, оскільки автогенеровані заготовки Laravel не покривають такі
> типи коректно за замовчуванням.

- [X] T006 Створити міграцію `documents` у
      `database/migrations/xxxx_create_documents_table.php`
      (`content_hash` unique серед не-видалених, `status` enum, `size_bytes`,
      `failure_reason`, `duplicate_of_document_id`, `deleted_at` — data-model.md;
      `down()` MUST коректно видаляти таблицю разом з enum-типом та
      частковим unique-індексом; самопосилальний FK `duplicate_of_document_id`
      додано окремою командою `Schema::table` після `Schema::create` —
      Postgres не резолвить self-FK в межах одного `CREATE TABLE`)
- [X] T007 [P] Створити міграцію `document_chunks` у
      `database/migrations/xxxx_create_document_chunks_table.php`
      (FK на `documents`, колонка `embedding vector(N)` через `pgvector`,
      `source` enum `text_layer`/`vision_ocr`, індекс HNSW; `down()` MUST
      коректно видаляти vector-колонку, HNSW-індекс та enum-тип; enum на
      Postgres реалізується через Laravel як CHECK-constraint, тому окремого
      DROP TYPE не потрібно — досить `DROP TABLE`)
- [X] T008 [P] Створити міграцію `conversation_sessions` у
      `database/migrations/xxxx_create_conversation_sessions_table.php`
      (UUID PK, `last_activity_at`; `down()` — стандартне видалення таблиці)
- [X] T009 [P] Створити міграцію `query_logs` у
      `database/migrations/xxxx_create_query_logs_table.php`
      (FK на `conversation_sessions`, `source_document_ids` JSON,
      `llm_input_tokens`, `llm_output_tokens`, `detected_language`,
      `answered_in_language`; `down()` — стандартне видалення таблиці)
- [X] T010 [P] Створити міграцію `answer_feedback` у
      `database/migrations/xxxx_create_answer_feedback_table.php`
      (FK unique на `query_logs`, `rating` enum, `comment`; `down()` MUST
      коректно видаляти таблицю разом з enum-типом `rating`)
- [X] T011 Створити модель `Document` з трейтом `SoftDeletes` у
      `app/Models/Document.php` (depends: T006)
- [X] T012 [P] Створити модель `DocumentChunk` у `app/Models/DocumentChunk.php`
      (depends: T007; використовує `Pgvector\Laravel\HasNeighbors` та
      `Vector`-каст із пакета `pgvector/pgvector`)
- [X] T013 [P] Створити модель `ConversationSession` у
      `app/Models/ConversationSession.php` (depends: T008)
- [X] T014 [P] Створити модель `QueryLog` у `app/Models/QueryLog.php`
      (depends: T009; `llm_input_tokens`/`llm_output_tokens` у `$hidden` —
      FR-010b)
- [X] T015 [P] Створити модель `AnswerFeedback` у `app/Models/AnswerFeedback.php`
      (depends: T010)
- [X] T016 Додати artisan-команду видачі технічного Sanctum-токена
      `app/Console/Commands/IssueApiTokenCommand.php` (research.md #5);
      команда `rag:issue-token {name}` (додано `HasApiTokens` до `App\Models\User`)
- [X] T017 Налаштувати `throttle`-middleware, прив'язаний до ідентифікатора
      Sanctum-токена, з лімітом із `config('rag.rate_limit_per_minute')` і
      відповіддю `429` + заголовком `Retry-After` — у `bootstrap/app.php`
      (FR-013a/b); реалізовано через `RateLimiter::for('rag-api', ...)` у
      `then`-колбеку `withRouting()` + маршрут-група `throttle:rag-api`
- [X] T018 Створити базову структуру `routes/api.php` з групою middleware
      `auth:sanctum` + throttle з T017 (без ще визначених ендпоінтів);
      додано `api:` параметр у `bootstrap/app.php withRouting()`
- [X] T019 Налаштувати окремий канал логування для помилок обробки
      документів/LLM у `config/logging.php` (Принцип IV конституції —
      Observability); канал `rag` (daily)

**Checkpoint**: Фундамент готовий — можна починати будь-яку user story.

---

## Phase 3: User Story 1 - Завантаження документа у базу знань (Priority: P1) 🎯 MVP

**Goal**: Оператор завантажує PDF (зокрема скановані), система дедуплікує за
хешем, асинхронно розбиває на фрагменти й векторизує (FR-001–FR-007, FR-003a)

**Independent Test**: Надіслати файл на `POST /api/v1/documents`, дочекатися
`status: "processed"` через `GET /api/v1/documents/{id}`; повторне
завантаження того самого файлу відразу повертає `status: "duplicate"`.

### Tests for User Story 1 ⚠️ (написати першими, переконатися що ПАДАЮТЬ)

- [X] T020 [P] [US1] Feature-тест успішного завантаження (201, `status: pending`)
      у `tests/Feature/DocumentUpload/UploadSuccessTest.php`
- [X] T021 [P] [US1] Feature-тест відхилення файлу > 50 МБ (FR-001a) у
      `tests/Feature/DocumentUpload/UploadSizeLimitTest.php`
- [X] T022 [P] [US1] Feature-тест синхронного відхилення пошкодженого/
      непідтримуваного файлу (FR-001b) у
      `tests/Feature/DocumentUpload/UploadValidationTest.php`
- [X] T023 [P] [US1] Feature-тест виявлення дубліката за хешем (FR-002/003) у
      `tests/Feature/DocumentUpload/DuplicateDetectionTest.php`
- [X] T024 [P] [US1] Feature-тест очікування другого запиту при паралельному
      завантаженні того самого хешу (FR-003a) у
      `tests/Feature/DocumentUpload/ConcurrentDuplicateTest.php`
- [X] T025 [P] [US1] Feature-тест переходів статусу документа (FR-007) у
      `tests/Feature/DocumentUpload/DocumentStatusTest.php`
- [X] T026 [P] [US1] Unit-тест `ChunkingService` (розбиття тексту на фрагменти)
      у `tests/Unit/ChunkingServiceTest.php`
- [X] T027 [P] [US1] Unit-тест `PdfVisionExtractor` (виклик vision-моделі лише
      для сторінок без текстового шару, FR-005) у
      `tests/Unit/PdfVisionExtractorTest.php`

### Implementation for User Story 1

- [X] T028 [US1] Реалізувати `DocumentUploadService` (валідація розміру/формату,
      обчислення SHA-256 хешу, дедуп-логіка з блокуванням паралельних
      завантажень того самого хешу) у
      `app/Services/DocumentIngestion/DocumentUploadService.php`
      (depends: T011, тести T020-T024 мають існувати й падати)
- [X] T029 [P] [US1] Реалізувати `PdfTextExtractor` (текстовий шар PDF) у
      `app/Services/DocumentIngestion/PdfTextExtractor.php`
- [X] T030 [P] [US1] Реалізувати `PdfVisionExtractor` (рендер сторінки в
      зображення + vision-запит до LLM) у
      `app/Services/DocumentIngestion/PdfVisionExtractor.php`
- [X] T031 [US1] Реалізувати `ChunkingService` у
      `app/Services/DocumentIngestion/ChunkingService.php`
      (depends: T029, T030)
- [X] T032 [P] [US1] Реалізувати `EmbeddingService` на основі LLPhant у
      `app/Services/Rag/EmbeddingService.php`
- [X] T033 [US1] Реалізувати `ProcessDocumentJob` (оркестрація: екстракція →
      чанкінг → ембединг → оновлення статусу на кожному кроці, FR-006/FR-007)
      у `app/Jobs/ProcessDocumentJob.php`
      (depends: T028, T031, T032)
- [X] T034 [P] [US1] Реалізувати `UploadDocumentRequest` (валідація файлу,
      MIME, розмір) у `app/Http/Requests/UploadDocumentRequest.php`
- [X] T035 [US1] Реалізувати `DocumentController@store/@index/@show` у
      `app/Http/Controllers/Api/DocumentController.php`
      (depends: T028, T033, T034)
- [X] T036 [US1] Додати маршрути `POST/GET /api/v1/documents`,
      `GET /api/v1/documents/{id}` у `routes/api.php` (depends: T035, T018)

**Checkpoint**: User Story 1 повністю функціональна й тестована незалежно
(MVP-мінімум — завантаження та обробка документів).

---

## Phase 4: User Story 2 - Отримання відповіді на питання через API (Priority: P1) 🎯 MVP

**Goal**: Клієнт ставить питання, система шукає релевантні фрагменти,
формує відповідь через LLM мовою питання, логує обмін (FR-008–FR-013b)

**Independent Test**: Поставити питання про факт із завантаженого документа
через `POST /api/v1/queries` і переконатися, що відповідь містить коректну
інформацію; питання не за темою повертає чесну відповідь про відсутність
інформації.

### Tests for User Story 2 ⚠️

- [X] T037 [P] [US2] Feature-тест відповіді на основі бази знань (SC-002) у
      `tests/Feature/Query/AnswerFromKnowledgeBaseTest.php`
- [X] T038 [P] [US2] Feature-тест чесної відповіді про відсутність релевантної
      інформації (FR-009) у `tests/Feature/Query/NoRelevantInfoTest.php`
- [X] T039 [P] [US2] Feature-тест запису обміну питання-відповідь у журнал
      (FR-010) у `tests/Feature/Query/QueryLoggingTest.php`
- [X] T040 [P] [US2] Feature-тест однієї повторної спроби при збої LLM, потім
      помилка клієнту (FR-009c) у `tests/Feature/Query/LlmFailureRetryTest.php`
- [X] T041 [P] [US2] Feature-тест відповіді мовою питання й мовою за
      замовчуванням для непідтримуваної мови (FR-009a/b) у
      `tests/Feature/Query/LanguageMatchTest.php`
- [X] T042 [P] [US2] Unit-тест `LanguageDetectionService` у
      `tests/Unit/LanguageDetectionServiceTest.php`
- [X] T043 [P] [US2] Feature-тест відповіді `429` з `Retry-After` при
      перевищенні rate limit (FR-013b) у `tests/Feature/Query/RateLimitTest.php`
- [X] T078 [P] [US2] Feature-тест перегляду журналу запитів — `GET
      /api/v1/queries` повертає раніше збережені записи питання-відповідь
      без повторного звернення до LLM (SC-005) у
      `tests/Feature/Query/QueryLogListTest.php`

### Implementation for User Story 2

- [X] T044 [P] [US2] Реалізувати `VectorSearchService` (пошук найближчих
      фрагментів у pgvector) у `app/Services/Rag/VectorSearchService.php`
- [X] T045 [P] [US2] Реалізувати `LanguageDetectionService` у
      `app/Services/Conversation/LanguageDetectionService.php`
- [X] T046 [US2] Реалізувати `AnswerGenerationService` (запит до LLM, одна
      автоматична повторна спроба, застосування мови відповіді) у
      `app/Services/Rag/AnswerGenerationService.php`
      (depends: T044, T045)
- [X] T047 [P] [US2] Реалізувати `SubmitQueryRequest` у
      `app/Http/Requests/SubmitQueryRequest.php`
- [X] T048 [US2] Реалізувати `QueryController@store` (включно з записом
      `QueryLog`, токен-метрики FR-010b) у
      `app/Http/Controllers/Api/QueryController.php`
      (depends: T046, T047, T014)
- [X] T049 [US2] Додати маршрут `POST /api/v1/queries` у `routes/api.php`
      (depends: T048, T018, T017)
- [X] T079 [US2] Реалізувати `QueryController@index` (список записів
      `QueryLog`, без полів токен-метрики — FR-010b) та маршрут
      `GET /api/v1/queries` у `app/Http/Controllers/Api/QueryController.php`
      і `routes/api.php`, покриває SC-005 (depends: T048, T078)

**Checkpoint**: User Story 1 + 2 разом утворюють функціональний RAG MVP.

---

## Phase 5: User Story 3 - Продовження розмови з пам'яттю контексту (Priority: P2)

**Goal**: Сесія розмови — власний запис у БД; невідома/прострочена сесія
автоматично замінюється новою (FR-010a–FR-012a, FR-011a)

**Independent Test**: Надіслати два пов'язаних питання з тим самим
`session_id`; друга відповідь враховує контекст першої (SC-004).

### Tests for User Story 3 ⚠️

- [X] T050 [P] [US3] Feature-тест створення нової сесії при першому питанні
      (FR-012) у `tests/Feature/Session/NewSessionTest.php`
- [X] T051 [P] [US3] Feature-тест урахування контексту в межах сесії
      (FR-011, SC-004) у `tests/Feature/Session/ContextContinuityTest.php`
- [X] T052 [P] [US3] Feature-тест автоматичного створення нової сесії для
      невідомого/простроченого `session_id` (FR-011a) у
      `tests/Feature/Session/UnknownSessionTest.php`
- [X] T053 [P] [US3] Unit-тест логіки тайм-ауту сесії, включно з `null` =
      без автозавершення (FR-012a) у
      `tests/Unit/ConversationSessionServiceTest.php`

### Implementation for User Story 3

- [X] T054 [US3] Реалізувати `ConversationSessionService` (find-or-create,
      перевірка тайм-ауту, оновлення `last_activity_at`) у
      `app/Services/Conversation/ConversationSessionService.php`
      (depends: T013)
- [X] T055 [US3] Інтегрувати `ConversationSessionService` у
      `QueryController`/`AnswerGenerationService` для завантаження історії
      сесії як контексту LLM (depends: T054, T048, T046)

**Checkpoint**: Розмова з пам'яттю контексту працює незалежно поверх US1+US2.

---

## Phase 6: User Story 4 - Веб-інтерфейс керування документами бази знань (Priority: P2)

**Goal**: Веб-сторінка без авторизації для перегляду/завантаження/м'якого
видалення документів (FR-015–FR-019, FR-017a)

**Independent Test**: Відкрити сторінку в браузері, завантажити файл через
форму, побачити його в списку зі статусом, видалити документ і переконатися,
що він зникає зі списку та більше не використовується у відповідях.

### Tests for User Story 4 ⚠️

- [X] T056 [P] [US4] Feature-тест рендеру списку документів на сторінці
      (FR-015) у `tests/Feature/Admin/DocumentListPageTest.php`
- [X] T057 [P] [US4] Feature-тест завантаження файлу через веб-форму (FR-016)
      у `tests/Feature/Admin/DocumentUploadPageTest.php`
- [X] T058 [P] [US4] Feature-тест м'якого видалення через сторінку (FR-017,
      FR-017a, FR-018) у `tests/Feature/Admin/DocumentDeletePageTest.php`
- [X] T059 [P] [US4] Feature-тест видалення неіснуючого документа → чітка
      помилка (FR-018a); реалізовано як API-рівневий тест (сам контракт
      видалення — DELETE /api/v1/documents/{id}, а Filament-дія в таблиці
      оперує лише наявними рядками) у
      `tests/Feature/DocumentUpload/DocumentDeletionTest.php`
- [X] T060 [P] [US4] Feature-тест доступності сторінки без входу в систему
      (FR-019) у `tests/Feature/Admin/DocumentPageAccessTest.php`

### Implementation for User Story 4

- [X] T061 [US4] Встановити й налаштувати Filament-панель без guard'а
      автентифікації (FR-019) — `app/Providers/Filament/AdminPanelProvider.php`
- [X] T062 [US4] Створити `DocumentResource` (список, форма завантаження,
      дія видалення — використовує `DocumentUploadService`) у
      `app/Filament/Resources/DocumentResource.php` (depends: T028, T061)
- [X] T063 [US4] Реалізувати `DocumentController@destroy` (м'яке видалення,
      404 для неіснуючого) у
      `app/Http/Controllers/Api/DocumentController.php`, додати
      `DELETE /api/v1/documents/{id}` у `routes/api.php`
      (depends: T035, T036)

**Checkpoint**: Веб-керування документами доступне й повністю відображає
поведінку API-ендпоінтів завантаження/видалення.

---

## Phase 7: User Story 5 - Прозорість джерел відповіді (Priority: P2)

**Goal**: Кожна відповідь супроводжується переліком документів-джерел
(FR-009d, SC-009)

**Independent Test**: Поставити питання, відповідь на яке спирається на
конкретний документ, і переконатися, що поле `sources` у відповіді API
містить саме цей документ; для відповіді "інформацію не знайдено" — `sources`
порожній.

### Tests for User Story 5 ⚠️

- [X] T064 [P] [US5] Feature-тест наявності `sources` з назвою документа-
      джерела у відповіді (FR-009d) у
      `tests/Feature/Query/AnswerSourcesTest.php`
- [X] T065 [P] [US5] Feature-тест порожнього `sources` при відсутності
      релевантної інформації у `tests/Feature/Query/EmptySourcesTest.php`

### Implementation for User Story 5

- [X] T066 [US5] Розширити `VectorSearchService`/`AnswerGenerationService` для
      відстеження документів-джерел використаних фрагментів (depends: T044, T046)
- [X] T067 [US5] Додати поле `sources` у відповідь `QueryController@store`
      відповідно до `contracts/api.md` (depends: T066, T048)

**Checkpoint**: Прозорість джерел працює для всіх відповідей US2/US3.

---

## Phase 8: User Story 6 - Зворотний зв'язок щодо якості відповіді (Priority: P3)

**Goal**: Клієнт оцінює відповідь («корисно»/«некорисно» + коментар);
повторна оцінка замінює попередню (FR-010c)

**Independent Test**: Надіслати оцінку для отриманої відповіді за
`query_log_id`, переконатися що вона збережена; надіслати іншу оцінку для
того самого `query_log_id` і переконатися, що вона замінила попередню.

### Tests for User Story 6 ⚠️

- [X] T068 [P] [US6] Feature-тест збереження оцінки з коментарем (FR-010c) у
      `tests/Feature/Feedback/SubmitFeedbackTest.php`
- [X] T069 [P] [US6] Feature-тест заміни попередньої оцінки новою (FR-010c) у
      `tests/Feature/Feedback/ReplaceFeedbackTest.php`
- [X] T070 [P] [US6] Feature-тест `404` для неіснуючого `query_log_id` у
      `tests/Feature/Feedback/FeedbackNotFoundTest.php`

### Implementation for User Story 6

- [X] T071 [P] [US6] Реалізувати `SubmitFeedbackRequest` у
      `app/Http/Requests/SubmitFeedbackRequest.php`
- [X] T072 [US6] Реалізувати `FeedbackController@store` (upsert за
      `query_log_id`) у `app/Http/Controllers/Api/FeedbackController.php`
      (depends: T071, T015)
- [X] T073 [US6] Додати маршрут `POST /api/v1/queries/{query_log_id}/feedback`
      у `routes/api.php` (depends: T072, T018)

**Checkpoint**: Усі 6 user stories незалежно функціональні.

---

## Phase 9: Polish & Cross-Cutting Concerns

**Purpose**: Наскрізна перевірка та фінальне узгодження

- [X] T074 [P] Виконати сценарії `quickstart.md` end-to-end на Sail-середовищі;
      перевірено з реальним OpenAI API (не фейком): upload → processing →
      processed (з реальним vision-fallback, FR-005, для мінімального
      тестового PDF без текстового шару) → query з реальним retrieval+LLM.
      У процесі виявлено й виправлено занадто суворий поріг косинусної
      відстані в `VectorSearchService` (0.5 → 0.9) — реальні embeddings на
      короткому контенті давали більшу дистанцію, ніж fake-тести
- [X] T075 [P] Звірити кожен edge case зі spec.md з відповідним тестом
      (аудит покриття T020–T080); додано
      `tests/Feature/Session/LanguageSwitchWithinSessionTest.php` для
      edge case "зміна мови в межах сесії", який раніше не мав окремого тесту
- [X] T076 [P] Оновити `.env.example` новими змінними `RAG_*` та
      `DB_CONNECTION=pgsql`
- [X] T077 [P] Прогнати `./vendor/bin/pint` для форматування коду
- [X] T080 Провести просте навантажувальне/конкурентне тестування (SC-008):
      скрипт або тест, що симулює 5-10 одночасних клієнтів, які надсилають
      запити до `/api/v1/documents` та `/api/v1/queries` на базі знань
      обсягом, наближеним до 1000 документів, і фіксує відсутність відмов чи
      суттєвої деградації часу відповіді, у
      `tests/Feature/Performance/ConcurrentLoadTest.php` (depends: T036, T049)

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: без залежностей
- **Foundational (Phase 2)**: залежить від Setup — БЛОКУЄ всі user stories
- **User Stories (Phase 3-8)**: усі залежать від завершення Foundational
  - US1 і US2 — обидва P1, разом становлять MVP; US2 технічно залежить від
    наявності проіндексованих документів з US1 для повноцінного тестування,
    але код (контролери/сервіси) можна писати паралельно
  - US3, US5 інтегруються поверх US2 (розширюють `QueryController`/
    `AnswerGenerationService`), тому виконуються після US2
  - T078/T079 (перегляд журналу запитів, покриває SC-005) додано до US2,
    оскільки залежить від тих самих моделі `QueryLog` і контролера
  - T080 (навантажувальне тестування SC-008) виконується в Polish-фазі
    після завершення US1 і US2, коли є повний пайплайн upload→query
  - US4 залежить від `DocumentUploadService` (US1) та додає ендпоінт
    видалення, який також потрібен контракту API
  - US6 залежить лише від наявності `QueryLog` (Foundational) — може
    виконуватися паралельно з US3/US4/US5 після US2
- **Polish (Phase 9)**: залежить від завершення бажаних user stories

### Within Each User Story

- Тести пишуться першими і мають ПАДАТИ до реалізації (Принцип II конституції)
- Моделі (Foundational) → сервіси → HTTP-запити/контролери → маршрути
- Історія вважається завершеною перед переходом до наступної за пріоритетом

### Parallel Opportunities

- Усі задачі Setup з [P] — паралельно
- Усі міграції/моделі Foundational з [P] — паралельно (T007-T010, T012-T015)
- Після Foundational: US1 і US2 можна розробляти паралельно різними людьми
  (різні файли), US6 — паралельно з US3/US4/US5
- Усі тести всередині однієї user story, позначені [P], — паралельно

---

## Parallel Example: User Story 1

```bash
# Тести US1 одночасно:
Task: "Feature-тест успішного завантаження в tests/Feature/DocumentUpload/UploadSuccessTest.php"
Task: "Feature-тест ліміту розміру в tests/Feature/DocumentUpload/UploadSizeLimitTest.php"
Task: "Feature-тест валідації формату в tests/Feature/DocumentUpload/UploadValidationTest.php"
Task: "Feature-тест дублікатів в tests/Feature/DocumentUpload/DuplicateDetectionTest.php"

# Сервіси US1, що не залежать одне від одного:
Task: "PdfTextExtractor у app/Services/DocumentIngestion/PdfTextExtractor.php"
Task: "PdfVisionExtractor у app/Services/DocumentIngestion/PdfVisionExtractor.php"
Task: "EmbeddingService у app/Services/Rag/EmbeddingService.php"
```

---

## Implementation Strategy

### MVP First (User Story 1 + 2)

1. Phase 1: Setup
2. Phase 2: Foundational (КРИТИЧНО — блокує все інше)
3. Phase 3: User Story 1 (завантаження й обробка документів)
4. Phase 4: User Story 2 (питання-відповідь) — разом із US1 це вже
   демонстрований RAG MVP через API
5. **ЗУПИНИТИСЯ й ПЕРЕВІРИТИ**: прогнати сценарії 1-2 з `quickstart.md`
6. Розгорнути/продемонструвати за потреби

### Incremental Delivery

1. Setup + Foundational → фундамент готовий
2. US1 → US2 → перевірити незалежно → MVP демо
3. US3 (пам'ять розмови) → перевірити → демо
4. US4 (веб-панель) → перевірити → демо
5. US5 (джерела відповіді) → перевірити → демо
6. US6 (зворотний зв'язок) → перевірити → демо
7. Polish

### Parallel Team Strategy

1. Команда разом завершує Setup + Foundational
2. Після Foundational:
   - Розробник A: US1 (завантаження документів)
   - Розробник B: US2 (питання-відповідь, стартує тести одразу, реалізацію
     синхронізує з готовністю US1 для повного end-to-end тесту)
   - Розробник C: US6 (незалежний від US3/US4/US5)
3. US3, US4, US5 — після US1/US2, можуть паралелитися між розробниками, що
   звільнилися

---

## Notes

- [P] = різні файли, без залежностей між собою
- [Story] прив'язує задачу до user story зі spec.md для трасування
- Кожна user story незалежно завершувана й тестована
- Тести MUST падати до реалізації (Принцип II конституції, NON-NEGOTIABLE)
- Комітити після кожної задачі або логічної групи задач
- Зупинятися на кожному чекпоінті для незалежної перевірки історії
- Уникати: розмитих задач, конфліктів в одному файлі між [P]-задачами,
  міжісторійних залежностей, що ламають незалежність
