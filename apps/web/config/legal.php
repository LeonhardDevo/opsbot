<?php

declare(strict_types=1);

$csv = static fn (string $value): array => array_values(array_filter(array_map('trim', explode(',', $value))));

return [
    'path' => (static fn (string $path): string => preg_match('#^([A-Za-z]:)?[\\\\/]#', $path) === 1 ? $path : base_path($path))(
        (string) env('LEGAL_PATH', 'resources/legal'),
    ),

    'reviewed' => (bool) env('LEGAL_REVIEWED', false),

    'enforce_acceptance' => (bool) env('LEGAL_ENFORCE_ACCEPTANCE', true),

    'documents' => ['terms', 'privacy', 'cookies', 'dpa', 'subprocessors', 'aup', 'ai', 'consent-ru', 'employee-notice'],

    'acceptance' => [
        'registration' => ['terms', 'privacy'],

        'registration_locale' => [
            'ru' => ['consent-ru'],
        ],

        'workspace' => ['dpa'],
    ],

    'company' => [
        'name' => env('LEGAL_COMPANY_NAME', '[Company name]'),
        'registration' => env('LEGAL_COMPANY_REGISTRATION', '[Registration number]'),
        'address' => env('LEGAL_COMPANY_ADDRESS', '[Registered address]'),
        'email' => env('LEGAL_COMPANY_EMAIL', 'legal@example.com'),
        'privacy_email' => env('LEGAL_PRIVACY_EMAIL', 'privacy@example.com'),
        'dpo' => env('LEGAL_DPO', '[Data protection officer]'),
        'eu_representative' => env('LEGAL_EU_REPRESENTATIVE', '[EU representative or "not appointed"]'),
        'governing_law' => env('LEGAL_GOVERNING_LAW', '[Governing law]'),
        'support_hours' => env('LEGAL_SUPPORT_HOURS', 'Mon–Fri, 09:00–18:00 UTC'),
    ],

    'llm_providers' => $csv((string) env('LEGAL_LLM_PROVIDERS', '')),

    'ai_discovery' => (bool) env('LEGAL_AI_DISCOVERY', true),

    'subprocessors' => [
        [
            'name' => env('LEGAL_HOSTING_PROVIDER', '[Hosting provider]'),
            'address' => env('LEGAL_HOSTING_ADDRESS', '[Registered address]'),
            'purpose' => ['en' => 'Application hosting and database', 'ru' => 'Хостинг приложения и базы данных'],
            'data' => ['en' => 'All service data', 'ru' => 'Все данные сервиса'],
            'location' => env('LEGAL_HOSTING_LOCATION', '[Region]'),
            'enabled' => true,
        ],
        [
            'name' => env('LEGAL_EMAIL_PROVIDER', '[E-mail delivery provider]'),
            'address' => env('LEGAL_EMAIL_ADDRESS', '[Registered address]'),
            'purpose' => ['en' => 'Transactional e-mail delivery', 'ru' => 'Доставка служебных писем'],
            'data' => ['en' => 'Name, e-mail address, message content', 'ru' => 'Имя, адрес e-mail, содержание письма'],
            'location' => env('LEGAL_EMAIL_LOCATION', '[Region]'),
            'enabled' => true,
        ],
        [
            'name' => 'Stripe, Inc.',
            'address' => '354 Oyster Point Blvd, South San Francisco, CA 94080, USA',
            'purpose' => ['en' => 'Subscription billing in USD and EUR', 'ru' => 'Оплата подписки в долларах США и евро'],
            'data' => ['en' => 'Billing contact, company details, payment details (held by Stripe)', 'ru' => 'Платёжный контакт, реквизиты компании, платёжные данные (хранятся у Stripe)'],
            'location' => 'USA / EU',
            'enabled' => in_array(env('BILLING_DRIVER', 'fake'), ['stripe', 'market'], true) && filled(env('STRIPE_SECRET')),
        ],
        [
            'name' => 'ООО НКО «ЮМани» (YooKassa)',
            'address' => '115035, Москва, ул. Садовническая, д. 82, стр. 2, комн. 3',
            'purpose' => ['en' => 'Subscription billing in RUB and fiscal receipts (54-FZ)', 'ru' => 'Оплата подписки в рублях и фискальные чеки (54-ФЗ)'],
            'data' => ['en' => 'Billing e-mail, payment details (held by YooKassa), receipt data', 'ru' => 'Платёжный e-mail, платёжные данные (хранятся у ЮKassa), данные чека'],
            'location' => 'Russia',
            'enabled' => in_array(env('BILLING_DRIVER', 'fake'), ['yookassa', 'market'], true) && filled(env('YOOKASSA_SHOP_ID')) && filled(env('YOOKASSA_SECRET_KEY')),
        ],
        [
            'name' => 'Telegram Messenger Inc.',
            'address' => env('LEGAL_TELEGRAM_ADDRESS', '[Registered address]'),
            'purpose' => ['en' => 'Delivery of questions, answers and notifications through the company bots customers connect', 'ru' => 'Передача вопросов, ответов и уведомлений через корпоративных ботов, подключённых клиентами'],
            'data' => ['en' => 'Telegram user and chat identifiers, usernames, message content exchanged with the bot', 'ru' => 'Идентификаторы пользователей и чатов Telegram, имена пользователей, содержание переписки с ботом'],
            'location' => 'UAE / global',
            'enabled' => true,
        ],
        [
            'name' => env('LEGAL_EMBEDDINGS_PROVIDER', '[Embeddings provider]'),
            'address' => env('LEGAL_EMBEDDINGS_ADDRESS', '[Registered address]'),
            'purpose' => ['en' => 'Vector search over customer documents (only when a remote embeddings model is configured and the workspace allows external AI providers)', 'ru' => 'Векторный поиск по документам клиента (только при подключённой удалённой модели эмбеддингов и разрешении внешних ИИ-провайдеров в пространстве)'],
            'data' => ['en' => 'Document excerpts and questions with personal data redacted', 'ru' => 'Фрагменты документов и вопросы с замаскированными персональными данными'],
            'location' => env('LEGAL_EMBEDDINGS_LOCATION', '[Region]'),
            'enabled' => (bool) env('LEGAL_EMBEDDINGS_REMOTE', false),
        ],
        ...array_map(
            static fn (array $provider): array => [
                'ai_provider' => $provider[0],
                'name' => env('LEGAL_AI_'.$provider[4].'_NAME', $provider[1]),
                'address' => env('LEGAL_AI_'.$provider[4].'_ADDRESS', $provider[2]),
                'purpose' => ['en' => 'AI answers and embeddings (only when the workspace allows external AI providers)', 'ru' => 'Ответы ИИ и векторизация (только если рабочее пространство разрешило внешних ИИ-провайдеров)'],
                'data' => ['en' => 'Questions and document excerpts with personal data redacted', 'ru' => 'Вопросы и фрагменты документов с замаскированными персональными данными'],
                'location' => $provider[3],
                'enabled' => null,
            ],
            [
                ['anthropic', 'Anthropic PBC', '548 Market St, PMB 90375, San Francisco, CA 94104, USA', 'USA', 'ANTHROPIC'],
                ['openai', 'OpenAI OpCo, LLC', '1455 3rd Street, San Francisco, CA 94158, USA', 'USA', 'OPENAI'],
                ['deepseek', 'Hangzhou DeepSeek Artificial Intelligence Co., Ltd.', '[Registered address]', 'China', 'DEEPSEEK'],
                ['openrouter', 'OpenRouter, Inc.', '[Registered address]', 'USA', 'OPENROUTER'],
                ['nvidia', 'NVIDIA Corporation', '2788 San Tomas Expressway, Santa Clara, CA 95051, USA', 'USA', 'NVIDIA'],
                ['opencode-zen', '[OpenCode Zen operator]', '[Registered address]', '[Region]', 'OPENCODE_ZEN'],
                ['opencode-go', '[OpenCode Go operator]', '[Registered address]', '[Region]', 'OPENCODE_GO'],
            ],
        ),
    ],

    'security' => [
        'contact' => env('SECURITY_CONTACT', 'mailto:security@example.com'),
        'preferred_languages' => env('SECURITY_PREFERRED_LANGUAGES', 'en, ru'),

        'expires_days' => (int) env('SECURITY_TXT_EXPIRES_DAYS', 180),
        'encryption' => env('SECURITY_ENCRYPTION_URL') ?: null,
    ],
];
