# Quickstart: Перевірка підтримки Word та текстових документів

Передумови: застосунок піднятий (`./vendor/bin/sail up -d` або еквівалент),
черга обробляється (`sail artisan queue:work` або `queue:listen`), є дійсний
Sanctum-токен API (`php artisan rag:issue-token` / наявна команда видачі
токена).

## 1. Завантаження `.docx`

```bash
curl -s -X POST http://localhost/api/documents \
  -H "Authorization: Bearer $TOKEN" \
  -F "file=@storage/app/testing/sample.docx"
```

**Очікується**: відповідь `201` зі статусом `pending`. Після обробки в черзі:

```bash
curl -s http://localhost/api/documents/{id} -H "Authorization: Bearer $TOKEN"
```

**Очікується**: `status: processed`, а питання в чаті/через
`POST /api/queries` з відповіддю, яка міститься лише в цьому `.docx`,
повертає відповідь, що посилається на цей документ (SC-003).

## 2. Завантаження `.docx` із вбудованим зображенням

Завантажити `.docx`, що містить скановану сторінку чи діаграму як
зображення без текстового шару навколо. Після обробки перевірити, що серед
фрагментів документа є хоча б один з `source = vision_ocr`
(`DocumentChunk::where('document_id', $id)->where('source', 'vision_ocr')`),
що підтверджує спрацювання OCR (FR-004a).

## 3. Завантаження `.txt` (UTF-8)

```bash
curl -s -X POST http://localhost/api/documents \
  -H "Authorization: Bearer $TOKEN" \
  -F "file=@storage/app/testing/notes.txt"
```

**Очікується**: `201` → зрештою `status: processed`; фрагменти мають
`page_number = null` (FR-004b, data-model.md).

## 4. Відхилення невалідного файлу

```bash
curl -s -X POST http://localhost/api/documents \
  -H "Authorization: Bearer $TOKEN" \
  -F "file=@storage/app/testing/fake.docx"   # файл, що не є справжнім docx
```

**Очікується**: `422` з поясненням причини, документ не потрапляє в чергу
обробки (SC-004).

## 5. Відхилення `.txt` у некоректному кодуванні

Завантажити `.txt`, збережений у кодуванні Windows-1251.

**Очікується**: документ отримує `201`/`pending`, але після обробки —
`status: failed` з `failure_reason`, що вказує на непідтримуване кодування.

## 6. Єдиний список і дедуплікація (User Story 3)

1. Завантажити по одному файлу PDF, `.docx`, `.txt`.
2. `GET /api/documents` — усі три в одному списку з однаковими полями
   (SC-005).
3. Повторно завантажити той самий `.docx` під іншою назвою — новий запис
   отримує `status: duplicate` і `duplicate_of_document_id` на оригінал.
4. М'яко видалити `.docx`-документ (`DELETE /api/documents/{id}`) і
   поставити питання, відповідь на яке була лише в ньому — відповідь не
   повинна більше посилатися на цей документ (FR-009).
