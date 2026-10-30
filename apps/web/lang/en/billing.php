<?php

declare(strict_types=1);

return [
    'plans' => [
        'free' => [
            'name' => 'Free',
            'description' => 'For trying things out with a small team.',
        ],
        'starter' => [
            'name' => 'Starter',
            'description' => 'For small teams getting their workflow in order.',
        ],
        'pro' => [
            'name' => 'Pro',
            'description' => 'For growing teams that live in the product every day.',
        ],
        'business' => [
            'name' => 'Business',
            'description' => 'For organisations that need scale, control and support.',
        ],
    ],

    'features' => [
        'seats' => '{1} :count seat for staff and managers|[2,*] :count seats for staff and managers',
        'seats_unlimited' => 'Unlimited seats',
        'documents' => '{1} :count document|[2,*] :count documents',
        'documents_unlimited' => 'Unlimited documents',
        'ai_queries' => '{1} :count AI question per month|[2,*] :count AI questions per month',
        'ai_queries_unlimited' => 'Unlimited AI questions',
        'assistant' => 'Assistant that answers only from approved documents',
        'telegram_bot' => 'Your own company Telegram bot',
        'incidents' => 'Incidents with routing to the right manager',
        'tasks' => 'Tasks with reminders and quiet hours',
        'escalations' => 'Questions passed to managers',
        'starter_pack' => 'Cleaning starter pack (RU + EN)',
        'analytics' => 'Analytics: questions, documents, incidents, tasks',
        'audit_export' => 'Audit log export (CSV)',
        'priority_support' => 'Priority support',
        'storage' => ':size of file storage',
        'max_pages' => '{1} Up to :count page per document|[2,*] Up to :count pages per document',
    ],

    'units' => [
        'mb' => ':value MB',
        'gb' => ':value GB',
    ],

    'limits' => [
        'seats' => 'Seats',
        'documents' => 'Documents',
        'storage_mb' => 'Storage (MB)',
        'ai_queries_monthly' => 'AI questions this month',
        'max_pages_per_document' => 'Pages per document',
        'max_file_mb' => 'File size (MB)',
        'bots' => 'Company bots',
    ],

    'intervals' => [
        'month' => 'monthly',
        'year' => 'yearly',
    ],

    'statuses' => [
        'trialing' => 'Trial',
        'active' => 'Active',
        'past_due' => 'Payment overdue',
        'canceled' => 'Canceled',
        'incomplete' => 'Awaiting payment',
    ],

    'documents_count' => '{1} :count document|[0,*] :count documents',
    'incidents_count' => '{1} :count incident|[0,*] :count incidents',
    'seats_count' => '{1} :count seat|[0,*] :count seats',

    'limit_reached' => 'You have reached your plan limit for :limit (:count). Upgrade your plan to add more.',

    'errors' => [
        'price_not_configured' => 'This plan is not available for online payment yet.',
        'provider_unavailable' => 'The payment provider is unavailable. Please try again later.',
        'no_customer' => 'There is no billing account yet — subscribe to a plan first.',
        'webhook_secret_missing' => 'Billing webhooks are not configured (STRIPE_WEBHOOK_SECRET).',
        'worker_unavailable' => 'The payment could not be confirmed: the background worker is not reachable.',
        'worker_rejected' => 'The payment confirmation was rejected by the background worker.',
        'currency_unsupported' => 'Payments in this workspace currency are not available with the selected provider.',
        'gateway_unavailable' => 'Online payments for this workspace market are not connected yet.',
        'market_locked_by_subscription' => 'This change would move billing away from :gateway while a subscription is active. Cancel the subscription and wait for the period to end first.',
        'no_subscription' => 'There is no active subscription — subscribe to a plan first.',
        'nothing_to_change' => 'This plan and billing period are already active.',
        'not_supported' => 'This action is not available for the current payment provider.',
        'payment_account_missing' => 'No payment account is connected for this currency.',
        'payment_account_invalid' => 'The payment account credentials are not valid.',
        'payment_webhook_url_missing' => 'The public webhook URL for payments is not configured (PAYMENTS_WEBHOOK_BASE_URL).',
        'already_subscribed' => 'A subscription is already active — change the plan instead.',
        'receipt_email_missing' => 'A billing e-mail is required for the fiscal receipt.',
    ],

    'plan_change_requested' => 'Your plan is being changed with prorated billing. The update appears in a few seconds.',
    'cancel_requested' => 'The subscription will be canceled at the end of the current billing period.',
    'resume_requested' => 'Cancellation withdrawn — the subscription will renew automatically.',

    'plan_change_scheduled' => 'The new plan starts with the next billing period; it will be charged at renewal.',

    'yookassa_item' => ':product subscription, :plan plan, :interval',

    'yookassa_intervals' => [
        'month' => '1 month',
        'year' => '1 year',
    ],

    'gateways' => [
        'stripe' => 'Stripe',
        'yookassa' => 'YooKassa',
        'fake' => 'Test mode',
    ],

    'gateways_inline' => [
        'stripe' => 'Stripe',
        'yookassa' => 'YooKassa',
        'fake' => 'test mode',
    ],

    'countries' => [
        'AE' => 'United Arab Emirates', 'AM' => 'Armenia', 'AT' => 'Austria', 'AU' => 'Australia', 'BE' => 'Belgium',
        'BR' => 'Brazil', 'CA' => 'Canada', 'CH' => 'Switzerland', 'CZ' => 'Czechia', 'DE' => 'Germany',
        'DK' => 'Denmark', 'EE' => 'Estonia', 'ES' => 'Spain', 'FI' => 'Finland', 'FR' => 'France',
        'GB' => 'United Kingdom', 'GE' => 'Georgia', 'IE' => 'Ireland', 'IL' => 'Israel', 'IN' => 'India',
        'IT' => 'Italy', 'JP' => 'Japan', 'KZ' => 'Kazakhstan', 'LT' => 'Lithuania', 'LV' => 'Latvia',
        'MX' => 'Mexico', 'NL' => 'Netherlands', 'NO' => 'Norway', 'PL' => 'Poland', 'PT' => 'Portugal',
        'RU' => 'Russia', 'SE' => 'Sweden', 'SG' => 'Singapore', 'TR' => 'Türkiye', 'US' => 'United States',
        'UZ' => 'Uzbekistan',
    ],

    'checkout_completed' => 'Payment received — your plan will be active in a few seconds.',
    'portal_unavailable_fake' => 'Test billing mode: manage the plan directly on this page.',

    'fake_checkout' => [
        'title' => ':plan plan, billed :interval',
    ],

    'subscription_state' => [
        'ok' => 'Active',
        'grace' => 'Payment overdue — grace period',
        'paused' => 'AI answers paused',
    ],

    'currency' => [
        'USD' => 'US dollar',
        'EUR' => 'Euro',
        'RUB' => 'Russian ruble',
    ],

    'gateways' => [
        'stripe' => 'Stripe',
        'yookassa' => 'YooKassa',
        'fake' => 'Test payments',
    ],

];
