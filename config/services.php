<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // Feature 004: Telegram-бот + авторизація та ролі
    'telegram' => [
        'bot_token' => env('TELEGRAM_BOT_TOKEN'),
        'webhook_secret' => env('TELEGRAM_WEBHOOK_SECRET'),
        // FR-004a: груповий чат модерації, куди надсилаються картки заявок на апрув
        'moderation_chat_id' => env('TELEGRAM_MODERATION_CHAT_ID'),
        // FR-004: фіксована мова картки заявки та кнопок у груповому чаті модерації
        'moderation_language' => env('TELEGRAM_MODERATION_LANGUAGE', 'uk'),
        // FR-019/FR-019a/FR-019b: одноразовий код для веб-входу
        'otp_ttl_minutes' => (int) env('TELEGRAM_OTP_TTL_MINUTES', 5),
        'otp_max_attempts' => (int) env('TELEGRAM_OTP_MAX_ATTEMPTS', 3),
        // FR-019b: скільки разів на хвилину можна запросити код на один номер
        'otp_requests_per_minute' => (int) env('TELEGRAM_OTP_REQUESTS_PER_MINUTE', 3),
        // Захист від перебору номерів/спаму кодами та підбору коду з одного IP.
        // Увага: за reverse proxy (nginx/Traefik) потрібен trustProxies, інакше
        // всі клієнти матимуть IP проксі й ділитимуть один лічильник.
        'otp_requests_per_ip_per_minute' => (int) env('TELEGRAM_OTP_REQUESTS_PER_IP_PER_MINUTE', 5),
        'otp_verify_per_ip_per_minute' => (int) env('TELEGRAM_OTP_VERIFY_PER_IP_PER_MINUTE', 10),
        // FR-007: час щоденного нагадування про необроблені заявки
        'reminder_time' => env('TELEGRAM_REMINDER_TIME', '09:00'),
        // FR-012a: розмір сторінки пагінації списку документів у боті
        'docs_page_size' => (int) env('TELEGRAM_DOCS_PAGE_SIZE', 20),
        // Окремий чат для технічних помилок webhook — не для модераторів,
        // лише для розробника/адміна, що стежить за станом бота. Якщо не
        // задано — помилки лише логуються (без надсилання в Telegram).
        'error_chat_id' => env('TELEGRAM_ERROR_CHAT_ID'),
        // Захист бюджету OpenAI у вільному чаті бота: ліміт питань на одного
        // користувача та максимальна довжина питання (як в API).
        'free_chat_per_minute' => (int) env('TELEGRAM_FREE_CHAT_PER_MINUTE', 10),
        'free_chat_per_day' => (int) env('TELEGRAM_FREE_CHAT_PER_DAY', 100),
        'question_max_length' => (int) env('TELEGRAM_QUESTION_MAX_LENGTH', 2000),
    ],

];
