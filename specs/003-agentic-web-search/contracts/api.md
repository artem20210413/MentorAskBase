# Contract Delta: Джерела відповіді (документ + інтернет)

Ця фіча розширює наявний контракт `POST /api/v1/queries` та
`GET /api/v1/queries` з `specs/001-rag-ai-bot-backend/contracts/api.md` —
форма запиту не змінюється; елементи масиву `sources` у відповіді
отримують новий необов'язковий тип.

## POST /api/v1/queries (delta)

**Response** `200 OK` — кожен елемент `sources` тепер MAY бути одним із
двох типів (розрізняється за наявними полями):

```json
{
  "query_log_id": "uuid",
  "session_id": "uuid",
  "answer": "Гарантія на виріб X становить 24 місяці; станом на 2026 рік MENTOR не публікував змін до цієї умови.",
  "answer_language": "uk",
  "sources": [
    { "type": "document", "document_id": "uuid", "document_name": "manual.pdf", "page_number": 4, "relevance": 82 },
    { "type": "web", "url": "https://www.jnjmedtech.com/...", "title": "MENTOR Warranty Information", "relevance": null }
  ]
}
```

- `sources[].type` MUST бути `"document"` або `"web"`.
- Для `type: "document"` — поля ті самі, що й зараз (`document_id`,
  `document_name`, `page_number`, `relevance`).
- Для `type: "web"` — `url` і `title` MUST бути присутні; `page_number` і
  `document_id` MUST бути відсутні; `relevance` MAY бути `null`.
- `sources` MUST бути порожнім масивом, якщо ні база знань, ні пошук в
  інтернеті не дали релевантної інформації (FR-007) — без змін відносно
  наявної поведінки.
- Клієнти, що не розпізнають `type: "web"`, MAY ігнорувати такі елементи
  масиву — це узгоджується зі зворотною сумісністю (Assumptions spec.md).

## GET /api/v1/queries (delta)

Той самий розширений формат `sources[]` застосовується до записів
журналу, створених після впровадження цієї фічі. Записи, створені до
неї, продовжують повертати лише `type: "document"` елементи (зворотна
сумісність при читанні, `data-model.md`).

**Error responses** — без змін відносно наявного контракту; додатково:
- Якщо цикл інструментів (FR-006) досягає ліміту кроків без остаточної
  відповіді від LLM, система MUST однаково повернути `200 OK` з
  найкращою доступною відповіддю на основі вже зібраної інформації —
  це не вважається помилкою (SC-003).
