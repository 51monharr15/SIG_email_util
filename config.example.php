<?php
/**
 * Copy to config.php on the server (keep config.php out of git).
 * Same file used by lusers.php and the cron email utilities.
 */
return [
    'database' => [
        'host'     => 'localhost',
        'port'     => 3306,
        'database' => 'your_flarum_db',
        'username' => 'db_user',
        'password' => 'db_pass',
        'charset'  => 'utf8mb4',
        // Flarum table prefix, e.g. '' or 'flarum_'
        'prefix'   => '',
    ],

    'forum' => [
        // Public base URL, no trailing slash
        'base_url' => 'https://stroke.logicalmodel.net',
        'name'     => 'Stroke Community',
    ],

    'mail' => [
        // Default true = log only, send nothing. Cron must pass --send or set false.
        'dry_run' => true,

        // Optional: only these addresses may receive real mail when --send is used.
        // Empty array = no allowlist restriction. Use while testing on a live site.
        'allowlist' => [
            // 'you@example.com',
        ],

        'from_email' => 'noreply@logicalmodel.net',
        'from_name'  => 'Stroke Community',

        // cPanel SMTP (or local mail). Leave host empty to use PHP mail().
        'smtp' => [
            'host'       => 'localhost',
            'port'       => 587,
            'encryption' => 'tls', // tls | ssl | ''
            'username'   => '',
            'password'   => '',
        ],
    ],

    'welcome' => [
        // Catch signups from this many hours ago (overlap so a missed day still works)
        'lookback_hours' => 36,
        // Require confirmed email address
        'require_confirmed' => true,
    ],

    'reengage' => [
        // Base inactivity threshold in days (n). Tiers use n, 2n, 3n.
        'n_days' => 30,
        // Also run a deeper pass (second n). Empty to disable.
        'extra_n_days' => [180],
        // Max discussion links per email
        'max_threads' => 30,
        // After welcome, skip users who have turned off all Flarum email notification prefs
        'honour_email_prefs' => true,
        // Do not re-mail the same user more often than this (days)
        'min_interval_days' => 6,
    ],
];
