# Data Model: Telegram-бот + авторизація та ролі

**Feature**: `004-telegram-bot-auth-roles` | **Date**: 2026-10-19

## Примітка щодо термінології статусу користувача

Spec.md (Key Entities) описує статус користувача як "pending/approved/rejected/active/deactivated" — у прозі "active" та "approved" вживаються як синоніми. Для однозначності в реалізації статус моделюється одним enum-полем із 4 значеннями:

- `pending` — заявка на розгляді
- `approved` — доступ відкрито (це і є "активний" стан з прози спеки)
- `rejected` — заявку відхилено (можливе повторне звернення за шкалою очікування)
- `deactivated` — доступ відкликано вручну super admin'ом (після того, як був `approved`)

Перехід `deactivated → approved` можливий (реактивація, FR-023), перехід `rejected → pending` можливий лише після закінчення періоду очікування (FR-006b).

## Entities

### User (розширення існуючої моделі)

Існуюча таблиця `users` (фіча 001) отримує нові колонки:

| Поле | Тип | Примітка |
|---|---|---|
| `phone` | `string`, nullable, unique | Нормалізований формат E.164 (FR-017a) |
| `status` | enum `pending\|approved\|rejected\|deactivated`, default `pending` | FR-003, FR-009 |
| `telegram_chat_id` | `bigint`, nullable, unique | FR-003 |
| `telegram_username` | `string`, nullable | Для картки заявки (FR-004) |
| `language` | enum `uk\|ru\|en`, default `uk` | FR-011 |
| `rejection_count` | `unsigned int`, default `0` | Для шкали очікування повторної заявки (FR-006b) |
| `last_rejected_at` | `timestamp`, nullable | FR-006b |
| `sessions_invalidated_at` | `timestamp`, nullable | FR-023a — мідлвар порівнює з часом логіну в сесії |

Роль (`user`/`moderator`/`admin`/`super_admin`) керується через `spatie/laravel-permission` (таблиці `roles`/`model_has_roles`), не окрема колонка.

**Validation**:
- `phone` обов'язковий і **глобально унікальний** серед усіх користувачів незалежно від статусу (валідується через `propaganistas/laravel-phone`); онбординг з номером, що вже належить іншому `chat_id`, відхиляється (FR-003a) — повторна заявка того ж користувача (FR-006b) оновлює той самий рядок `User`, тому конфлікту з уже зареєстрованим номером не виникає.
- `telegram_chat_id` унікальний, якщо не `null`.
- Рівно одна роль на користувача (бізнес-правило; `spatie/laravel-permission` технічно дозволяє кілька — обмежується на рівні сервісу призначення ролі).
- Завжди існує щонайменше один користувач з роллю `super_admin` (FR-006a) — забезпечується на рівні сидера/команди деактивації (блокується спроба деактивувати останнього).

### UserApprovalRequest

Заявка на апрув; одна активна (`pending`) заявка на користувача одночасно.

| Поле | Тип | Примітка |
|---|---|---|
| `id` | PK | |
| `user_id` | FK → `users.id` | Заявник |
| `status` | enum `pending\|approved\|rejected`, default `pending` | |
| `decided_by_user_id` | FK → `users.id`, nullable | Хто обробив (FR-005) |
| `decided_at` | `timestamp`, nullable | |
| `moderation_chat_id` | `bigint` | Чат, куди надіслано картку (FR-004a) |
| `moderation_message_id` | `bigint`, nullable | Поточний `message_id` картки — оновлюється при нагадуванні (FR-008) |
| `last_reminder_at` | `timestamp`, nullable | FR-007 |
| `created_at` / `updated_at` | | |

**State transitions**: `pending → approved` (кнопка "Одобрити"), `pending → rejected` (кнопка "Відхилити"). Жодних інших переходів — нова спроба після відхилення створює новий запис `UserApprovalRequest`, а не змінює старий.

**Validation**: не більше однієї заявки зі статусом `pending` на одного `user_id` (перевіряється перед створенням нової).

### OneTimeCode

Одноразовий код для входу на веб-форму (FR-019).

| Поле | Тип | Примітка |
|---|---|---|
| `id` | PK | |
| `user_id` | FK → `users.id` | |
| `code_hash` | `string` | Хеш коду (`Hash::make`), не зберігається у відкритому вигляді |
| `expires_at` | `timestamp` | `created_at + 5 хвилин` (конфігуровано) |
| `attempts` | `unsigned int`, default `0` | Максимум 3 (FR-019) |
| `consumed_at` | `timestamp`, nullable | Проставляється при успішному вході |
| `invalidated_at` | `timestamp`, nullable | Проставляється, коли код витіснено новішим запитом (FR-019a) |
| `created_at` | | |

**Validation**: код вважається дійсним, якщо `consumed_at IS NULL AND invalidated_at IS NULL AND expires_at > now() AND attempts < 3`. Перевищення спроб або TTL → код більше не приймається, потрібен новий. При генерації нового коду для того ж `user_id` усі попередні незавершені коди одразу отримують `invalidated_at = now()` (FR-019a).

**Rate limiting** (FR-019b, не поле моделі): генерація коду обмежується через `Illuminate\Cache\RateLimiter` за ключем нормалізованого номера телефону — не більше 3 запитів на хвилину; реалізується на рівні контролера/сервісу, без окремої таблиці.

### DocumentSummary (кеш AI-резюме)

| Поле | Тип | Примітка |
|---|---|---|
| `id` | PK | |
| `document_id` | FK → `documents.id` (існуюча таблиця, фіча 001) | |
| `locale` | enum `uk\|ru\|en` | |
| `summary` | `text` | |
| `created_at` / `updated_at` | | |

**Validation**: унікальний індекс `(document_id, locale)` — одне резюме на пару документ+мова (FR-013/FR-014).

**Примітка (FR-012/FR-012b)**: список документів і запит резюме в боті MUST фільтрувати (м'яко) видалені документи (`Document::query()` без `withTrashed()`/`onlyTrashed()` — стандартний Eloquent `SoftDeletes` вже виключає їх за замовчуванням). У системі (фіча 001) немає дії "замінити файл у документі" — заміна виконується як завантаження нового `Document` і видалення старого, тож окремий механізм інвалідації кешу при "оновленні" не потрібен.

### AdminActionLog

Журнал адміністративних дій (FR-026).

| Поле | Тип | Примітка |
|---|---|---|
| `id` | PK | |
| `actor_user_id` | FK → `users.id` | Хто виконав дію |
| `target_user_id` | FK → `users.id`, nullable | Щодо кого (заявник/керований користувач) |
| `action` | enum `approval_approved\|approval_rejected\|role_changed\|user_deactivated\|user_activated` | |
| `metadata` | `json`, nullable | Напр. `{"old_role": "moderator", "new_role": "admin"}` |
| `created_at` | | Журнал незмінний — без `updated_at`, без видалення |

## Relationships

```text
User 1───* UserApprovalRequest (user_id)      — заявки, подані користувачем
User 1───* UserApprovalRequest (decided_by)   — заявки, обробленi користувачем
User 1───* OneTimeCode                        — коди входу
User 1───* AdminActionLog (actor_user_id)     — дії, виконані користувачем
User 1───* AdminActionLog (target_user_id)    — дії, виконані щодо користувача
Document 1───* DocumentSummary                — резюме документа по мовах
```

## Конфігураційні значення (не дані, але пов'язані з моделлю)

| Ключ конфігурації | Призначення | Значення за замовчуванням |
|---|---|---|
| `services.telegram.bot_token` | Токен бота | — (`.env`) |
| `services.telegram.webhook_secret` | Секрет webhook-шляху | — (`.env`) |
| `services.telegram.moderation_chat_id` | Чат модерації (FR-004a) | — (`.env`) |
| `services.telegram.moderation_language` | Мова картки заявки (FR-004) | `uk` |
| `services.telegram.otp_ttl_minutes` | TTL коду (FR-019) | `5` |
| `services.telegram.otp_max_attempts` | Ліміт спроб коду (FR-019) | `3` |
| `services.telegram.reminder_time` | Час щоденного нагадування (FR-007) | `09:00` |
| `services.telegram.docs_page_size` | Розмір сторінки списку документів (FR-012a) | `20` |
| `session.lifetime` (Laravel, `SESSION_LIFETIME`) | Тривалість веб-сесії (FR-020) | `480` (8 год) |
