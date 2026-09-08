# API Contract: RAG AI Bot Backend (API MVP)

Усі ендпоінти — під префіксом `/api/v1`, автентифікація — заголовок
`Authorization: Bearer {технічний Sanctum-токен}` (FR-013), з rate limiting
на токен (FR-013a/b). Формат помилок — стандартний Laravel JSON
(`{"message": "...", "errors": {...}}`).

## POST /api/v1/documents

Завантаження документа до бази знань (FR-001).

**Request**: `multipart/form-data`
| Поле | Тип | Правила |
|---|---|---|
| `file` | file | обов'язково; MIME щонайменше `application/pdf`; ≤ 50 МБ (FR-001a) |

**Responses**:
- `201 Created` — файл прийнято, обробка асинхронна:
  ```json
  { "id": "uuid", "status": "pending", "original_name": "manual.pdf" }
  ```
- `200 OK` — файл є дублікатом уже обробленого документа (FR-002/003):
  ```json
  { "id": "uuid", "status": "duplicate", "duplicate_of_document_id": "uuid" }
  ```
- `422 Unprocessable Entity` — файл пошкоджений, непідтримуваний формат або
  перевищує 50 МБ (FR-001a, FR-001b): `{"message": "...", "errors": {"file": ["..."]}}`
- `429 Too Many Requests` — перевищено rate limit (FR-013b), заголовок
  `Retry-After: {секунди}`

## GET /api/v1/documents

Список документів (для веб-сторінки та API-тестування; FR-007, FR-015).

**Response** `200 OK`:
```json
{
  "data": [
    {
      "id": "uuid",
      "original_name": "manual.pdf",
      "status": "processed",
      "failure_reason": null,
      "created_at": "2026-09-06T12:00:00Z"
    }
  ]
}
```

## GET /api/v1/documents/{id}

Статус конкретного документа (FR-007).

**Responses**: `200 OK` (той самий формат, що елемент списку вище) або
`404 Not Found`, якщо документ не існує чи вже видалений (FR-018a).

## DELETE /api/v1/documents/{id}

М'яке видалення документа (FR-017, FR-017a).

**Responses**:
- `204 No Content` — видалено (позначено як видалене) негайно, незалежно від
  активних запитів, що можуть його використовувати (FR-017)
- `404 Not Found` — документ не існує або вже видалений (FR-018a)

## GET /api/v1/queries

Перегляд журналу запитів без повторного звернення до LLM (SC-005).

**Response** `200 OK`:
```json
{
  "data": [
    {
      "query_log_id": "uuid",
      "session_id": "uuid",
      "question": "Яка гарантія на виріб X?",
      "answer": "Гарантія становить 24 місяці.",
      "answer_language": "uk",
      "sources": [{ "document_id": "uuid", "document_name": "manual.pdf" }],
      "created_at": "2026-09-06T12:00:00Z"
    }
  ]
}
```
Токен-метрика LLM (FR-010b) у цій відповіді відсутня навмисно (внутрішня
метрика, не призначена для клієнта).

## POST /api/v1/queries

Питання до бази знань (FR-008, User Story 2/3/5).

**Request**:
```json
{
  "question": "Яка гарантія на виріб X?",
  "session_id": "uuid-або-відсутнє"
}
```
`session_id` — необов'язкове; відсутнє значення починає нову сесію (FR-012).
Невідомий/прострочений `session_id` MUST не повертати помилку — система
автоматично створює нову сесію (FR-011a).

**Response** `200 OK`:
```json
{
  "query_log_id": "uuid",
  "session_id": "uuid",
  "answer": "Гарантія становить 24 місяці.",
  "answer_language": "uk",
  "sources": [
    { "document_id": "uuid", "document_name": "manual.pdf" }
  ]
}
```
- `sources` MUST бути порожнім масивом, якщо релевантної інформації не
  знайдено (FR-009, FR-009d); `query_log_id` MUST завжди повертатися — саме
  за ним клієнт згодом надсилає feedback (FR-010c).
- Метрика токенів LLM (FR-010b) у цій відповіді відсутня навмисно.

**Error responses**:
- `422 Unprocessable Entity` — порожнє/занадто довге питання
- `429 Too Many Requests` — перевищено rate limit (FR-013b), `Retry-After`
- `502 Bad Gateway` — зовнішній LLM API недоступний після однієї автоматичної
  повторної спроби (FR-009c)

## POST /api/v1/queries/{query_log_id}/feedback

Зворотний зв'язок щодо відповіді (FR-010c, User Story 6).

**Request**:
```json
{ "rating": "useful", "comment": "необов'язковий коментар" }
```
`rating` ∈ `{"useful", "not_useful"}`.

**Responses**:
- `200 OK` — оцінка збережена або замінила попередню:
  ```json
  { "query_log_id": "uuid", "rating": "useful", "comment": null }
  ```
- `404 Not Found` — запис журналу з таким `query_log_id` не існує
- `422 Unprocessable Entity` — недійсне значення `rating`

## Веб-сторінка керування документами (User Story 4)

Реалізується як Filament-ресурс (серверний рендеринг, без окремого API-
контракту): список, форма завантаження, дія видалення — використовують ті
самі сервіси (`DocumentUploadService`, м'яке видалення), що й API-ендпоінти
вище. Доступ без входу в систему на цьому етапі (FR-019); окремого HTTP-
контракту не документується, оскільки це не програмний інтерфейс для
зовнішніх клієнтів.
