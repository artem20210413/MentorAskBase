# Quickstart: RAG AI Bot Backend (API MVP)

Мета — довести наскрізний сценарій «завантажив документ → отримав відповідь
з джерелами → залишив зворотний зв'язок» на локальному середовищі Sail.

## Передумови

- Docker Desktop запущений
- Composer, PHP 8.3 (лише для первинної установки Sail-скриптів)
- Ключ API обраного LLM-провайдера (OpenAI або Gemini — див. `research.md` #2)

## Налаштування середовища

```bash
composer require laravel/sail --dev
php artisan sail:install --with=pgsql,redis
./vendor/bin/sail up -d
```

У `.env`:
```env
DB_CONNECTION=pgsql
DB_HOST=pgsql
DB_PORT=5432

OPENAI_API_KEY=sk-...
RAG_SUPPORTED_LANGUAGES=uk,en
RAG_SESSION_TIMEOUT_MINUTES=60
RAG_MAX_UPLOAD_MB=50
RAG_RATE_LIMIT_PER_MINUTE=30
```

Увімкнути розширення `pgvector` (одноразово, після першого підняття
контейнера `pgsql`):

```bash
./vendor/bin/sail exec pgsql psql -U sail -d laravel -c "CREATE EXTENSION IF NOT EXISTS vector;"
```

Міграції та видача технічного токена:

```bash
./vendor/bin/sail artisan migrate
./vendor/bin/sail artisan tinker --execute="
  \$token = \App\Models\User::factory()->create()->createToken('api-test');
  echo \$token->plainTextToken;
"
```

Запустити воркер черги (обробка документів асинхронна, FR-006):

```bash
./vendor/bin/sail artisan queue:work
```

## Сценарій 1 — завантаження документа (User Story 1)

```bash
curl -X POST http://localhost/api/v1/documents \
  -H "Authorization: Bearer <TOKEN>" \
  -F "file=@./manual.pdf"
```

Очікування: `201 Created` зі `status: "pending"`. Перевірити прогрес:

```bash
curl http://localhost/api/v1/documents/{id} -H "Authorization: Bearer <TOKEN>"
```

Дочекатися `status: "processed"` (FR-007). Для документа з переважно
сканованими сторінками — переконатися, що воркер чергу відпрацював без
помилок (`status` не залишився `failed` — інакше перевірити `failure_reason`).

Повторне завантаження того самого файлу MUST одразу повернути
`status: "duplicate"` (FR-002/003, SC-003).

## Сценарій 2 — питання з джерелами (User Story 2, 5)

```bash
curl -X POST http://localhost/api/v1/queries \
  -H "Authorization: Bearer <TOKEN>" -H "Content-Type: application/json" \
  -d '{"question": "Яка гарантія на виріб X?"}'
```

Очікування: `200 OK`, поле `answer` містить фактично коректну відповідь
(SC-002), `sources` — непорожній масив з назвою завантаженого документа
(SC-009), `session_id` присутній у відповіді.

## Сценарій 3 — продовження розмови (User Story 3)

```bash
curl -X POST http://localhost/api/v1/queries \
  -H "Authorization: Bearer <TOKEN>" -H "Content-Type: application/json" \
  -d '{"question": "А для виробу Y?", "session_id": "<session_id з кроку 2>"}'
```

Очікування: відповідь враховує контекст попереднього питання (SC-004).

## Сценарій 4 — зворотний зв'язок (User Story 6)

```bash
curl -X POST http://localhost/api/v1/queries/{query_log_id}/feedback \
  -H "Authorization: Bearer <TOKEN>" -H "Content-Type: application/json" \
  -d '{"rating": "useful"}'
```

Очікування: `200 OK`, повторний виклик з іншим `rating` замінює попередню
оцінку (не створює дублікат).

## Сценарій 5 — керування через веб-сторінку (User Story 4)

Відкрити `http://localhost/admin/documents` (Filament-ресурс) без входу в
систему (FR-019): переконатися, що список документів, форма завантаження й
дія видалення (soft delete, FR-017a) працюють так само, як відповідні API-
ендпоінти вище.

## Перевірка автоматизованими тестами

```bash
./vendor/bin/sail artisan test
```

Очікування: усі Feature/Unit-тести з `tasks.md` (буде згенеровано
`/speckit-tasks`) проходять, включно з тестами на edge cases зі spec.md
(невідома сесія, перевищення rate limit, збій LLM з одним ретраєм тощо).

## Довідково

- Повний опис контрактів — `contracts/api.md`
- Модель даних — `data-model.md`
- Обґрунтування технологічних рішень — `research.md`
