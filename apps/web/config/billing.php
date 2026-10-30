<?php

declare(strict_types=1);

return [
    'driver' => env('BILLING_DRIVER', 'fake'),

    'allow_fake_in_production' => (bool) env('BILLING_ALLOW_FAKE_IN_PRODUCTION', false),

    'currency' => env('BILLING_CURRENCY', 'USD'),

    'default_plan' => 'free',

    'active_statuses' => ['trialing', 'active', 'past_due'],

    'trial' => [
        'plan' => 'pro',
        'days' => 14,
    ],

    'trial_days' => (int) env('BILLING_TRIAL_DAYS', 0),

    'trial_ending_notice_days' => 3,

    'grace_days' => 7,

    'gateways' => [
        'stripe' => 'App\Billing\StripeGateway',
        'yookassa' => 'App\Billing\YooKassa\YooKassaGateway',
        'fake' => 'App\Billing\Fake\FakeGateway',
    ],

    'gateway_requirements' => [
        'yookassa' => ['billing.yookassa.shop_id', 'billing.yookassa.secret_key'],
    ],

    'markets' => [
        'default_country' => env('BILLING_DEFAULT_COUNTRY', 'US'),
        'default_gateway' => 'stripe',
        'currency_gateways' => [
            'RUB' => 'yookassa',
        ],
        'country_gateways' => [
            'RU' => 'yookassa',
        ],
        'gateway_currencies' => [
            'stripe' => ['USD', 'EUR'],
            'yookassa' => ['RUB'],
            'fake' => ['USD', 'EUR', 'RUB'],
        ],
        'country_currencies' => [
            'RU' => 'RUB',
            'AT' => 'EUR', 'BE' => 'EUR', 'HR' => 'EUR', 'CY' => 'EUR', 'EE' => 'EUR', 'FI' => 'EUR',
            'FR' => 'EUR', 'DE' => 'EUR', 'GR' => 'EUR', 'IE' => 'EUR', 'IT' => 'EUR', 'LV' => 'EUR',
            'LT' => 'EUR', 'LU' => 'EUR', 'MT' => 'EUR', 'NL' => 'EUR', 'PT' => 'EUR', 'SK' => 'EUR',
            'SI' => 'EUR', 'ES' => 'EUR',
        ],
        'locale_currencies' => [
            'ru' => 'RUB',
        ],
        'countries' => [
            'US', 'GB', 'CA', 'AU', 'DE', 'FR', 'ES', 'IT', 'NL', 'BE', 'AT', 'IE', 'PT', 'FI', 'EE',
            'LV', 'LT', 'PL', 'CZ', 'SE', 'NO', 'DK', 'CH', 'RU', 'KZ', 'AM', 'GE', 'UZ', 'AE', 'TR',
            'IL', 'IN', 'SG', 'JP', 'BR', 'MX',
        ],
    ],

    'plans' => [
        'free' => [
            'highlighted' => false,
            'features' => ['assistant', 'telegram_bot', 'incidents', 'tasks', 'escalations', 'starter_pack'],
            'limits' => [
                'seats' => 5,
                'documents' => 15,
                'storage_mb' => 200,
                'ai_queries_monthly' => 300,
                'max_pages_per_document' => 30,
                'max_file_mb' => 10,
                'bots' => 1,
            ],
            'prices' => [
                'USD' => ['month' => 0, 'year' => 0],
                'EUR' => ['month' => 0, 'year' => 0],
                'RUB' => ['month' => 0, 'year' => 0],
            ],
        ],
        'starter' => [
            'highlighted' => false,
            'features' => ['assistant', 'telegram_bot', 'incidents', 'tasks', 'escalations', 'starter_pack', 'analytics'],
            'limits' => [
                'seats' => 25,
                'documents' => 100,
                'storage_mb' => 2048,
                'ai_queries_monthly' => 3000,
                'max_pages_per_document' => 150,
                'max_file_mb' => 25,
                'bots' => 1,
            ],
            'prices' => [
                'USD' => ['month' => 2900, 'year' => 29000],
                'EUR' => ['month' => 2700, 'year' => 27000],
                'RUB' => ['month' => 249000, 'year' => 2490000],
            ],
        ],
        'pro' => [
            'highlighted' => true,
            'features' => ['assistant', 'telegram_bot', 'incidents', 'tasks', 'escalations', 'starter_pack', 'analytics', 'audit_export'],
            'limits' => [
                'seats' => 75,
                'documents' => 500,
                'storage_mb' => 10240,
                'ai_queries_monthly' => 15000,
                'max_pages_per_document' => 300,
                'max_file_mb' => 50,
                'bots' => 1,
            ],
            'prices' => [
                'USD' => ['month' => 7900, 'year' => 79000],
                'EUR' => ['month' => 7300, 'year' => 73000],
                'RUB' => ['month' => 649000, 'year' => 6490000],
            ],
        ],
        'business' => [
            'highlighted' => false,
            'features' => ['assistant', 'telegram_bot', 'incidents', 'tasks', 'escalations', 'starter_pack', 'analytics', 'audit_export', 'priority_support'],
            'limits' => [
                'seats' => 250,
                'documents' => 2000,
                'storage_mb' => 51200,
                'ai_queries_monthly' => 60000,
                'max_pages_per_document' => 500,
                'max_file_mb' => 100,
                'bots' => 1,
            ],
            'prices' => [
                'USD' => ['month' => 19900, 'year' => 199000],
                'EUR' => ['month' => 18500, 'year' => 185000],
                'RUB' => ['month' => 1599000, 'year' => 15990000],
            ],
        ],
    ],

    'stripe' => [
        'key' => env('STRIPE_KEY'),
        'secret' => env('STRIPE_SECRET'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET') ?: null,
        'dev_webhook_secret' => 'whsec_test_local',
        'api_base' => env('STRIPE_API_BASE') ?: null,
        'catalog' => env('STRIPE_CATALOG') ?: env('PRODUCT_SLUG', 'opsbot'),
        'automatic_tax' => (bool) env('STRIPE_AUTOMATIC_TAX', false),
        'tax_id_collection' => (bool) env('STRIPE_TAX_ID_COLLECTION', true),
        'price_cache_seconds' => 600,
        'max_network_retries' => 2,
    ],

    'yookassa' => [
        'shop_id' => env('YOOKASSA_SHOP_ID') ?: null,
        'secret_key' => env('YOOKASSA_SECRET_KEY') ?: null,
        'api_base' => env('YOOKASSA_API_BASE') ?: 'https://api.yookassa.ru/v3',
        'receipts' => (bool) env('YOOKASSA_RECEIPTS', false),
        'vat_code' => (int) env('YOOKASSA_VAT_CODE', 1),
        'tax_system_code' => is_numeric(env('YOOKASSA_TAX_SYSTEM_CODE')) ? (int) env('YOOKASSA_TAX_SYSTEM_CODE') : null,
        'timeout_seconds' => 15,
        'retry_hours' => (int) env('YOOKASSA_RETRY_HOURS', 24),
        'grace_days' => (int) env('YOOKASSA_GRACE_DAYS', 7),
    ],

    'fake' => [
        'session_ttl_minutes' => 60,
    ],
];
