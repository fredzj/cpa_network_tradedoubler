<?php
/**
 * Copy this file to config.php and fill in your credentials.
 * Keep config.php OUTSIDE public_html (or keep the bundled .htaccess).
 */
return [
    // MySQL/MariaDB database (DirectAdmin > MySQL Management)
    'db' => [
        'dsn'  => 'mysql:host=localhost;dbname=USERNAME_tradedoubler;charset=utf8mb4',
        'user' => 'USERNAME_tradedoubler',
        'pass' => '',
    ],

    // Publisher API (OAuth2). Create the client at
    // https://publishers.tradedoubler.com/en/uaa/clients (secret is only shown once).
    'oauth' => [
        'client_id'     => '',
        'client_secret' => '',
        'username'      => '', // your Tradedoubler login
        'password'      => '',
    ],

    // Optional: legacy API tokens (Products / Vouchers / Claims / Conversions).
    // Normally these are discovered automatically via GET /publisher/tokens.
    // Only fill this in to override, or if a SITE token can't be matched to a source.
    'legacy_tokens' => [
        // 'PRODUCTS'    => ['publisher' => null, 'sites' => [1234567 => '40-char-token']],
        // 'VOUCHERS'    => ['publisher' => null, 'sites' => []],
        // 'CLAIMS'      => ['publisher' => null, 'sites' => []],
        // 'CONVERSIONS' => ['publisher' => null, 'sites' => []],
    ],

    // Limit the sync to these source (site) IDs. Empty = all your sources.
    'source_ids' => [],

    // Limit the products sync to these feed IDs. Empty = all feeds your sites can access.
    // Products of feeds not listed here are removed from the database.
    'feed_ids' => [],

    'timezone'          => 'Europe/Amsterdam',
    'report_currency'   => 'EUR',
    'backfill_days'     => 365, // history fetched the first time a site is synced
    'lookback_days'     => 45,  // window re-fetched on every run to catch status changes
    'program_status_ids'=> '1,2,3,6', // 1 under consideration, 2 on hold, 3 accepted, 6 on hold (accepted); '' = all incl. 0 not applied

    // Minimum minutes between runs of each task. Schedule the cron more often (e.g. every 30 min);
    // tasks that are not yet due are skipped.
    'intervals' => [
        'account'      => 1440,
        'programs'     => 1440,
        'statistics'   => 60,
        'transactions' => 60,
        'payments'     => 1440,
        'claims'       => 60,
        'vouchers'     => 360,
        'products'     => 1440, // each feed file may only be downloaded 3x per 24h
        'conversions'  => 1440,
    ],

    'log_file' => __DIR__ . '/logs/sync.log',

    // Optional: allow triggering via URL (https://.../sync.php?key=...). Leave empty to allow CLI only.
    'web_key' => '',
];
