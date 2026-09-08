<?php

return [
    // FR-009a/b: перший елемент — мова за замовчуванням для непідтримуваних мов
    'supported_languages' => array_filter(array_map(
        'trim',
        explode(',', env('RAG_SUPPORTED_LANGUAGES', 'uk,en'))
    )),

    // FR-012a: null вимикає автозавершення сесії (безстрокове зберігання контексту)
    'session_timeout_minutes' => env('RAG_SESSION_TIMEOUT_MINUTES', 60) === null
        ? null
        : (int) env('RAG_SESSION_TIMEOUT_MINUTES', 60),

    // FR-001a
    'max_upload_size_mb' => (int) env('RAG_MAX_UPLOAD_MB', 50),

    // Диск для зберігання файлів документів. "public" дає пряме посилання
    // через /storage/... (симлінк storage:link) без потреби в Bearer-токені —
    // усвідомлений компроміс: файли бази знань доступні за прямим посиланням
    // усім, хто його знає (як картинка на CDN), а не лише авторизованим
    // клієнтам API.
    'document_disk' => env('RAG_DOCUMENT_DISK', 'public'),

    // FR-013a
    'rate_limit_per_minute' => (int) env('RAG_RATE_LIMIT_PER_MINUTE', 30),

    // Остаточне (фізичне) видалення м'яко видалених документів (FR-017a):
    // через скільки днів після soft delete запис і файл стираються назавжди.
    'permanent_deletion_after_days' => (int) env('RAG_PERMANENT_DELETION_AFTER_DAYS', 30),

    'openai' => [
        'chat_model' => env('OPENAI_CHAT_MODEL', 'gpt-4o-mini'),
        'vision_model' => env('OPENAI_VISION_MODEL', 'gpt-4o-mini'),
        'embedding_model' => env('OPENAI_EMBEDDING_MODEL', 'text-embedding-3-small'),
        'embedding_dimensions' => (int) env('OPENAI_EMBEDDING_DIMENSIONS', 1536),
    ],

    'search' => [
        // Скільки максимум найближчих фрагментів передавати в LLM (FR-008)
        'top_k' => (int) env('RAG_SEARCH_TOP_K', 5),

        // Мінімальний відсоток релевантності (0-100, той самий, що йде у
        // відповіді як "relevance"), нижче якого фрагмент вважається
        // нерелевантним і відкидається ще до LLM (FR-009). Навмисно
        // ліберальний за замовчуванням: короткі/загальні фрагменти реальних
        // embedding-моделей часто дають лише 10-40% навіть для змістовно
        // пов'язаних питань — основне рішення "чи достатньо інформації"
        // покладається на LLM через системний промпт
        // (AnswerGenerationService::NO_INFO_MARKER).
        'min_relevance_percent' => (int) env('RAG_SEARCH_MIN_RELEVANCE', 30),
    ],
];
