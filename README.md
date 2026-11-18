# MentorAskBase — RAG AI-асистент по базі знань

Backend AI-асистента, який відповідає на питання **на основі завантажених документів** (PDF, DOCX, TXT). Документи нарізаються на фрагменти, перетворюються на вектори (OpenAI embeddings) і зберігаються в PostgreSQL + pgvector. На питання відповідає агент на OpenAI Responses API: він сам шукає по базі знань, за потреби — в інтернеті, і повертає відповідь із джерелами (документ + сторінка або веб-посилання з відсотком релевантності).

## Канали доступу

| Канал | Адреса | Для кого |
|---|---|---|
| REST API | `/api/v1/*` | інтеграції; доступ за Sanctum-токеном |
| Публічний демо-чат | `/chat` | показ замовнику; без входу, ліміт по IP |
| Telegram-бот | webhook `/api/telegram/webhook/{secret}` | користувачі після апруву модератором |
| Адмін-панель (Filament 3) | `/admin` | `admin` / `super_admin`; вхід за телефоном + кодом із Telegram |

## Можливості

- **Завантаження документів** — PDF, DOCX, TXT до 50 МБ; дедуплікація за хешем; асинхронна обробка в черзі.
- **OCR сканів** — сторінки PDF без текстового шару та картинки всередині DOCX розпізнає vision-модель.
- **Агентні відповіді** — пошук по базі знань (`search_knowledge_base`) і вбудований `web_search` (можна обмежити доменами); кількість кроків обмежена.
- **Контекст розмови** — сесії з таймаутом неактивності, переформулювання питання з урахуванням історії.
- **Мультимовність** — відповідь мовою питання (`uk`, `en`, `ru`); інтерфейс бота — мовою користувача.
- **Зворотний зв'язок** — оцінка відповіді `useful` / `not_useful`, журнал запитів і кроків агента.
- **Telegram-бот** — онбординг через спільний чат модерації, меню, список документів, AI-резюме документа, вільний чат з індикатором «думаю…», ліміти на користувача.
- **Ролі** — `user`, `moderator`, `admin`, `super_admin` (spatie/laravel-permission), журнал дій адміністраторів.

## Стек

- PHP 8.3+, Laravel 13, Livewire, Filament 3, Sanctum
- PostgreSQL 18 + pgvector (HNSW-індекс, cosine distance)
- OpenAI через `openai-php/laravel`: Responses API, embeddings, vision
- Парсинг: `smalot/pdfparser`, `phpoffice/phpword`, `spatie/pdf-to-image` (Imagick)
- Telegram: `irazasyed/telegram-bot-sdk`
- Docker: Laravel Sail (`laravel.test`, `pgsql`, `redis`)

## Як це влаштовано

```
Завантаження документа
  DocumentUploadService ─► ProcessDocumentJob (черга)
     ─► екстрактор за розширенням (PDF / PDF-скан → vision / DOCX / TXT)
     ─► ChunkingService ─► EmbeddingService ─► document_chunks (pgvector)

Питання (API, /chat, Telegram — усі через одну точку входу)
  QuestionAnsweringService::ask()
     ─► AnswerGenerationService (мова, промпт, один ретрай)
     ─► QueryRewriter (опційно)
     ─► AgentToolRunner: search_knowledge_base ⇄ web_search ─► відповідь + джерела
     ─► QueryLog + AgentToolStep
```

Усі налаштування моделей, нарізки, пошуку й лімітів — у `config/rag.php` (значення беруться з `.env`).

---

## Вимоги

Проєкт запускається **лише через Docker + Laravel Sail**. PHP, Composer, Node.js і Postgres на хост ставити не треба: усе працює в контейнерах (`laravel.test` з PHP 8.5, Composer, Node та Imagick; `pgsql` з pgvector; `redis`).

Потрібні:

- Docker (Docker Desktop на macOS / Windows)
- ключ OpenAI API
- для бота й входу в адмінку — Telegram-бот від [@BotFather](https://t.me/BotFather) і публічний HTTPS-URL (локально — ngrok)

## Швидкий старт

Усі команди виконуються з кореня проєкту через `./vendor/bin/sail`. Для зручності можна додати alias: `alias sail='./vendor/bin/sail'`.

### 1. Код і `.env`

```sh
git clone git@github.com:artem20210413/MentorAskBase.git
cd MentorAskBase
cp .env.example .env          # впишіть щонайменше OPENAI_API_KEY
```

### 2. Composer-залежності (одноразово)

Папки `vendor/` ще немає, тому й скрипта Sail теж. Ставимо залежності одноразовим контейнером:

```sh
docker run --rm \
    -u "$(id -u):$(id -g)" \
    -v "$(pwd):/var/www/html" \
    -w /var/www/html \
    laravelsail/php84-composer:latest \
    composer install --ignore-platform-reqs
```

`--ignore-platform-reqs` потрібен, бо в цьому образі немає Imagick. Сам застосунок працюватиме в Sail-образі, де всі розширення є.

### 3. Контейнери

```sh
./vendor/bin/sail up -d
```

Перший запуск збирає образ — це кілька хвилин.

> Якщо порт 80 зайнятий іншим проєктом, `laravel.test` не підніметься. Задайте в `.env` `APP_PORT=8000` і `APP_URL=http://localhost:8000`, потім знову `./vendor/bin/sail up -d`. Аналогічно для зайнятих 5432 / 6379: `FORWARD_DB_PORT` / `FORWARD_REDIS_PORT`.

### 4. pgvector (одноразово)

Init-скрипт Sail створює дві бази: `laravel` (робоча) і `testing` (для тестів) — лише при **першому** запуску з порожнім volume. Розширення `vector` треба ввімкнути в обох. Жодна міграція цього не робить, тож без цього кроку `migrate` впаде на типі `vector`:

```sh
./vendor/bin/sail exec pgsql psql -U sail -d laravel -c "CREATE EXTENSION IF NOT EXISTS vector;"
./vendor/bin/sail exec pgsql psql -U sail -d testing -c "CREATE EXTENSION IF NOT EXISTS vector;"
```

### 5. Ініціалізація застосунку

```sh
./vendor/bin/sail artisan key:generate
./vendor/bin/sail artisan migrate
./vendor/bin/sail artisan db:seed --class=RoleSeeder   # ролі user/moderator/admin/super_admin
./vendor/bin/sail artisan storage:link                 # прямі посилання на файли документів (/storage/...)
./vendor/bin/sail npm install
./vendor/bin/sail npm run build
```

> Не запускайте просто `db:seed`: `DatabaseSeeder` окрім ролей створює випадкового тестового super admin.

### 6. Воркер черги

Вебсервер уже працює в контейнері `laravel.test`. Документи ж обробляються у фоні, тому в окремому терміналі запустіть воркер:

```sh
./vendor/bin/sail artisan queue:work --queue=telegram,default
```

Без воркера документи залишаться в статусі `pending`. Для гарячого перезавантаження фронтенду під час розробки — `./vendor/bin/sail npm run dev`.

Готово: `http://localhost/chat` — демо-чат, `http://localhost/admin` — адмінка (вхід потребує налаштованого бота, див. нижче).

Зупинити: `./vendor/bin/sail stop`. Логи: `./vendor/bin/sail logs -f`.

---

## Telegram-бот і перший адміністратор

Вхід в `/admin` — лише за номером телефону + одноразовим кодом, який надсилає бот. Тому без налаштованого бота в адмінку не зайти.

1. **Створіть бота** в @BotFather → `TELEGRAM_BOT_TOKEN`.
2. **Згенеруйте секрет webhook** → `TELEGRAM_WEBHOOK_SECRET`:
   ```sh
   openssl rand -hex 24
   ```
3. **Створіть дві групи** і додайте в них бота:
   - чат модерації (сюди приходять картки заявок з кнопками апруву й відхилення) → `TELEGRAM_MODERATION_CHAT_ID`;
   - чат технічних помилок (бачить лише розробник) → `TELEGRAM_ERROR_CHAT_ID`.

   ID групи можна дізнатися, написавши в неї повідомлення й відкривши `https://api.telegram.org/bot<TOKEN>/getUpdates` — це працює, **поки webhook ще не встановлено**. ID груп від'ємні (`-100…`).
4. **Встановіть webhook** на публічний HTTPS-URL (локально — `ngrok http 80` або ваш `APP_PORT`):
   ```sh
   ./vendor/bin/sail artisan telegram:webhook-set https://<host>/api/telegram/webhook/<TELEGRAM_WEBHOOK_SECRET>
   ```
5. **Зареєструйтеся в боті**: `/start` → «Поділитися контактом». Заявка потрапить у чат модерації.
6. **Підвищіть свій акаунт до super admin:**
   ```sh
   ./vendor/bin/sail artisan telegram:create-super-admin --phone=+380XXXXXXXXX
   ```
   Команда покаже знайдений акаунт і попросить підтвердження. Після цього акаунт стає `approved` + `super_admin`.
7. Відкрийте `/admin`, введіть номер — код прийде в бот.

> **Порядок кроків 5 → 6 важливий.** Код входу надсилається в Telegram-чат, прив'язаний до акаунта. Якщо спершу виконати `create-super-admin` для номера, якого ще немає, акаунт створиться без прив'язки до Telegram, а спроба потім поділитися контактом у боті отримає відмову «номер уже зареєстровано» — код входу нікуди буде надсилати.

### Як обробляються update бота

Webhook завжди відповідає Telegram `200` одразу, а сама обробка йде за `TELEGRAM_UPDATES_VIA_QUEUE`:

- `false` (за замовчуванням) — у тому ж PHP-процесі одразу після відповіді. Воркер не потрібен — підходить для хостингу без постійних процесів.
- `true` — у черзі `telegram`; потрібен воркер `./vendor/bin/sail artisan queue:work --queue=telegram,default`.

---

## REST API

Видайте токен технічному клієнту:

```sh
./vendor/bin/sail artisan rag:issue-token my-client
```

Усі запити — з заголовком `Authorization: Bearer <token>`. Ліміт — `RAG_RATE_LIMIT_PER_MINUTE` на токен. Помилки завжди в JSON.

| Метод | Шлях | Що робить |
|---|---|---|
| `POST` | `/api/v1/documents` | завантажити документ (`multipart`, поле `file`: pdf/docx/txt) → `201 pending` або `200 duplicate` |
| `GET` | `/api/v1/documents` | список документів зі статусами |
| `GET` | `/api/v1/documents/{id}` | статус документа (`pending` / `processing` / `processed` / `failed` + `failure_reason`) |
| `DELETE` | `/api/v1/documents/{id}` | м'яке видалення (фізично — через `RAG_PERMANENT_DELETION_AFTER_DAYS`) |
| `POST` | `/api/v1/queries` | питання: `{"question": "...", "session_id": "uuid?"}` → відповідь, джерела, `session_id` |
| `GET` | `/api/v1/queries` | журнал запитів |
| `POST` | `/api/v1/queries/{id}/feedback` | оцінка: `{"rating": "useful" \| "not_useful", "comment": "..."}` |

```sh
curl -X POST http://localhost/api/v1/queries \
  -H "Authorization: Bearer <token>" -H "Content-Type: application/json" \
  -d '{"question": "Яка гарантія на виріб?"}'
```

Повний контракт — `specs/001-rag-ai-bot-backend/contracts/api.md`.

---

## Змінні оточення

Повний перелік з коментарями — у [`.env.example`](.env.example). Нижче — що треба заповнити і що варто знати.

### Обов'язкові

| Змінна | Що вказати |
|---|---|
| `APP_KEY` | `./vendor/bin/sail artisan key:generate` |
| `APP_URL` | публічна адреса; з неї будуються посилання на файли документів у відповідях |
| `DB_*` | Postgres з pgvector. Для Sail залиште як у прикладі: `pgsql` / `5432` / `laravel` / `sail` / `password` |
| `OPENAI_API_KEY` | ключ OpenAI |
| `TELEGRAM_BOT_TOKEN` | токен бота — потрібен і для бота, і для входу в адмінку |
| `TELEGRAM_WEBHOOK_SECRET` | випадковий рядок, частина URL webhook |
| `TELEGRAM_MODERATION_CHAT_ID` | ID групи модерації |

### Рекомендовані

| Змінна | За замовчуванням | Навіщо |
|---|---|---|
| `TELEGRAM_ERROR_CHAT_ID` | — | технічні помилки бота в окремий чат; без неї — лише в лог |
| `TELEGRAM_UPDATES_VIA_QUEUE` | `false` | див. «Як обробляються update бота» |
| `DB_QUEUE_RETRY_AFTER` | `90` | **ставте `660`**: обробка документа може тривати до 600 с, інакше її запустять повторно |
| `SESSION_LIFETIME` | `120` | тривалість входу в адмінку, хв (у прикладі `480` = 8 год) |
| `APP_LOCALE` | `uk` | мова адмінки, `/chat`, повідомлень API |

### OpenAI

| Змінна | У прикладі | Опис |
|---|---|---|
| `OPENAI_CHAT_MODEL` | `gpt-4o` | модель відповідей і агента |
| `OPENAI_VISION_MODEL` | `gpt-4o` | OCR сканів і картинок |
| `OPENAI_EMBEDDING_MODEL` | `text-embedding-3-small` | модель ембедингів |
| `OPENAI_EMBEDDING_DIMENSIONS` | `1536` | розмірність вектора; фіксується міграцією — змінювати лише на порожній базі |
| `OPENAI_REQUEST_TIMEOUT` | `180` | таймаут запиту, с |
| `OPENAI_TEMPERATURE` / `OPENAI_TOP_P` | `0.2` / `0.9` | параметри генерації (reasoning-моделі їх не приймають) |
| `OPENAI_MAX_TOKENS` | `500` | ліміт довжини відповіді; порожньо — без ліміту |
| `OPENAI_QUERY_REFINEMENT_ENABLED` | `true` | переформулювати питання з урахуванням історії |

### RAG

| Змінна | У прикладі | Опис |
|---|---|---|
| `RAG_SUPPORTED_LANGUAGES` | `uk,en,ru` | мови відповідей; перша — за замовчуванням |
| `RAG_SESSION_TIMEOUT_MINUTES` | `60` | неактивність, після якої розмова починається заново |
| `RAG_MAX_UPLOAD_MB` | `50` | максимальний розмір файлу |
| `RAG_RATE_LIMIT_PER_MINUTE` | `30` | ліміт на API-токен і на IP для `/chat` |
| `RAG_SEARCH_TOP_K` | `3` | скільки фрагментів передавати моделі |
| `RAG_SEARCH_MIN_RELEVANCE` | `30` | поріг релевантності фрагмента, % |
| `RAG_CHUNK_MAX_LENGTH` | `700` | довжина фрагмента, символів |
| `RAG_AGENT_MAX_STEPS` | `4` | ліміт викликів інструментів на одне питання |
| `RAG_WEB_SEARCH_ALLOWED_DOMAINS` | — | домени через кому для `web_search`; порожньо — будь-які |
| `RAG_DOCUMENT_DISK` | `public` | диск для файлів; `public` = пряме посилання (потрібен `storage:link`) |
| `RAG_PERMANENT_DELETION_AFTER_DAYS` | `30` | через скільки днів видалені документи стираються фізично |

### Telegram: ліміти й дрібниці

| Змінна | За замовчуванням | Опис |
|---|---|---|
| `TELEGRAM_MODERATION_LANGUAGE` | `uk` | мова карток у чаті модерації |
| `TELEGRAM_OTP_TTL_MINUTES` / `TELEGRAM_OTP_MAX_ATTEMPTS` | `5` / `3` | час життя коду входу і кількість спроб |
| `TELEGRAM_OTP_REQUESTS_PER_MINUTE` | `3` | запитів коду на хвилину на один номер |
| `TELEGRAM_OTP_REQUESTS_PER_IP_PER_MINUTE` / `TELEGRAM_OTP_VERIFY_PER_IP_PER_MINUTE` | `5` / `10` | захист від перебору з одного IP (за reverse proxy потрібен `trustProxies`) |
| `TELEGRAM_FREE_CHAT_PER_MINUTE` / `TELEGRAM_FREE_CHAT_PER_DAY` | `10` / `100` | ліміт питань у боті на користувача |
| `TELEGRAM_QUESTION_MAX_LENGTH` | `2000` | максимальна довжина питання |
| `TELEGRAM_REMINDER_TIME` | `09:00` | час щоденного нагадування про необроблені заявки |
| `TELEGRAM_DOCS_PAGE_SIZE` | `20` | документів на сторінку в боті |

---

## Продакшн: cron і черга

Розділ стосується сервера, де застосунок працює без Docker (команди `php artisan` — на самому сервері). Планувальник (`routes/console.php`) щодня чистить видалені документи, тимчасові файли Livewire і старі логи webhook, а також надсилає нагадування про заявки. Додайте в cron:

```cron
* * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1
```

Для обробки документів потрібен воркер черги (`php artisan queue:work`). Якщо постійні процеси на хостингу недоступні, воркер можна запускати з cron з `--stop-when-empty`.

Чекліст деплою: `APP_ENV=production`, `APP_DEBUG=false`, свій `APP_KEY`, HTTPS-`APP_URL`, `migrate --force`, `db:seed --class=RoleSeeder`, `storage:link`, `npm run build`, `config:cache` / `route:cache`, webhook на прод-домен.

## Тести

```sh
./vendor/bin/sail artisan test
./vendor/bin/sail artisan test --filter=OnboardingTest
./vendor/bin/sail pint                   # код-стайл
```

Тести працюють на Postgres-базі `testing` (не SQLite — потрібен pgvector), з `RefreshDatabase`, синхронною чергою і замоканими OpenAI / Telegram. Telegram-змінні для тестів задані в `phpunit.xml`.

## Artisan-команди проєкту

Запуск: `./vendor/bin/sail artisan <команда>`.

| Команда | Опис |
|---|---|
| `rag:issue-token {name}` | видати Sanctum-токен для API |
| `telegram:create-super-admin --phone=...` | створити / підвищити super admin |
| `telegram:webhook-set {url}` | зареєструвати webhook бота |
| `rag:purge-deleted-documents` | фізично видалити старі м'яко видалені документи (щодня) |
| `rag:purge-livewire-tmp` | почистити тимчасові файли завантаження (щодня) |
| `telegram:send-approval-reminders` | нагадати про заявки, старші 12 год (щодня о `TELEGRAM_REMINDER_TIME`) |
| `telegram:purge-webhook-logs {days?}` | видалити старі логи webhook (щодня) |
