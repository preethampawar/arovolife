<?php

declare(strict_types=1);

/**
 * Arovolife platform-wide configuration.
 *
 * Mostly read from .env at boot. We surface env vars through this config
 * file so the codebase can use config() instead of env() at runtime —
 * env() returns null after `php artisan config:cache`, config() doesn't.
 */
return [

    /*
    |--------------------------------------------------------------------------
    | ProductionSeeder bootstrap values
    |--------------------------------------------------------------------------
    |
    | Used by `php artisan db:seed --class=ProductionSeeder`. All keys are
    | read once from .env at config-cache time. See the runbook §A and
    | docs/runbooks/cloudways-deployment.md for the full list.
    */
    'seeder' => [
        'admin' => [
            'email' => env('PROD_ADMIN_EMAIL'),
            'password' => env('PROD_ADMIN_PASSWORD'),
            'name' => env('PROD_ADMIN_NAME', 'Administrator'),
            'phone' => env('PROD_ADMIN_PHONE', '+910000000000'),
        ],

        'root_distributor' => [
            'email' => env('PROD_ROOT_EMAIL'),
            'password' => env('PROD_ROOT_PASSWORD'),
            'name' => env('PROD_ROOT_NAME', 'Arovolife Company Root'),
            'phone' => env('PROD_ROOT_PHONE', '+910000000001'),
            'state' => env('PROD_ROOT_STATE', 'TG'),
            'adn' => env('PROD_ROOT_ADN', '111222333'),
        ],

        'compliance' => [
            'state_age_minimums' => env('COMPLIANCE_STATE_AGE_MINIMUMS', '{"MH":21}'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Support contacts
    |--------------------------------------------------------------------------
    */
    'support_email' => env('SUPPORT_EMAIL', 'support@arovolife.com'),

    /*
    |--------------------------------------------------------------------------
    | Analytics
    |--------------------------------------------------------------------------
    |
    | Google Analytics 4 (gtag.js) measurement ID. When set, the
    | partials._google-analytics snippet emits the loader + config on
    | public-facing pages. Leave empty in environments without consent
    | infrastructure (dev / staging) to keep the snippet from firing at all.
    |
    | DPDP NOTE: GA stores IP address + device fingerprint, which qualify
    | as personal data under the DPDP Act 2023. Before public launch we
    | owe users a cookie-consent banner that defers gtag init until the
    | user accepts (or short-circuits to "analytics off" if they decline).
    */
    'analytics' => [
        'google_id' => env('GOOGLE_ANALYTICS_ID'),
    ],
];
