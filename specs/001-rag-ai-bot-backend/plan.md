# Implementation Plan: RAG AI Bot Backend (API MVP)

**Branch**: `001-rag-ai-bot-backend` | **Date**: 2026-09-06 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/001-rag-ai-bot-backend/spec.md`

## Summary

Бекенд на Laravel (піднятий через Sail), що реалізує RAG-архітектуру: оператор
завантажує документи (передусім PDF, зокрема скановані/графічні) через API або
просту веб-сторінку без авторизації; система дедуплікує файли за хешем,
асинхронно розбиває їх на фрагменти, розпізнає текст із зображень через
vision-модель за потреби та векторизує в PostgreSQL із pgvector. Клієнт API
ставить питання через ендпоінт RAG-запиту; система знаходить релевантні
фрагменти, формує запит до LLM (OpenAI або Gemini — конфігуровано), повертає
відповідь мовою питання (зі списку підтримуваних мов) разом із переліком
документів-джерел, підтримує сесію розмови (власний запис у БД, не HTTP-сесія)
з тайм-аутом неактивності, і логує кожен обмін питання-відповідь разом з
токен-метрикою LLM (внутрішньо) та можливістю залишити зворотний зв'язок.

## Technical Context

**Language/Version**: PHP 8.3, Laravel 13.17

**Primary Dependencies**:
- `laravel/sail` (dev) — локальне середовище розробки в Docker
- `laravel/sanctum` — видача та перевірка технічного токена доступу до API (FR-013)
- `theodo-group/llphant` — RAG-оркестрація (chunking, embeddings, vector store abstraction, LLM клієнт)
- `openai-php/laravel` — офіційний клієнт OpenAI (ембединги + чат-модель; сумісний ендпоінт також покриває деякі Gemini-сумісні шлюзи, конкретний провайдер — конфігурація)
- `smalot/pdfparser` — витягання текстового шару з PDF
- `spatie/pdf-to-image` (+ `imagick`) — рендер сторінок PDF у зображення для сторінок без текстового шару (передаються у vision-модель)
- Вбудовані Laravel Queues (`database` або `redis` драйвер через Sail) — асинхронна обробка документів
- Вбудований `Illuminate\Cache\RateLimiter` / `throttle` middleware — rate limiting технічного токена (FR-013a/b)
- Filament (опційно, як панель для веб-сторінки керування документами, User Story 4) — прискорює розробку CRUD-інтерфейсу без написання Blade/JS з нуля

**Storage**: PostgreSQL 16 з розширенням `pgvector` (одна база даних для
документів, фрагментів+векторів, сесій розмов, журналу запитів і оцінок) —
обґрунтування вибору задокументовано в `research.md`

**Testing**: PHPUnit (наявний у проєкті), Feature-тести для API-ендпоінтів і
Filament-сторінки, Unit-тести для сервісів чанкінгу/дедуплікації/визначення
мови; `Queue::fake()` для тестів асинхронної обробки; HTTP-фейки для
зовнішнього LLM API

**Target Platform**: Linux-контейнери через Laravel Sail (Docker Compose) для
розробки; веб-сервер (API + Filament-панель) для продакшн-розгортання

**Project Type**: web-service (Laravel-моноліт: API + серверна веб-панель,
без окремого SPA-фронтенду на цьому етапі)

**Performance Goals**: відповідь на питання — прийнятний час очікування
синхронного HTTP-запиту (секунди, залежить від зовнішнього LLM API, не
мілісекунди); завантаження документа — миттєве підтвердження прийому,
обробка асинхронна без обмеження за часом виконання (в межах черги)

**Constraints**: максимальний розмір файлу 50 МБ (FR-001a); одна автоматична
повторна спроба при збої LLM (FR-009c); базовий rate limit на технічний
токен (FR-013a/b); підтримувані мови відповіді — конфігурований список

**Scale/Scope**: до ~1000 документів у базі знань, 5-10 одночасних
користувачів (SC-008); MVP не включає користувачів/авторизацію,
Telegram-інтеграцію чи вебвбудову — лише API + технічний токен + внутрішня
веб-панель керування документами

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

| Принцип конституції | Перевірка | Статус |
|---|---|---|
| I. Українська мова спілкування | Ця плановна документація й усі подальші комунікації — українською | PASS |
| II. Test-First (NON-NEGOTIABLE) | Кожен FR з spec.md отримає Feature/Unit-тест до або одночасно з реалізацією (деталі — у tasks.md); контракти API покриваються тестами перед реалізацією | PASS (буде забезпечено на етапі /speckit-tasks) |
| III. Simplicity (YAGNI) | Обрано один storage (PostgreSQL+pgvector) замість окремого векторного сховища; Sanctum замість кастомної токен-системи; Filament замість написання адмінки з нуля — мінімум нових абстракцій | PASS |
| IV. Observability | FR-007 (статуси документа), FR-010/FR-010b (журнал питання-відповідь + токен-метрика), стандартний Laravel logging для помилок LLM/обробки (FR-009c, FR-001b) | PASS |
| V. Versioning & Breaking Changes | Усі нові таблиці — через міграції Laravel зі зворотними `down()`; API-контракт документується в `contracts/` перед реалізацією | PASS |

Порушень немає, `Complexity Tracking` не заповнюється.

**Повторна перевірка після Phase 1 (data-model.md, contracts/, quickstart.md)**:
дизайн не додав нових зовнішніх сервісів, абстракцій чи проєктів понад
перелічені вище (один Laravel-застосунок, одна БД, стандартні Laravel-
патерни для моделей/джобів/контролерів) — усі принципи залишаються PASS.

## Project Structure

### Documentation (this feature)

```text
specs/001-rag-ai-bot-backend/
├── plan.md              # Цей файл (/speckit-plan)
├── research.md          # Phase 0 output
├── data-model.md        # Phase 1 output
├── quickstart.md        # Phase 1 output
├── contracts/
│   └── api.md           # Phase 1 output — контракт REST API
└── tasks.md             # Phase 2 output (/speckit-tasks, ще не створено)
```

### Source Code (repository root)

Проєкт — існуючий стандартний Laravel-моноліт (не потрібно вибирати між
опціями структури — застосунок уже розгорнутий за конвенціями Laravel).
Нові файли цієї фічі додаються в наявну структуру:

```text
app/
├── Models/
│   ├── Document.php
│   ├── DocumentChunk.php
│   ├── ConversationSession.php
│   ├── QueryLog.php
│   └── AnswerFeedback.php
├── Services/
│   ├── DocumentIngestion/
│   │   ├── DocumentUploadService.php      # валідація, хеш, дедуплікація (FR-001a/b, FR-002-003a)
│   │   ├── PdfTextExtractor.php           # текстовий шар PDF
│   │   ├── PdfVisionExtractor.php         # сторінки без тексту → vision-модель
│   │   └── ChunkingService.php            # розбиття на чанки (FR-004)
│   ├── Rag/
│   │   ├── EmbeddingService.php           # LLPhant embeddings
│   │   ├── VectorSearchService.php        # pgvector similarity search
│   │   └── AnswerGenerationService.php    # LLM-запит, ретрай (FR-009c), джерела (FR-009d)
│   └── Conversation/
│       ├── ConversationSessionService.php # створення/пошук/тайм-аут сесії (FR-010a-012a, FR-011a)
│       └── LanguageDetectionService.php   # визначення мови питання (FR-009a/b)
├── Jobs/
│   └── ProcessDocumentJob.php             # асинхронна обробка (FR-006)
├── Http/
│   ├── Controllers/Api/
│   │   ├── DocumentController.php         # upload/status/delete (API)
│   │   ├── QueryController.php            # питання-відповідь
│   │   └── FeedbackController.php         # FR-010c
│   ├── Middleware/
│   │   └── ThrottleApiToken.php           # FR-013a/b (або вбудований throttle)
│   └── Requests/
│       ├── UploadDocumentRequest.php
│       └── SubmitQueryRequest.php
└── Filament/
    └── Resources/DocumentResource.php     # веб-сторінка керування документами (User Story 4)

database/migrations/
├── xxxx_create_documents_table.php
├── xxxx_create_document_chunks_table.php  # з колонкою vector (pgvector)
├── xxxx_create_conversation_sessions_table.php
├── xxxx_create_query_logs_table.php
└── xxxx_create_answer_feedback_table.php

routes/
└── api.php                                 # ендпоінти документів/запитів/фідбеку

tests/
├── Feature/
│   ├── DocumentUploadTest.php
│   ├── DocumentDeletionTest.php
│   ├── QueryAnswerTest.php
│   ├── ConversationSessionTest.php
│   └── AnswerFeedbackTest.php
└── Unit/
    ├── ChunkingServiceTest.php
    ├── LanguageDetectionServiceTest.php
    └── DocumentUploadServiceTest.php
```

**Structure Decision**: Один Laravel-застосунок (без окремого backend/frontend
поділу) — API-ендпоінти в `routes/api.php` + Filament-панель для веб-керування
документами в межах того самого застосунку. Це відповідає Принципу III
конституції (Simplicity) і поточному масштабу проєкту (SC-008): окремий SPA чи
мікросервіс не виправдані на цьому етапі.

## Complexity Tracking

*Порушень Constitution Check немає — розділ не заповнюється.*
