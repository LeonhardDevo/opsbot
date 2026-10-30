<?php

declare(strict_types=1);

use App\Domain\Opsbot\Privacy\OpsbotExportContributor;

return [
    'slug' => env('PRODUCT_SLUG', 'opsbot'),

    'tagline' => env('PRODUCT_TAGLINE', "Your company rules in every employee's Telegram"),

    'support_email' => env('PRODUCT_SUPPORT_EMAIL', 'support@example.com'),

    'telegram' => [
        'bot_username' => env('TELEGRAM_BOT_USERNAME') ?: null,
        'link_ttl_minutes' => (int) env('TELEGRAM_LINK_TTL_MINUTES', 15),
    ],

    'privacy' => [
        'export_contributors' => [
            OpsbotExportContributor::class,
        ],
    ],

    'email' => [
        'primary' => env('PRODUCT_EMAIL_PRIMARY', '#0277B6'),
        'ink' => env('PRODUCT_EMAIL_INK', '#0B1B2B'),
        'accent' => env('PRODUCT_EMAIL_ACCENT', '#0F766E'),
        'background' => env('PRODUCT_EMAIL_BACKGROUND', '#F6FAFD'),
        'background_dark' => env('PRODUCT_EMAIL_BACKGROUND_DARK', '#0A1220'),
    ],

    'workspace' => [
        'default_timezone' => env('PRODUCT_DEFAULT_TIMEZONE', 'UTC'),
        'default_currency' => env('PRODUCT_DEFAULT_CURRENCY', 'USD'),
        'currencies' => ['USD', 'EUR', 'RUB'],
    ],

    'assistant_suggestions' => [
        'en' => [
            'What do I do if I damage a client\'s property?',
            'Which products are allowed on natural stone?',
            'How do I report an injury on site?',
            'What is the checklist for a bathroom clean?',
        ],
        'ru' => [
            'Что делать, если я повредил имущество клиента?',
            'Какие средства можно использовать на натуральном камне?',
            'Как сообщить о травме на объекте?',
            'Какой чек-лист уборки санузла?',
        ],
    ],
];
