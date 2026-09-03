<?php

return [
    'gateways' => [
        'stripe' => [
            'enabled' => env('BILLING_STRIPE_ENABLED', false),
            'secret_key' => env('STRIPE_SECRET'),
            'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
            'success_url' => env('STRIPE_SUCCESS_URL', env('APP_URL') . '/app/billing?checkout=success'),
            'cancel_url' => env('STRIPE_CANCEL_URL', env('APP_URL') . '/app/pricing?checkout=canceled'),
        ],
        'paypal' => [
            'enabled' => env('BILLING_PAYPAL_ENABLED', false),
            'client_id' => env('PAYPAL_CLIENT_ID'),
            'client_secret' => env('PAYPAL_CLIENT_SECRET'),
            'sandbox' => env('PAYPAL_SANDBOX', true),
            'success_url' => env('PAYPAL_SUCCESS_URL', env('APP_URL') . '/app/billing?checkout=success'),
            'cancel_url' => env('PAYPAL_CANCEL_URL', env('APP_URL') . '/app/pricing?checkout=canceled'),
            'webhook_id' => env('PAYPAL_WEBHOOK_ID'),
        ],
    ],
];
