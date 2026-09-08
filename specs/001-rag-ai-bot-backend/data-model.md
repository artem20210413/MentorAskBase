# Data Model: RAG AI Bot Backend (API MVP)

Джерело: `spec.md` → Key Entities, Functional Requirements. Усі сутності —
таблиці PostgreSQL з розширенням `pgvector`, керовані через Eloquent-моделі
та стандартні міграції Laravel (Принцип V конституції: зворотні міграції).

## Document

Завантажений файл бази знань (spec.md: FR-001–FR-007, FR-017a, FR-018).

| Поле | Тип | Правила |
|---|---|---|
| `id` | UUID / bigint PK | |
| `original_name` | string | назва файлу, як завантажено |
| `content_hash` | string(64), indexed, unique серед не-видалених | SHA-256 вмісту файлу; основа дедуплікації (FR-002, FR-003, FR-003a) |
| `storage_path` | string | шлях у Laravel `Storage::` (локальний диск) |
| `size_bytes` | unsigned bigint | MUST ≤ 52 428 800 (50 МБ, FR-001a) — перевіряється до створення запису |
| `status` | enum | `pending` \| `processing` \| `processed` \| `failed` \| `duplicate` (FR-007); рівно одне значення в кожен момент |
| `failure_reason` | string, nullable | заповнюється лише при `status = failed` (FR-007) |
| `duplicate_of_document_id` | FK → documents.id, nullable | заповнюється лише при `status = duplicate` |
| `deleted_at` | timestamp, nullable | soft delete (FR-017a); Eloquent `SoftDeletes` |
| `created_at` / `updated_at` | timestamp | |

**Правила стану** (FR-007): `pending → processing → (processed | failed)`;
паралельний запит з тим самим `content_hash`, поки оригінал не в
`processed`/`failed`, не створює новий запис, а очікує на результат (FR-003a).
Запис зі статусом `duplicate` створюється лише після того, як оригінал уже
`processed`.

**Видалені документи** (`deleted_at IS NOT NULL`) виключаються з усіх
вибірок за замовчуванням (глобальний scope Eloquent `SoftDeletes`) — це
покриває FR-018 (не враховуються у відповідях, не показуються в списку).

## DocumentChunk

Фрагмент документа з векторним представленням (FR-004, FR-005).

| Поле | Тип | Правила |
|---|---|---|
| `id` | UUID / bigint PK | |
| `document_id` | FK → documents.id, cascade soft-delete | |
| `position` | unsigned int | порядковий номер фрагмента в межах документа |
| `content` | text | текст фрагмента (звичайний або розпізнаний з зображення) |
| `source` | enum | `text_layer` \| `vision_ocr` — з якого джерела отримано текст (FR-005) |
| `embedding` | `vector(N)` (pgvector) | N визначається обраною моделлю ембедингів (research.md #1) |
| `created_at` | timestamp | |

Індекс: HNSW/IVFFlat на `embedding` для similarity search; звичайний індекс
на `document_id`.

## ConversationSession

Логічна сесія розмови — власний запис у БД, а не HTTP-сесія (FR-010a).

| Поле | Тип | Правила |
|---|---|---|
| `id` | UUID PK | генерується системою, повертається клієнту (FR-010a, FR-012) |
| `last_activity_at` | timestamp | оновлюється при кожному новому питанні в межах сесії |
| `created_at` | timestamp | |

**Тайм-аут неактивності** (FR-012a): конфігурований (`config('rag.session_timeout_minutes')`,
типово 60; `null` = без автозавершення). Сесія вважається завершеною, якщо
`now() - last_activity_at > timeout` — перевіряється на льоту при вхідному
запиті (не потребує окремого поля статусу чи фонового job'а для MVP).

**Невідома/завершена сесія** (FR-011a): якщо переданий `session_id` не
знайдено або тайм-аут вичерпано, система створює новий запис
`ConversationSession` і повертає новий `id` клієнту, а не помилку.

## QueryLog

Запис одного обміну питання-відповідь (FR-010, FR-009d, FR-010b).

| Поле | Тип | Правила |
|---|---|---|
| `id` | UUID PK | ідентифікатор, за яким клієнт надсилає feedback (FR-010c) |
| `conversation_session_id` | FK → conversation_sessions.id | |
| `question` | text | текст питання |
| `answer` | text | текст відповіді |
| `detected_language` | string(10) | мова, визначена для питання (FR-009a) |
| `answered_in_language` | string(10) | фактична мова відповіді (може відрізнятись від `detected_language`, якщо застосовано FR-009b) |
| `source_document_ids` | JSON (масив FK) | документи-джерела відповіді (FR-009d); порожній масив, якщо релевантної інформації не знайдено (FR-009) |
| `llm_input_tokens` | unsigned int, nullable | внутрішня метрика (FR-010b), не повертається в API |
| `llm_output_tokens` | unsigned int, nullable | внутрішня метрика (FR-010b), не повертається в API |
| `created_at` | timestamp | |

## AnswerFeedback

Оцінка клієнтом конкретної відповіді (FR-010c).

| Поле | Тип | Правила |
|---|---|---|
| `id` | UUID / bigint PK | |
| `query_log_id` | FK → query_logs.id, unique | один активний запис на `query_log` — повторна оцінка **оновлює** цей запис, не створює новий (FR-010c) |
| `rating` | enum | `useful` \| `not_useful` |
| `comment` | text, nullable | необов'язковий коментар |
| `created_at` / `updated_at` | timestamp | `updated_at` відображає момент останньої зміни оцінки |

## Зв'язки (огляд)

```text
Document (1) ──< (N) DocumentChunk
ConversationSession (1) ──< (N) QueryLog
QueryLog (1) ──── (0..1) AnswerFeedback
QueryLog.source_document_ids ──> Document.id (м'яке посилання, не FK-каскад:
  видалений документ MUST залишатися видимим в історичних логах)
```

## Примітка щодо конфігурації (не сутність БД)

- `config/rag.php`: `supported_languages` (масив, перший елемент — мова за
  замовчуванням, FR-009a/b), `session_timeout_minutes` (FR-012a),
  `max_upload_size_mb` = 50 (FR-001a), `rate_limit_per_minute` (FR-013a).
