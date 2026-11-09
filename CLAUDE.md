# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project

Laravel 13 (PHP 8.3+) backend for a RAG AI assistant over an uploaded knowledge base (PDF/DOCX/TXT). Channels: token-protected REST API, a public Livewire demo chat (`/chat`), a Telegram bot, and a Filament 3 admin panel. LLM/embeddings via `openai-php/laravel` (Responses API with tools), vectors in Postgres + pgvector.

**Communicate with the user in Ukrainian** (`.specify/memory/constitution.md`, Principle I). Code comments in this repo are in Ukrainian too and frequently reference spec requirement IDs (`FR-009a`, `contracts/telegram-webhook.md`) — keep that style when touching related code.

## Commands

```sh
composer setup                 # install, .env, key, migrate, npm build
composer dev                   # php artisan dev (server + queue + vite)
php artisan test               # all tests (or: composer test)
php artisan test --filter=OnboardingTest
php artisan test tests/Feature/Telegram/FreeChatTest.php
./vendor/bin/pint              # code style (Laravel Pint)
php artisan queue:work --queue=telegram,default   # bot updates + document ingestion (tests use sync queue)
```

Docker via Sail (`compose.yaml`): `laravel.test`, `pgsql` (`pgvector/pgvector:pg18`), `redis`. Tests run against Postgres database `testing` (created by Sail's init script) — not SQLite, because pgvector is required.

Local environment notes:
- Port 80 is often taken by other projects' containers, so `laravel.test` may not start. Run `php artisan` on the host against the dockerized Postgres with `DB_HOST=127.0.0.1` (the `.env` value `pgsql` only resolves inside the docker network).
- Host PHP lacks `ext-imagick`: use `composer ... --ignore-platform-req=ext-imagick`; `tests/Unit/PdfVisionExtractorTest.php` fails with `Class "Imagick" not found` on the host — environmental, not a regression.
- `DocumentUploadPageTest::test_uploading_document_via_web_form_creates_pending_document` is a known pre-existing failure.

Project artisan commands:
- `rag:issue-token {name}` — Sanctum token for an API client
- `telegram:create-super-admin --phone=...` — bootstrap first admin (admin login is by phone)
- `telegram:webhook-set {url}` — register the bot webhook
- Scheduled (`routes/console.php`): `rag:purge-deleted-documents`, `rag:purge-livewire-tmp`, `telegram:send-approval-reminders`, `telegram:purge-webhook-logs`

## Architecture

### Single Q&A entry point
`App\Services\Rag\QuestionAnsweringService::ask()` is the only way any channel (API `QueryController`, `Livewire\PublicChat`, Telegram `FreeChatService`) gets an answer. It resolves the conversation session (unknown/expired → new session silently), loads history, calls `AnswerGenerationService`, and writes a `QueryLog` + `AgentToolStep` rows. New channels must go through it, not call the LLM directly.

Answer pipeline: `AnswerGenerationService` (language detection, system instructions, single retry via `OpenAiRetry`, `NO_INFO_MARKER` handling) → optional `QueryRewriter` → `AgentToolRunner`, an agent loop on the OpenAI Responses API with a function tool `search_knowledge_base` (`KnowledgeBaseSearchTool` → `VectorSearchService` → pgvector) plus the built-in `web_search` tool (optionally domain-restricted). Loop is capped by `rag.agent.max_tool_steps`, then a final answer is forced. Sources (document+page or web URL with relevance %) are stored as JSON on `query_logs.source_document_ids`.

All LLM generation params go through `App\Services\LlmOptions` from `config/rag.php` — the central config for models, chunking, top_k, relevance threshold, rate limits, session timeout, supported languages. Prefer adding env-backed keys there over hardcoding.

### Document ingestion
`DocumentUploadService` (sha-hash dedup under a `Cache::lock`, readability check, stores on `rag.document_disk`, default `public`) → dispatches `ProcessDocumentJob` (queued). The job picks an extractor by extension (`PdfTextExtractor` with `PdfVisionExtractor`/`VisionTranscriber` fallback for image pages, `WordTextExtractor` incl. embedded images, `TextFileExtractor` with encoding detection) → `ChunkingService` → `EmbeddingService` → `document_chunks`. Documents are soft-deleted and purged physically later by a scheduled command. "Replacing" a document = delete + upload new.

### Telegram bot
- Webhook route lives in `routes/api.php` (`/api/telegram/webhook/{secret}`) to avoid CSRF. `TelegramWebhookController` only checks the secret, dedups by `update_id` (unique constraint on `telegram_webhook_logs`), dispatches `ProcessTelegramUpdateJob` to the `telegram` queue and always returns 200 (Telegram retries otherwise). The job calls `TelegramUpdateHandler`, which routes `callback_query`/`message` to services in `app/Services/Telegram` and reports errors to the error chat. No retries (`tries = 1`) to avoid double answers.
- Contacts are accepted only if `contact.user_id === from.id` (own number); `telegram:create-super-admin` asks for confirmation before promoting an existing account.
- `TelegramClient` is a thin own wrapper over `irazasyed/telegram-bot-sdk`; it reads config only from `config('services.telegram.*')` (not the package's `config/telegram.php`). Tests mock `TelegramClient`.
- Onboarding requires moderator approval: request cards go to one shared moderation group chat (`TELEGRAM_MODERATION_CHAT_ID`), always in `TELEGRAM_MODERATION_LANGUAGE`. Webhook errors go to a separate `TELEGRAM_ERROR_CHAT_ID` — keep error and business notifications in different chats.
- User-facing bot texts are translated in `resources/lang/{uk,ru,en}/telegram.php`, chosen by `users.language`.

### Auth & admin
- API: Sanctum tokens + `throttle:rag-api` limiter (registered in `AppServiceProvider::boot`, not in routing callbacks, so it survives `route:cache`). API errors always render JSON (`bootstrap/app.php`).
- Filament admin login is phone + one-time code sent via Telegram (`Filament/Pages/Auth/PhoneLogin`, `OneTimeCodeService`); `EnsureAdminSessionIsValid` invalidates sessions when role/status changes.
- Roles via `spatie/laravel-permission`: `user`, `moderator`, `admin`, `super_admin` (`App\Enums\UserRole`, `RoleSeeder`). Admin actions are recorded via `AdminActionLogger`.

### Tests
`tests/TestCase.php` uses `RefreshDatabase` for everything. Helpers in `tests/Support`: `FakesAgentResponses` (builds `OpenAI` Responses fakes for final answers / tool calls — use with `OpenAI::fake()`), `PostsTelegramUpdates` (webhook payload builders), `SeedsRoles`, `GeneratesTestPdf`. Telegram env values for tests are set in `phpunit.xml`.

## Workflow (Spec Kit)

Features are developed through Spec Kit: `specs/NNN-feature/` holds `spec.md`, `plan.md`, `tasks.md`, `data-model.md`, `contracts/`. Use the `speckit-*` skills (specify → clarify → plan → tasks → analyze → implement). Constitution (`.specify/memory/constitution.md`) mandates: test-first (tests required for business logic, run `php artisan test` before finishing), YAGNI / prefer built-in Laravel features, no silently swallowed exceptions, reversible migrations with `down()`, no undocumented changes to public API contracts. Client-facing documents (ТЗ, estimates) live in `docs/`.
