# Tasks: Telegram-бот + авторизація та ролі

**Input**: Design documents from `/specs/004-telegram-bot-auth-roles/`

**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/, quickstart.md (усі наявні)

**Tests**: Включені — Принцип II конституції (Test-First, NON-NEGOTIABLE) вимагає тест на кожен FR до або одночасно з реалізацією; `plan.md` Constitution Check явно відкладає це виконання на `/speckit-tasks`.

**Organization**: Завдання згруповані за user story зі spec.md (US1-US4) для незалежної реалізації й тестування кожної.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Можна виконувати паралельно (різні файли, без залежності від незавершених завдань)
- **[Story]**: До якої user story належить завдання (US1-US4)
- Шляхи файлів — точні, відповідають `plan.md` → Project Structure

## Path Conventions

Один Laravel-моноліт (існуючий застосунок) — усі шляхи відносно кореня репозиторію: `app/`, `database/migrations/`, `routes/`, `config/`, `tests/Feature/`.

---

## Phase 1: Setup (Shared Infrastructure)

**Purpose**: Залежності та базова конфігурація, потрібні для будь-якої подальшої роботи

- [X] T001 Встановити `composer require spatie/laravel-permission irazasyed/telegram-bot-sdk propaganistas/laravel-phone` і опублікувати міграцію/конфіг `spatie/laravel-permission` (`php artisan vendor:publish --provider="Spatie\Permission\PermissionServiceProvider"`)
- [X] T002 [P] Додати блок `'telegram'` у `config/services.php` (`bot_token`, `webhook_secret`, `moderation_chat_id`, `moderation_language`, `otp_ttl_minutes`, `otp_max_attempts`, `reminder_time`, `docs_page_size`) за переліком з `data-model.md` → "Конфігураційні значення"
- [X] T003 [P] Перевірити відповідність секції Telegram у `.env.example` ключам з T002 (значення вже чернеткою додані під час `/speckit-clarify`) і підняте значення `SESSION_LIFETIME=480`

**Checkpoint**: Залежності встановлено, конфігурація готова.

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: Інфраструктура, без якої жодна user story не може бути реалізована

**⚠️ CRITICAL**: Жодна робота над US1-US4 не починається до завершення цієї фази

- [X] T004 [P] Міграція: додати до `users` колонки `phone`, `status`, `telegram_chat_id`, `telegram_username`, `language`, `rejection_count`, `last_rejected_at`, `sessions_invalidated_at` у `database/migrations/xxxx_add_telegram_fields_to_users_table.php` (data-model.md → User)
- [X] T005 [P] Міграція: створити таблицю `admin_action_logs` (`actor_user_id`, `target_user_id`, `action`, `metadata`, `created_at`) у `database/migrations/xxxx_create_admin_action_logs_table.php`
- [X] T006 [P] Сидер ролей `user`/`moderator`/`admin`/`super_admin` через `spatie/laravel-permission` у `database/seeders/RoleSeeder.php`, зареєструвати в `database/seeders/DatabaseSeeder.php`
- [X] T007 Оновити `app/Models/User.php`: трейт `HasRoles`, `fillable`/`casts` для нових колонок із T004, `canAccessPanel()` за `contracts/web-auth.md` (depends on T004, T006)
- [X] T008 [P] Створити `app/Models/AdminActionLog.php` з relations `actor()`/`target()` до `User` (depends on T005)
- [X] T009 Створити `app/Services/Admin/AdminActionLogger.php` — метод `log(User $actor, ?User $target, string $action, array $metadata = [])` (FR-026) (depends on T008)
- [X] T010 [P] Створити `app/Services/Telegram/TelegramClient.php` — тонка обгортка над `irazasyed/telegram-bot-sdk`: `sendMessage`, `editMessageText`, `editMessageReplyMarkup`, `answerCallbackQuery` (research.md #1)
- [X] T011 Створити `app/Http/Controllers/TelegramWebhookController.php`: перевірка `{secret}` з `services.telegram.webhook_secret` (404 при невідповідності), парсинг `Update`, диспетчеризація за типом (`message`/`message.contact`/`callback_query`) до заглушок, завжди відповідь `200` (contracts/telegram-webhook.md) (depends on T010)
- [X] T012 Зареєструвати маршрут `POST /telegram/webhook/{secret}` у `routes/web.php` (depends on T011)
- [X] T013 [P] Створити `app/Console/Commands/CreateSuperAdminCommand.php` (`telegram:create-super-admin --phone=`) — нормалізація телефону через `propaganistas/laravel-phone`, призначення ролі `super_admin` (FR-025) (depends on T007)
- [X] T014 [P] Створити `app/Console/Commands/TelegramWebhookSetCommand.php` (`telegram:webhook-set {url}`) — викликає `setWebhook` Bot API (depends on T010)

**Checkpoint**: Ролі існують, `users` розширено, webhook відповідає `200`, супер-адміна можна створити — можна починати будь-яку user story.

---

## Phase 3: User Story 1 - Онбординг та апрув нового користувача бота (Priority: P1) 🎯 MVP (частина 1)

**Goal**: Новий користувач проходить онбординг (`/start` → контакт) і отримує рішення (апрув/відхилення) через картку в груповому чаті модерації.

**Independent Test**: Новий telegram-акаунт пише боту, ділиться контактом; картка з'являється в чаті модерації; натискання "Одобрити"/"Відхилити" дає заявнику відповідний результат, повторне натискання ігнорується.

### Tests for User Story 1 ⚠️ (писати першими, мають падати до реалізації)

- [X] T015 [P] [US1] Feature-тест онбордингу (`/start` + вітання + запит контакту) у `tests/Feature/Telegram/OnboardingTest.php` (FR-002, FR-003)
- [X] T016 [P] [US1] Feature-тест відмови при дублікаті телефону у `tests/Feature/Telegram/OnboardingDuplicatePhoneTest.php` (FR-003a)
- [X] T017 [P] [US1] Feature-тест надсилання картки заявки в чат модерації у `tests/Feature/Telegram/ApprovalRequestCreatedTest.php` (FR-004)
- [X] T018 [P] [US1] Feature-тест обробки callback (перевірка ролі, апрув/відхилення, ігнорування повторного натискання) у `tests/Feature/Telegram/ApprovalDecisionTest.php` (FR-004b, FR-005, FR-006)
- [X] T019 [P] [US1] Feature-тест щоденного нагадування (≥12г з моменту створення, конфігурована година) у `tests/Feature/Telegram/ApprovalReminderTest.php` (FR-007, FR-008)
- [X] T020 [P] [US1] Feature-тест повторної заявки за шкалою очікування 24г/48г/72г/1тиждень/1місяць у `tests/Feature/Telegram/ReapplicationCooldownTest.php` (FR-006b)

### Implementation for User Story 1

- [X] T021 [P] [US1] Модель `app/Models/UserApprovalRequest.php` + міграція `database/migrations/xxxx_create_user_approval_requests_table.php` (data-model.md → UserApprovalRequest)
- [X] T022 [US1] `app/Services/Telegram/OnboardingService.php` — обробка `/start` і `message.contact`, перевірка дубліката телефону (FR-002, FR-003, FR-003a) (depends on T021)
- [X] T023 [US1] `app/Services/Telegram/ApprovalRequestService.php` — створення заявки, розсилка картки, атомарна обробка callback-рішення, запис в `AdminActionLog` (FR-004, FR-004b, FR-005, FR-006) (depends on T021, T009, T010)
- [X] T024 [P] [US1] `app/Services/Telegram/ReapplicationCooldownService.php` — розрахунок наступного дозволеного часу заявки за `rejection_count`/`last_rejected_at` (FR-006b)
- [X] T025 [US1] `app/Console/Commands/SendApprovalRemindersCommand.php` — пошук pending-заявок старших 12г без нагадування сьогодні, повторна картка + деактивація старого повідомлення (FR-007, FR-008) (depends on T021, T010)
- [X] T026 [US1] Зареєструвати `Schedule::command('telegram:send-approval-reminders')->dailyAt(config('services.telegram.reminder_time'))` у `routes/console.php` (depends on T025)
- [X] T027 [US1] Підключити `OnboardingService` і `ApprovalRequestService` до `TelegramWebhookController` замість заглушок із T011 (depends on T022, T023)

**Checkpoint**: US1 повністю функціональна й незалежно тестована.

---

## Phase 4: User Story 2 - Доступ до функцій бота для approved-користувача (Priority: P1) 🎯 MVP (частина 2)

**Goal**: Схвалений користувач користується меню бота (мова, довідка, список документів, резюме) і вільним чатом з AI.

**Independent Test**: З уже approved-акаунтом відкрити меню, обрати мову, отримати список документів, запросити резюме документа, написати вільне питання й отримати відповідь.

### Tests for User Story 2 ⚠️

- [X] T028 [P] [US2] Feature-тест головного меню і зміни мови у `tests/Feature/Telegram/BotMenuTest.php` (FR-010, FR-011)
- [X] T029 [P] [US2] Feature-тест списку документів з пагінацією (`docs_page:n`) і виключенням (м'яко) видалених документів у `tests/Feature/Telegram/DocumentListTest.php` (FR-012, FR-012a)
- [X] T030 [P] [US2] Feature-тест AI-резюме документа і кешу за документ+мова, включно з відмовою для видаленого документа у `tests/Feature/Telegram/DocumentSummaryTest.php` (FR-012b, FR-013, FR-014)
- [X] T031 [P] [US2] Feature-тест вільного чату через RAG-пайплайн і обробки помилки/таймауту AI у `tests/Feature/Telegram/FreeChatTest.php` (FR-015, FR-015a, FR-016)
- [X] T032 [P] [US2] Feature-тест відмови pending/rejected/deactivated користувачу на будь-яке повідомлення у `tests/Feature/Telegram/AccessDeniedTest.php` (FR-009)

### Implementation for User Story 2

- [X] T033 [P] [US2] Модель `app/Models/DocumentSummary.php` + міграція `database/migrations/xxxx_create_document_summaries_table.php` (унікальний індекс `document_id`+`locale`)
- [X] T034 [P] [US2] `app/Services/Telegram/BotMenuService.php` — меню, зміна мови (`users.language`), список документів+пагінація, виключаючи (м'яко) видалені документи (стандартний `SoftDeletes`-скоуп) (FR-010, FR-011, FR-012, FR-012a) (depends on T010)
- [X] T035 [US2] `app/Services/Telegram/DocumentSummaryService.php` — резюме через існуючий RAG/AI-клієнт + кеш у `DocumentSummary`; відмова, якщо документ (м'яко) видалений (FR-012b, FR-013, FR-014) (depends on T033)
- [X] T036 [P] [US2] `app/Services/Telegram/FreeChatService.php` — виклик існуючого `QuestionAnsweringService` з `ConversationSession` прив'язаною до `chat_id`, дружнє повідомлення при помилці/таймауті (FR-015, FR-015a, FR-016)
- [X] T037 [P] [US2] Перевірка статусу користувача (pending/rejected/deactivated → відмова, FR-009) як guard-крок перед диспетчеризацією в `TelegramWebhookController`
- [X] T038 [US2] Підключити `BotMenuService`, `DocumentSummaryService`, `FreeChatService` і guard з T037 до `TelegramWebhookController` (depends on T034, T035, T036, T037)

**Checkpoint**: US1+US2 разом утворюють повний MVP бот-флоу.

---

## Phase 5: User Story 3 - Авторизація та доступ до веб-форми завантаження документів (Priority: P2)

**Goal**: Admin/super_admin входить на Filament-панель за номером телефону й одноразовим кодом з бота; сесія діє 8 годин і негайно анулюється при зміні ролі/статусу.

**Independent Test**: Ввести телефон admin-користувача на сторінці входу, отримати код у боті, ввести код, отримати доступ до `DocumentResource`; невідповідна роль чи невідомий номер отримують відмову без деталей.

### Tests for User Story 3 ⚠️

- [X] T039 [P] [US3] Feature-тест кроку 1 входу (пошук за телефоном, однакова відмова для невідомого номера/невідповідної ролі) у `tests/Feature/Auth/PhoneLoginStepOneTest.php` (FR-017, FR-017a, FR-018)
- [X] T040 [P] [US3] Feature-тест rate limit 3 коди/хв на номер у `tests/Feature/Auth/OtpRateLimitTest.php` (FR-019b)
- [X] T041 [P] [US3] Feature-тест анулювання попереднього коду новим запитом у `tests/Feature/Auth/OtpInvalidatesPreviousTest.php` (FR-019a)
- [X] T042 [P] [US3] Feature-тест перевірки коду (успіх / 3 невдалі спроби / TTL минув) у `tests/Feature/Auth/PhoneLoginVerifyTest.php` (FR-019, FR-020)
- [X] T043 [P] [US3] Feature-тест негайної інвалідації сесії при зміні ролі/деактивації у `tests/Feature/Auth/AdminSessionInvalidationTest.php` (FR-023a)

### Implementation for User Story 3

- [X] T044 [P] [US3] Модель `app/Models/OneTimeCode.php` + міграція `database/migrations/xxxx_create_one_time_codes_table.php` (включно з `invalidated_at`)
- [X] T045 [US3] `app/Services/Auth/OneTimeCodeService.php` — генерація (з анулюванням попередніх кодів, FR-019a), rate limit через `Illuminate\Cache\RateLimiter` (FR-019b), перевірка з лічильником спроб (FR-019) (depends on T044)
- [X] T046 [US3] `app/Filament/Pages/Auth/PhoneLogin.php` — 2-крокова форма (телефон → код), викликає `OneTimeCodeService` і `TelegramClient::sendMessage` (depends on T045, T010)
- [X] T047 [P] [US3] `app/Services/Auth/AdminSessionGuardService.php` — порівняння `session('logged_in_at')` з `users.sessions_invalidated_at` і поточної ролі/статусу
- [X] T048 [US3] `app/Http/Middleware/EnsureAdminSessionIsValid.php` (depends on T047)
- [X] T049 [US3] Оновити `app/Providers/Filament/AdminPanelProvider.php`: `->login(PhoneLogin::class)`, додати middleware з T048, прибрати коментар про FR-019 (відсутність авторизації з фічі 001) (depends on T046, T048)

**Checkpoint**: US3 незалежно тестована — вхід за телефоном+кодом працює, сесія анулюється миттєво при зміні ролі.

---

## Phase 6: User Story 4 - Керування користувачами (Priority: P3)

**Goal**: Super admin керує ролями/статусами користувачів на окремій сторінці з журналом дій; не може знизити/деактивувати себе.

**Independent Test**: Super admin відкриває сторінку керування користувачами, змінює роль одному користувачу, деактивує іншого, бачить обидві дії в журналі; спроба дії на себе блокується.

### Tests for User Story 4 ⚠️

- [X] T050 [P] [US4] Feature-тест видимості сторінки лише для `super_admin` у `tests/Feature/Admin/UserResourceAccessTest.php` (FR-021)
- [X] T051 [P] [US4] Feature-тест зміни ролі/деактивації: `sessions_invalidated_at` оновлюється, пишеться `AdminActionLog` у `tests/Feature/Admin/UserRoleChangeTest.php` (FR-022, FR-023, FR-023a, FR-026)
- [X] T052 [P] [US4] Feature-тест заборони дії на самого себе у `tests/Feature/Admin/SelfProtectionTest.php` (FR-024)
- [X] T053 [P] [US4] Feature-тест журналу дій (включно із записами апрову з US1) у `tests/Feature/Admin/AdminActionLogPageTest.php` (FR-026) — записи створені напряму через модель, а не через реальний прогон US1-флоу (незалежна тестованість, п. U2 аналізу)

### Implementation for User Story 4

- [X] T054 [US4] `app/Filament/Resources/UserResource.php` — список (телефон/ім'я з Telegram/роль/статус), дії зміни ролі та деактивації/активації, доступ лише `super_admin` (FR-021, FR-022, FR-023)
- [X] T055 [US4] Self-protection guard у діях `UserResource` — заборона дії щодо самого себе (FR-024) (depends on T054)
- [X] T056 [US4] Викликати `AdminActionLogger::log()` і оновлювати `sessions_invalidated_at` у діях зміни ролі/статусу `UserResource` (depends on T009, T054)
- [X] T057 [US4] Додати перегляд журналу дій — окремий `AdminActionLogResource` (read-only), видимий лише `super_admin` (depends on T054)

**Checkpoint**: Усі чотири user story незалежно функціональні.

---

## Phase 7: Polish & Cross-Cutting Concerns

**Purpose**: Наскрізна перевірка якості після завершення всіх historій

- [X] T058 [P] Пройти сценарії 1-4 з `quickstart.md` вручну на локальному середовищі (webhook через тунель) — **частково**: маршрути (`/admin/login`, `/admin/users`, `/admin/admin-action-logs`, `/telegram/webhook/{secret}`) перевірені реальним HTTP-запитом (не лише тестовим клієнтом), сценарії 1-4 покриті Feature-тестами end-to-end через симуляцію Telegram Update JSON; справжній роздзвін через ngrok+живий бот-токен не виконувався (немає зовнішнього тунелю/реального бота в цьому середовищі) — потребує ручної перевірки перед продакшн-релізом
- [X] T059 Запустити повний `php artisan test`, переконатися, що існуючі тести фіч 001-003 не зламані новими міграціями/моделями — 141/143, 2 відмови préexistentes й не пов'язані з цією фічею (підтверджено `git stash` до створення коду): `DocumentUploadPageTest` (бага фічі 001) та `PdfVisionExtractorTest` (відсутнє розширення `imagick` у локальному PHP CLI)
- [X] T060 [P] Прогнати `./vendor/bin/pint` на новому коді (`app/Services/Telegram`, `app/Services/Auth`, `app/Services/Admin`, `app/Filament/Pages/Auth`, `app/Filament/Resources/UserResource.php`)
- [X] T061 Перевірити, що помилки Telegram API та збої RAG/AI (FR-015a) логуються через стандартний Laravel logging з контекстом (Принцип IV Observability), без порожніх catch-блоків — усі `catch` без логування стосуються лише валідації вводу (невірний номер телефону), не діагностичних помилок

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: без залежностей
- **Foundational (Phase 2)**: після Setup — БЛОКУЄ усі user story
- **User Stories (Phase 3-6)**: усі залежать від завершення Foundational; між собою — лише м'яка пріоритетність (P1 → P1 → P2 → P3), жодна не ламає іншу
- **Polish (Phase 7)**: після всіх бажаних user story

### User Story Dependencies

- **US1 (P1)**: після Foundational — без залежності від інших story
- **US2 (P1)**: після Foundational — незалежна від US1 (можна тестувати з уже існуючим approved-користувачем, створеним напряму в БД для тесту), але разом з US1 утворює повний MVP
- **US3 (P2)**: після Foundational — незалежна; використовує `users.sessions_invalidated_at` з Foundational
- **US4 (P3)**: після Foundational — незалежна; записи в журналі дій з US1 (апрув/відхилення) можна перевірити лише після US1, але сама функціональність US4 (зміна ролі, self-protection) тестується незалежно

### Within Each User Story

- Тести пишуться першими і мають падати до реалізації (Принцип II конституції)
- Моделі/міграції → сервіси → контролер/UI-рівень → інтеграція
- Story вважається завершеною лише після Checkpoint

### Parallel Opportunities

- Усі Setup-завдання з `[P]` — паралельно
- `T004`, `T005`, `T006`, `T008`, `T010`, `T013`, `T014` у Foundational — паралельно (різні файли, без взаємних залежностей)
- Після Foundational — US1, US2, US3, US4 можуть виконуватися паралельно різними розробниками
- Усі тести всередині однієї story з `[P]` — паралельно
- Незалежні моделі/сервіси всередині story (позначені `[P]`) — паралельно

---

## Parallel Example: User Story 1

```bash
# Тести US1 одночасно:
Task: "Feature-тест онбордингу в tests/Feature/Telegram/OnboardingTest.php"
Task: "Feature-тест дубліката телефону в tests/Feature/Telegram/OnboardingDuplicatePhoneTest.php"
Task: "Feature-тест картки заявки в tests/Feature/Telegram/ApprovalRequestCreatedTest.php"
Task: "Feature-тест обробки callback в tests/Feature/Telegram/ApprovalDecisionTest.php"
Task: "Feature-тест нагадування в tests/Feature/Telegram/ApprovalReminderTest.php"
Task: "Feature-тест шкали очікування в tests/Feature/Telegram/ReapplicationCooldownTest.php"

# Незалежні моделі/сервіси US1:
Task: "Модель UserApprovalRequest + міграція"
Task: "ReapplicationCooldownService"
```

---

## Implementation Strategy

### MVP First (User Story 1 + User Story 2)

1. Завершити Phase 1: Setup
2. Завершити Phase 2: Foundational (КРИТИЧНО — блокує все інше)
3. Завершити Phase 3: User Story 1 → перевірити онбординг+апрув незалежно
4. Завершити Phase 4: User Story 2 → перевірити меню/документи/AI-чат незалежно
5. **СТОП і ПЕРЕВІРИТИ**: разом US1+US2 — повний бот-флоу (MVP), валідація через `quickstart.md` сценарії 1-2

### Incremental Delivery

1. Setup + Foundational → фундамент готовий
2. + US1 → тест незалежно → демо (онбординг+апрув)
3. + US2 → тест незалежно → демо (MVP бота завершено)
4. + US3 → тест незалежно → демо (захищена веб-форма)
5. + US4 → тест незалежно → демо (повний функціонал фічі)

### Parallel Team Strategy

Після Foundational: один розробник — US1→US2 послідовно (логічна залежність онбордингу перед функціями бота для approved), другий — US3, третій — US4; інтеграція відбувається лише у `TelegramWebhookController` (US1/US2) та `AdminPanelProvider`/`UserResource` (US3/US4), де можливі дрібні конфлікти мерджу.

---

## Notes

- `[P]` = різні файли, без залежності від незавершених завдань
- `[Story]` прив'язує завдання до конкретної user story для трасування
- Кожна story — незалежно завершувана й тестована
- Переконатися, що тести падають до реалізації (Red → Green)
- Комітити після кожного завдання або логічної групи
- Зупинятися на кожному Checkpoint для незалежної перевірки story
