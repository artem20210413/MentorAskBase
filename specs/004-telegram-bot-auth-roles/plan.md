# Implementation Plan: Telegram-бот + авторизація та ролі

**Branch**: `004-telegram-bot-auth-roles` | **Date**: 2026-10-19 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/004-telegram-bot-auth-roles/spec.md`

## Summary

Додаємо Telegram-бота як новий канал доступу до вже існуючого RAG/AI-пайплайна
(фіча 001) з обов'язковою авторизацією через онбординг і апрув модератором/
адміном у груповому чаті модерації. Разом з ботом вводиться рольова модель
(`spatie/laravel-permission`: user/moderator/admin/super_admin), якою ж
закривається досі неавторизована Filament-панель завантаження документів —
вхід замінюється на кастомну 2-кроkову сторінку (номер телефону → одноразовий
код, що надсилається тим самим ботом). Супер-адмін отримує сторінку керування
користувачами з журналом адміністративних дій. Технічний підхід — максимальне
повторне використання вже наявної інфраструктури (Filament, Laravel-сесії,
`QuestionAnsweringService`, `Schedule::command`) замість нових абстракцій.

## Technical Context

**Language/Version**: PHP 8.3, Laravel 13.17 (існуючий застосунок)

**Primary Dependencies**:
- `spatie/laravel-permission` — ролі `user`/`moderator`/`admin`/`super_admin` (вже узгоджено в `docs/new_scope_auth_telegram.md`)
- `irazasyed/telegram-bot-sdk` — клієнт Telegram Bot API (webhook, inline-клавіатури, редагування повідомлень) — вибір обґрунтовано в `research.md` п.1
- `propaganistas/laravel-phone` — нормалізація/валідація номера телефону до E.164 (FR-017a) — `research.md` п.3
- Filament 3 (вже в проєкті) — захист існуючої панелі через кастомну `->login()`-сторінку замість нової системи авторизації
- Вбудовані Laravel-механізми: `Schedule::command` (нагадування), сесії (`SESSION_LIFETIME`), `Hash` (хеш одноразового коду) — без нових залежностей

**Storage**: PostgreSQL (існуюча БД проєкту) — нові таблиці: `user_approval_requests`, `one_time_codes`, `document_summaries`, `admin_action_logs`; нові колонки на `users`

**Testing**: PHPUnit/Pest (наявний у проєкті) — Feature-тести для webhook-флоу (онбординг, апрув, callback-кнопки), логіну за кодом, керування користувачами; `Http::fake()` для Telegram Bot API

**Target Platform**: той самий Laravel-монoліт (web-сервер); webhook вимагає публічний HTTPS-URL (для локальної розробки — тунель типу ngrok)

**Project Type**: web-service (розширення існуючого Laravel-моноліту, без нових проєктів/сервісів)

**Performance Goals**: відповідь на webhook — синхронна обробка в межах одного HTTP-запиту (секунди); жодних нових вимог до пропускної здатності понад існуючі

**Constraints**: TTL одноразового коду 5 хв / 3 спроби (FR-019); веб-сесія 8 год (FR-020, через `SESSION_LIFETIME=480`); нагадування — не раніше 12 год і о сконфігурованій годині (FR-007); пагінація списку документів по ~20 (FR-012a); шкала очікування повторної заявки 24г/48г/72г/1тиждень/1місяць (FR-006b, захардкоджена послідовність, не конфігурована)

**Scale/Scope**: масштаб пет-проєкту — одна група модерації, десятки адмінів/модераторів, сотні-тисячі користувачів бота; жодних вимог до горизонтального масштабування

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

| Принцип конституції | Перевірка | Статус |
|---|---|---|
| I. Українська мова спілкування | Увесь план, спека та подальші комунікації — українською | PASS |
| II. Test-First (NON-NEGOTIABLE) | Кожен FR отримає Feature/Unit-тест до або одночасно з реалізацією (деталізація в `tasks.md`); контракти (`contracts/*.md`) покриваються тестами перед реалізацією | PASS (забезпечується на етапі `/speckit-tasks`) |
| III. Simplicity (YAGNI) | Повторне використання `QuestionAnsweringService`, Filament-панелі, Laravel-сесій і `Schedule::command` замість нових систем; готові бібліотеки (`spatie/laravel-permission`, `irazasyed/telegram-bot-sdk`, `propaganistas/laravel-phone`) замість власних реалізацій ролей/Telegram-клієнта/парсингу телефону | PASS |
| IV. Observability | `AdminActionLog` (журнал дій), стандартне логування помилок/таймаутів AI-пайплайну (FR-015a) і помилок Telegram API | PASS |
| V. Versioning & Breaking Changes | Усі нові таблиці/колонки — через зворотні Laravel-міграції (`down()`); контракти webhook і веб-логіну задокументовані в `contracts/` перед реалізацією | PASS |

Порушень немає, `Complexity Tracking` не заповнюється.

**Повторна перевірка після Phase 1** (`data-model.md`, `contracts/`, `quickstart.md`): дизайн не додав нових зовнішніх сервісів чи абстракцій понад перелічені вище бібліотеки й розширення існуючої Filament-панелі/RAG-пайплайну — усі принципи залишаються PASS.

## Project Structure

### Documentation (this feature)

```text
specs/004-telegram-bot-auth-roles/
├── plan.md               # Цей файл (/speckit-plan)
├── research.md           # Phase 0 output
├── data-model.md         # Phase 1 output
├── quickstart.md         # Phase 1 output
├── contracts/
│   ├── telegram-webhook.md   # Контракт вхідного webhook + вихідні викликі Bot API
│   └── web-auth.md           # Контракт логіну за телефоном+кодом на Filament-панель
├── checklists/
│   └── requirements.md
└── tasks.md               # Phase 2 output (/speckit-tasks, ще не створено)
```

### Source Code (repository root)

Проєкт — існуючий Laravel-моноліт; нові файли додаються в наявну структуру
(`app/`, `database/`, `routes/`, `tests/`) без нових top-level директорій:

```text
app/
├── Models/
│   ├── User.php                          # розширюється новими колонками/relations
│   ├── UserApprovalRequest.php
│   ├── OneTimeCode.php
│   ├── DocumentSummary.php
│   └── AdminActionLog.php
├── Services/
│   ├── Telegram/
│   │   ├── TelegramClient.php             # тонка обгортка над SDK (sendMessage/edit/answerCallback)
│   │   ├── OnboardingService.php          # /start, прийом контакту (FR-002/003)
│   │   ├── ApprovalRequestService.php     # створення заявки, розсилка картки, обробка callback (FR-004-FR-008)
│   │   ├── ReapplicationCooldownService.php # шкала очікування (FR-006b)
│   │   ├── BotMenuService.php              # меню, мова, список документів+пагінація (FR-010-012a)
│   │   └── DocumentSummaryService.php      # AI-резюме + кеш (FR-013/014)
│   ├── Auth/
│   │   ├── OneTimeCodeService.php          # генерація/перевірка коду (FR-019)
│   │   └── AdminSessionGuardService.php    # інвалідація сесії (FR-023a)
│   └── Admin/
│       └── AdminActionLogger.php           # запис у журнал (FR-026)
├── Http/
│   ├── Controllers/
│   │   └── TelegramWebhookController.php   # єдина точка входу webhook
│   └── Middleware/
│       └── EnsureAdminSessionIsValid.php   # мідлвар інвалідації (contracts/web-auth.md)
├── Filament/
│   ├── Pages/Auth/
│   │   └── PhoneLogin.php                  # кастомна 2-крокова сторінка логіну
│   └── Resources/
│       └── UserResource.php                # керування користувачами + журнал дій (FR-021-026)
└── Console/Commands/
    ├── TelegramWebhookSetCommand.php       # php artisan telegram:webhook-set
    ├── CreateSuperAdminCommand.php         # php artisan telegram:create-super-admin (FR-025)
    └── SendApprovalRemindersCommand.php    # php artisan telegram:send-approval-reminders (FR-007/008)

database/migrations/
├── xxxx_add_telegram_fields_to_users_table.php
├── xxxx_create_user_approval_requests_table.php
├── xxxx_create_one_time_codes_table.php
├── xxxx_create_document_summaries_table.php
└── xxxx_create_admin_action_logs_table.php

routes/
├── web.php                                 # + маршрут telegram/webhook/{secret}
└── console.php                             # + Schedule::command('telegram:send-approval-reminders')

config/
└── services.php                            # + блок 'telegram' (bot_token, webhook_secret, moderation_chat_id, ...)

tests/Feature/
├── TelegramOnboardingTest.php
├── TelegramApprovalFlowTest.php
├── TelegramApprovalReminderTest.php
├── TelegramBotMenuTest.php
├── TelegramDocumentSummaryTest.php
├── TelegramFreeChatTest.php
├── PhoneLoginTest.php
├── AdminSessionInvalidationTest.php
└── UserManagementTest.php
```

**Structure Decision**: Один Laravel-застосунок (без поділу backend/frontend) —
бот і веб-логін додаються в наявну структуру того самого монoліту, що й фіча
001/002/003. Це відповідає Принципу III (Simplicity) і масштабу проєкту: окремий
сервіс для бота чи окрема авторизаційна система не виправдані.

## Complexity Tracking

*Порушень Constitution Check немає — розділ не заповнюється.*
