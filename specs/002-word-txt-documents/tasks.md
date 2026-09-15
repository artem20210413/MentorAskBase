# Tasks: Підтримка Word та текстових документів

**Input**: Design documents from `/specs/002-word-txt-documents/`

**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/api.md, quickstart.md

**Tests**: Конституція проєкту (Принцип II, Test-First, NON-NEGOTIABLE) вимагає тестів для кожної бізнес-логіки — тестові завдання включені й позначені як обов'язкові, а не опційні.

**Organization**: Завдання згруповано за користувацькими історіями зі spec.md (US1 = P1 Word, US2 = P2 текстові файли, US3 = P3 уніфікована поведінка).

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Можна виконувати паралельно (різні файли, без залежностей)
- **[Story]**: US1/US2/US3 — відповідність користувацькій історії зі spec.md
- Точні шляхи файлів вказані в кожному завданні

## Path Conventions

Існуючий Laravel-моноліт (`001-rag-ai-bot-backend`) — `app/`, `tests/`, `database/` у корені репозиторію; нових проєктів/директорій верхнього рівня не додається.

---

## Phase 1: Setup

**Purpose**: Підготовка залежностей і тестових фікстур, спільних для обох нових форматів

- [X] T001 Додати залежність `phpoffice/phpword` через `composer require phpoffice/phpword` (оновлює `composer.json`/`composer.lock`)
- [X] T002 [P] Додати тестові фікстури для `.docx` у `tests/Fixtures/documents/` — коректний `document.docx` (лише текст), `document-with-image.docx` (містить вбудоване зображення без тексту навколо), `fake.docx` (файл із розширенням `.docx`, що не є справжнім docx)
- [X] T003 [P] Додати тестові фікстури для `.txt` у `tests/Fixtures/documents/` — коректний `notes.txt` (UTF-8), `notes-cp1251.txt` (Windows-1251)

**Checkpoint**: Фікстури й бібліотека доступні для написання тестів у наступних фазах

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: Спільна інфраструктура, потрібна і US1 (Word), і US2 (txt), перш ніж їхня реалізація має сенс

**⚠️ CRITICAL**: Жодна з користувацьких історій не починається, доки ця фаза не завершена

- [X] T004 Розширити правило валідації файлу в `app/Http/Requests/UploadDocumentRequest.php` з `mimes:pdf` на `mimes:pdf,docx,txt` (FR-001, FR-002, FR-005)
- [X] T005 Виділити приватний метод визначення формату файлу за розширенням `original_name` (`pdf`/`docx`/`txt`) у `app/Jobs/ProcessDocumentJob.php`, і перебудувати `handle()` на гілкування за форматом навколо наявної PDF-логіки (без зміни поведінки для PDF) — підготовка місця для підключення нових екстракторів у US1/US2

**Checkpoint**: Валідація приймає нові розширення, `ProcessDocumentJob` готовий до підключення нових гілок обробки — можна починати US1 і US2 паралельно

---

## Phase 3: User Story 1 - Завантаження документа Word (Priority: P1) 🎯 MVP

**Goal**: Адміністратор завантажує `.docx`, система вилучає текст (і розпізнає вбудовані зображення через OCR), чанкує й векторизує його так само, як PDF.

**Independent Test**: Завантажити `document.docx` через `POST /api/documents`, дочекатися `status: processed`, переконатися, що питання з відповіддю виключно в цьому документі повертає відповідь з посиланням на нього.

### Tests for User Story 1 ⚠️

> Написати ці тести ПЕРШИМИ, переконатися що вони падають (Red) до реалізації

- [X] T006 [P] [US1] Unit-тест `VisionTranscriber` (успішна транскрипція зображення, обробка відмови vision-моделі) у `tests/Unit/VisionTranscriberTest.php`
- [X] T007 [P] [US1] Unit-тест `WordTextExtractor` (вилучення тексту параграфів/таблиць, вилучення шляхів вбудованих зображень, `assertReadable()` кидає виняток на `fake.docx`) у `tests/Unit/WordTextExtractorTest.php`
- [X] T008 [P] [US1] Feature-тест: завантаження коректного `document.docx` → `201`, після обробки `status: processed`, фрагменти мають `page_number = null` і `source = text_layer` у `tests/Feature/DocumentUploadTest.php`
- [X] T009 [P] [US1] Feature-тест: завантаження `fake.docx` → `422`, документ не потрапляє в чергу обробки у `tests/Feature/DocumentUploadTest.php`
- [X] T010 [P] [US1] Feature-тест: завантаження `document-with-image.docx` → після обробки серед фрагментів документа є хоча б один з `source = vision_ocr` у `tests/Feature/DocumentUploadTest.php`

### Implementation for User Story 1

- [X] T011 [US1] Створити `app/Services/DocumentIngestion/VisionTranscriber.php` — винести спільну логіку "зображення (шлях до файлу) → текст" (OpenAI vision-запит через `OpenAiRetry`, промпт транскрипції, розпізнавання відмов) з `PdfVisionExtractor`
- [X] T012 [US1] Рефакторити `app/Services/DocumentIngestion/PdfVisionExtractor.php` — делегувати транскрипцію зображення сторінки в `VisionTranscriber`, зберігши поточну публічну поведінку `extractPageText()`
- [X] T013 [US1] Створити `app/Services/DocumentIngestion/WordTextExtractor.php` з методами `extract(string $absolutePath): array` (текст параграфів/таблиць у порядку читання + перелік тимчасових шляхів вбудованих зображень) та `assertReadable(string $absolutePath): void` (FR-003, аналогічно `PdfTextExtractor::assertReadable()`), використовуючи `phpoffice/phpword`
- [X] T014 [US1] Підключити `WordTextExtractor::assertReadable()` до синхронної перевірки в `app/Services/DocumentIngestion/DocumentUploadService.php` для файлів з розширенням `.docx` (FR-003)
- [X] T015 [US1] Реалізувати гілку обробки `.docx` в `app/Jobs/ProcessDocumentJob.php`: вилучити текст і зображення через `WordTextExtractor`, розпізнати кожне зображення через `VisionTranscriber` (`source = vision_ocr`), решту тексту чанкувати з `source = text_layer`, `page_number = null` для всіх фрагментів (FR-004, FR-004a)
- [X] T016 [US1] Обробити в `ProcessDocumentJob` (гілка `.docx`) сценарії невдачі — пароль/пошкоджений файл, порожній вміст навіть після OCR — статус `failed` з описовим `failure_reason` (FR-010), залогованим через `Log::channel('rag')` (Принцип IV)

**Checkpoint**: US1 повністю функціональна й тестована незалежно — Word-документи завантажуються, обробляються (включно з OCR зображень) і беруть участь у пошуку відповідей

---

## Phase 4: User Story 2 - Завантаження текстового файлу (Priority: P2)

**Goal**: Адміністратор завантажує `.txt` у UTF-8, система вилучає його вміст, чанкує й векторизує так само, як PDF/Word.

**Independent Test**: Завантажити `notes.txt` через `POST /api/documents`, дочекатися `status: processed`, переконатися, що фрагменти доступні для пошуку відповідей.

### Tests for User Story 2 ⚠️

> Написати ці тести ПЕРШИМИ, переконатися що вони падають (Red) до реалізації

- [X] T017 [P] [US2] Unit-тест `TextFileExtractor` (успішне читання UTF-8, `assertReadable()` кидає виняток на `notes-cp1251.txt`) у `tests/Unit/TextFileExtractorTest.php`
- [X] T018 [P] [US2] Feature-тест: завантаження коректного `notes.txt` → `201`, після обробки `status: processed`, фрагменти мають `page_number = null` і `source = text_layer` у `tests/Feature/DocumentUpload/TextUploadTest.php`
- [X] T019 [P] [US2] Feature-тест: завантаження `notes-cp1251.txt` → `422` синхронно (перевірка кодування — частина синхронної перевірки формату FR-003, як і для пошкодженого PDF/docx), документ не створюється, у `tests/Feature/DocumentUpload/TextUploadTest.php`

### Implementation for User Story 2

- [X] T020 [US2] Створити `app/Services/DocumentIngestion/TextFileExtractor.php` з методами `extract(string $absolutePath): string` та `assertReadable(string $absolutePath): void` — перевірка `mb_check_encoding($content, 'UTF-8')`, виняток інакше (FR-003, FR-004b)
- [X] T021 [US2] Підключити `TextFileExtractor::assertReadable()` до синхронної перевірки в `app/Services/DocumentIngestion/DocumentUploadService.php` для файлів з розширенням `.txt` (FR-003)
- [X] T022 [US2] Реалізувати гілку обробки `.txt` в `app/Jobs/ProcessDocumentJob.php`: вилучити текст через `TextFileExtractor`, чанкувати з `source = text_layer`, `page_number = null`; порожній вміст → статус `failed` з `failure_reason` (FR-004, FR-010), залогований через `Log::channel('rag')`

**Checkpoint**: US1 і US2 обидві повністю функціональні й незалежно тестовані

---

## Phase 5: User Story 3 - Уніфікована поведінка незалежно від формату (Priority: P3)

**Goal**: Підтвердити, що документи всіх трьох форматів (PDF, Word, txt) поводяться однаково в списку, дедуплікації, м'якому видаленні й пошуку — без формат-специфічних розгалужень поза конвеєром обробки.

**Independent Test**: Завантажити по одному файлу кожного формату, переконатися в єдиному списку з однаковими полями; повторно завантажити `.docx` з ідентичним вмістом — очікується дублікат; м'яко видалити документ — його фрагменти зникають з пошуку.

### Tests for User Story 3 ⚠️

> Написати ці тести ПЕРШИМИ, переконатися що вони падають (Red) до реалізації

- [X] T023 [P] [US3] Feature-тест: `GET /api/documents` повертає документи PDF/`.docx`/`.txt` в одному списку з однаковим набором полів (`original_name`, `status`, `failure_reason`, `created_at`) у `tests/Feature/DocumentUploadTest.php`
- [X] T024 [P] [US3] Feature-тест: повторне завантаження `.docx` з ідентичним вмістом (інша назва файлу) позначається `status: duplicate` з коректним `duplicate_of_document_id` у `tests/Feature/DocumentUploadTest.php`
- [X] T025 [P] [US3] Unit/Feature-тест: після м'якого видалення `.docx`-документа його фрагменти виключені з `VectorSearchService::search()` у `tests/Unit/VectorSearchServiceTest.php` (розширення наявного тесту з покриттям нового формату, якщо тест вже існує для PDF)

### Implementation for User Story 3

- [X] T026 [US3] Пройтись по `DocumentController::transform()` (`app/Http/Controllers/Api/DocumentController.php`) і `DocumentResource::table()` (`app/Filament/Resources/DocumentResource.php`) та підтвердити (за потреби — виправити), що жодних формат-специфічних умов немає і нові формати відображаються ідентично до PDF (FR-007)

**Checkpoint**: Усі три користувацькі історії повністю функціональні; єдиний список документів, дедуплікація й м'яке видалення підтверджено працюють однаково для всіх форматів

---

## Phase 6: Polish & Cross-Cutting Concerns

**Purpose**: Фінальна перевірка якості понад усі користувацькі історії

- [X] T027 Запустити повний набір тестів (`php artisan test`) і усунути регресії в наявних тестах PDF-конвеєра, спричинені рефакторингом `VisionTranscriber`/`ProcessDocumentJob`
- [X] T028 [P] Оновити докблоки/коментарі в `app/Jobs/ProcessDocumentJob.php`, що описують конвеєр обробки, відповідно до нового гілкування за форматом (Принцип IV, Observability — коментарі мають відображати актуальну логіку)
- [X] T029 Виконати сценарії `specs/002-word-txt-documents/quickstart.md` вручну проти локального середовища (Sail) і підтвердити відповідність очікуваним результатам

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: без залежностей — старт одразу
- **Foundational (Phase 2)**: залежить від Setup — блокує US1 і US2
- **US1 (Phase 3)**: залежить від Foundational; незалежна від US2
- **US2 (Phase 4)**: залежить від Foundational; незалежна від US1 (може виконуватися паралельно з US1 іншим розробником)
- **US3 (Phase 5)**: залежить від завершення US1 і US2 (потребує наявності документів усіх трьох форматів для перевірки уніфікованої поведінки)
- **Polish (Phase 6)**: залежить від завершення US1, US2, US3

### Within Each User Story

- Тести пишуться першими й мають падати (Red) до реалізації (Принцип II конституції)
- Екстрактор (Unit) → підключення до `DocumentUploadService` (синхронна валідація) → гілка в `ProcessDocumentJob` (асинхронна обробка)

### Parallel Opportunities

- T002, T003 (фікстури) — паралельно
- Усі тестові завдання в межах однієї історії (T006-T010, T017-T019, T023-T025) — паралельно між собою (різні файли/незалежні тестові методи)
- US1 (Phase 3) і US2 (Phase 4) можуть виконуватися паралельно двома розробниками після Foundational — вони торкаються різних нових файлів-екстракторів і незалежних гілок у `ProcessDocumentJob`

---

## Parallel Example: User Story 1

```bash
# Тести US1 одночасно:
Task: "Unit-тест VisionTranscriber у tests/Unit/VisionTranscriberTest.php"
Task: "Unit-тест WordTextExtractor у tests/Unit/WordTextExtractorTest.php"
Task: "Feature-тест завантаження document.docx у tests/Feature/DocumentUploadTest.php"
Task: "Feature-тест завантаження fake.docx у tests/Feature/DocumentUploadTest.php"
Task: "Feature-тест завантаження document-with-image.docx у tests/Feature/DocumentUploadTest.php"
```

---

## Implementation Strategy

### MVP First (User Story 1 Only)

1. Завершити Phase 1: Setup
2. Завершити Phase 2: Foundational
3. Завершити Phase 3: US1 (Word)
4. **СТОП і ПЕРЕВІРИТИ**: Незалежний тест US1 (завантаження `.docx`, відповідь бота з посиланням на нього)
5. Це вже покриває основний запит користувача ("ворд файлы")

### Incremental Delivery

1. Setup + Foundational → фундамент готовий
2. US1 (Word) → тест незалежно → MVP
3. US2 (txt) → тест незалежно → інкремент
4. US3 (уніфікована поведінка) → підтвердження узгодженості → фінальна перевірка
5. Polish → повний прогін тестів і ручна перевірка quickstart.md

---

## Notes

- [P] завдання = різні файли, без залежностей одне від одного
- Мітка [Story] прив'язує завдання до конкретної користувацької історії зі spec.md
- Тести обов'язкові (Принцип II конституції) — писати до реалізації, переконатися що падають
- Комітити після кожного завдання чи логічної групи
- Зупинятися на кожному чекпоінті, щоб перевірити історію незалежно
