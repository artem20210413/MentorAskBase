# Контракт: Telegram Webhook

**Feature**: `004-telegram-bot-auth-roles`

## Вхідний ендпоінт

```
POST /telegram/webhook/{secret}
```

- `{secret}` — значення `services.telegram.webhook_secret`, перевіряється до розбору тіла запиту; невідповідність → `404` (не розкривати існування ендпоінта).
- Тіло запиту — стандартний Telegram `Update` JSON (`irazasyed/telegram-bot-sdk` парсить у типізований об'єкт).
- Відповідь: завжди `200 OK` з порожнім тілом (Telegram повторює доставку при інших кодах).
- Усі бізнес-дії виконуються синхронно в межах запиту (обсяг невеликий — API-дзвінки до Telegram, запис у БД); при повільних операціях (звернення до AI) — див. FR-015a (таймаут → дружнє повідомлення, не блокування webhook).

## Типи вхідних подій, що обробляються

### 1. `message` з текстом `/start` або без зареєстрованого користувача
- **Вхід**: `message.from.id` (Telegram user id), `message.chat.id`.
- **Дія**: якщо користувача з таким `telegram_chat_id` нема — надіслати привітання (FR-002) і запросити контакт (reply-кнопка "Поділитись контактом").
- **Вихід**: `sendMessage` з текстом вітання + `ReplyKeyboardMarkup` (кнопка типу `request_contact`).

### 2. `message.contact`
- **Вхід**: `contact.phone_number`, `contact.user_id` (має збігатися з `message.from.id`).
- **Дія**: нормалізувати номер (E.164). **Якщо** цей номер уже закріплений за іншим `telegram_chat_id` (FR-003a) → відповісти "номер вже зареєстровано", жодних записів не створювати, зупинити обробку. **Інакше** — створити/оновити `User` (`status=pending`, `telegram_chat_id`, `telegram_username`), створити `UserApprovalRequest`, надіслати картку в чат модерації (FR-003, FR-004).
- **Вихід**: підтвердження заявнику ("заявку надіслано на розгляд") + `sendMessage` картки в `moderation_chat_id` з `InlineKeyboardMarkup` (`callback_data`: `approval:{request_id}:approve` / `approval:{request_id}:reject`).

### 3. `callback_query` з `callback_data` що починається з `approval:`
- **Вхід**: `callback_query.from.id`, `callback_query.data` (`approval:{id}:approve|reject`), `callback_query.message.message_id`.
- **Дія**: перевірити роль клікера (FR-004b); якщо немає ролі — `answerCallbackQuery` із текстом помилки, без зміни стану. Якщо є — атомарно перевірити, що заявка ще `pending`; якщо вже обробена — `answerCallbackQuery` ("заявка вже обробена"). Інакше — оновити `UserApprovalRequest` (`status`, `decided_by_user_id`, `decided_at`), `editMessageText`/`editMessageReplyMarkup` картки (прибрати кнопки, додати позначку), надіслати результат заявнику, записати `AdminActionLog`.

### 4. `callback_query` з `callback_data` що починається з `docs_page:`
- **Вхід**: `docs_page:{page}`.
- **Дія**: перевірити, що користувач `approved` (FR-009); отримати сторінку списку документів, `editMessageText`/`editMessageReplyMarkup` з новим текстом і кнопками "Далі"/"Назад" (FR-012a).

### 5. `message` звичайний текст від `approved`-користувача
- **Дія**: якщо це одна з команд меню (мова/довідка/список документів/інфо) — обробити відповідним хендлером (FR-010–FR-014); інакше — передати в `QuestionAnsweringService` як вільне питання (FR-015), з обробкою помилки/таймауту (FR-015a).

### 6. `message` від користувача зі статусом `pending`/`rejected`/`deactivated`
- **Дія**: відповісти повідомленням про відсутність доступу з причиною (FR-009), ніяких інших дій.

## Вихідні викликі Telegram Bot API (через SDK)

| Метод | Коли |
|---|---|
| `sendMessage` | Привітання, підтвердження заявки, результат апрову, меню, список документів (1-ша сторінка), резюме, відповідь AI, повідомлення про блокування доступу |
| `editMessageText` / `editMessageReplyMarkup` | Оновлення картки заявки після рішення/нагадування, пагінація списку документів |
| `answerCallbackQuery` | Підтвердження натискання кнопки (у т.ч. тост-повідомлення про відмову/вже обробено) |

## Авторизаційний контракт для callback-кнопок

Будь-яка дія, що змінює стан (апрув/відхилення), MUST пройти перевірку ролі клікера за `telegram_chat_id → User → hasAnyRole([...])` **перед** будь-якою зміною даних — членство в груповому чаті модерації саме по собі не є авторизацією (edge case зі спеки).
