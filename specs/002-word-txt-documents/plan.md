# Implementation Plan: Підтримка Word та текстових документів

**Branch**: `002-word-txt-documents` | **Date**: 2026-09-12 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/002-word-txt-documents/spec.md`

## Summary

Розширити наявний конвеєр завантаження й обробки документів (побудований у
`001-rag-ai-bot-backend`) підтримкою двох нових вхідних форматів — `.docx`
(Word) і `.txt` (звичайний текст) — на додачу до вже підтримуваного PDF.
Валідація, дедуплікація за хешем, чанкінг, ембединг, м'яке/остаточне
видалення і пошук працюють для нових форматів так само, як для PDF, без
жодного видимого користувачу розрізнення форматів у списку документів.
Технічний підхід: додати `phpoffice/phpword` для вилучення тексту й
вбудованих зображень з `.docx`; винести спільну логіку "зображення → текст
через vision-модель" з `PdfVisionExtractor` у перевикористовуваний
`VisionTranscriber`, яким також скористається новий екстрактор `.docx`;
читання `.txt` — пряме, з перевіркою кодування UTF-8. Гілкування за типом
файлу відбувається в `ProcessDocumentJob` на основі розширення
`original_name`, без нових таблиць/стовпців БД.

## Technical Context

**Language/Version**: PHP 8.3, Laravel 13.17 (без змін відносно `001-rag-ai-bot-backend`)

**Primary Dependencies**:
- `phpoffice/phpword` (новий) — вилучення тексту й вбудованих зображень з `.docx`
- `openai-php/laravel` (наявний) — vision-модель для OCR зображень (перевикористання через новий `VisionTranscriber`)
- `smalot/pdfparser`, `spatie/pdf-to-image` (наявні, без змін) — обробка PDF
- Вбудовані PHP-функції (`file_get_contents`, `mb_check_encoding`) — читання `.txt`

**Storage**: PostgreSQL з pgvector (наявна схема `documents`/`document_chunks`, без міграцій — див. `data-model.md`)

**Testing**: PHPUnit — Feature-тест на завантаження `.docx`/`.txt` (успіх, невалідний файл, некоректне кодування), Unit-тести на нові екстрактори (`WordTextExtractor`, `TextFileExtractor`, `VisionTranscriber`), розширення наявних тестів `DocumentUploadServiceTest`/`ProcessDocumentJob`-покриття новими форматами

**Target Platform**: те саме середовище, що й `001-rag-ai-bot-backend` (Laravel Sail / стандартний веб-сервер)

**Project Type**: web-service (те саме, без змін структури проєкту)

**Performance Goals**: без нових вимог понад ті, що вже діють для PDF (асинхронна обробка без жорсткого ліміту часу в межах черги)

**Constraints**: той самий спільний ліміт розміру файлу 50 МБ (FR-005); OCR зображень `.docx` — той самий бюджет ретраїв, що й `PdfVisionExtractor` (через спільний `OpenAiRetry`)

**Scale/Scope**: не змінює масштаб `001-rag-ai-bot-backend` (до ~1000 документів) — додає лише два нові формати вхідного файлу

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

| Принцип конституції | Перевірка | Статус |
|---|---|---|
| I. Українська мова спілкування | Уся плановна документація та подальші комунікації — українською | PASS |
| II. Test-First (NON-NEGOTIABLE) | Кожен новий FR (FR-001–FR-004b) отримає Feature/Unit-тест до або одночасно з реалізацією — деталі в `tasks.md` | PASS (буде забезпечено на етапі `/speckit-tasks`) |
| III. Simplicity (YAGNI) | Перевикористано наявний enum `source` і `page_number` (nullable) без нових стовпців/таблиць; тип файлу виводиться з `original_name` замість нового поля; спільний `VisionTranscriber` замість дублювання OCR-логіки; `phpoffice/phpword` замість написання власного docx-парсера | PASS |
| IV. Observability | Нові причини `failure_reason` (пошкоджений docx, пароль, непідтримуване кодування, порожній вміст) логуються тим самим шляхом (`Log::channel('rag')`), що й помилки PDF у `ProcessDocumentJob` | PASS |
| V. Versioning & Breaking Changes | Контракт `POST /api/documents` розширюється зворотно сумісно (додаються допустимі MIME-типи, структура відповіді не змінюється) — задокументовано в `contracts/api.md` як delta; міграцій БД не потрібно | PASS |

Порушень немає, `Complexity Tracking` не заповнюється.

**Повторна перевірка після Phase 1 (data-model.md, contracts/, quickstart.md)**:
дизайн підтвердив відсутність нових таблиць, стовпців чи зовнішніх сервісів
— лише один новий Composer-пакет (`phpoffice/phpword`) з чітким
обґрунтуванням (Phase 0, п. 1) і рефакторинг наявної OCR-логіки в спільний
сервіс замість дублювання. Усі принципи залишаються PASS.

## Project Structure

### Documentation (this feature)

```text
specs/002-word-txt-documents/
├── plan.md              # Цей файл (/speckit-plan)
├── research.md          # Phase 0 output
├── data-model.md         # Phase 1 output
├── quickstart.md         # Phase 1 output
├── contracts/
│   └── api.md            # Phase 1 output — delta до контракту 001-rag-ai-bot-backend
├── checklists/
│   └── requirements.md
└── tasks.md               # Phase 2 output (/speckit-tasks, ще не створено)
```

### Source Code (repository root)

Існуючий Laravel-моноліт (той самий, що й у `001-rag-ai-bot-backend`) —
нові файли додаються в наявну структуру `app/Services/DocumentIngestion/`:

```text
app/
├── Services/
│   └── DocumentIngestion/
│       ├── DocumentUploadService.php      # існуючий; розширюється викликом assertReadable для нових форматів
│       ├── PdfTextExtractor.php           # без змін
│       ├── PdfVisionExtractor.php         # рефакториться: OCR-виклик делегується у VisionTranscriber
│       ├── VisionTranscriber.php          # НОВИЙ — спільна логіка "зображення → текст" (OpenAI vision + retry)
│       ├── WordTextExtractor.php          # НОВИЙ — текст + вбудовані зображення з .docx (phpoffice/phpword)
│       ├── TextFileExtractor.php          # НОВИЙ — читання .txt + перевірка UTF-8
│       └── ChunkingService.php            # без змін — перевикористовується для всіх форматів
├── Jobs/
│   └── ProcessDocumentJob.php             # розширюється гілкуванням за розширенням файлу (pdf/docx/txt)
└── Http/
    └── Requests/
        └── UploadDocumentRequest.php      # правило mimes розширюється на docx/txt

tests/
├── Feature/
│   └── DocumentUploadTest.php             # розширюється кейсами .docx/.txt (успіх, невалідний файл, кодування)
└── Unit/
    ├── WordTextExtractorTest.php          # НОВИЙ
    ├── TextFileExtractorTest.php          # НОВИЙ
    └── VisionTranscriberTest.php          # НОВИЙ (виділений з наявних тестів PdfVisionExtractor, якщо є)
```

**Structure Decision**: Розширення наявного Laravel-моноліту без нових
проєктів/сервісів — нові екстрактори додаються поруч із наявними в тому
самому неймспейсі `App\Services\DocumentIngestion`, дотримуючись уже
встановленого в `001-rag-ai-bot-backend` паттерну (один клас-екстрактор на
формат/відповідальність). Відповідає Принципу III й поточному масштабу
проєкту.

## Complexity Tracking

*Порушень Constitution Check немає — розділ не заповнюється.*
