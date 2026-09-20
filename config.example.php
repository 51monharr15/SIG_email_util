<?php
/**
 * Copy to config.php on the server (keep config.php out of git).
 */
return [
    // Used for log timestamps and email date wording
    'timezone' => 'Europe/London',

    'database' => [
        'host'     => 'localhost',
        'port'     => 3306,
        'database' => 'your_flarum_db',
        'username' => 'db_user',
        'password' => 'db_pass',
        'charset'  => 'utf8mb4',
        'prefix'   => '',
    ],

    'forum' => [
        'base_url'     => 'https://stroke.logicalmodel.net',
        'name'         => 'Stroke Community',
        'profile_path' => '/settings',
    ],

    'mail' => [
        'dry_run' => true,
        'allowlist' => [
            // 'you@example.com',
        ],
        'from_email' => 'noreply@logicalmodel.net',
        'from_name'  => 'Stroke Community',
        'smtp' => [
            'host'       => 'localhost',
            'port'       => 587,
            'encryption' => 'tls',
            'username'   => '',
            'password'   => '',
        ],
    ],

    'welcome' => [
        'lookback_hours' => 36,
        'require_confirmed' => true,
        // If last_seen is more than this many days after join, treat as already active (no welcome)
        'already_active_after_days' => 1,
    ],

    // Reminder / digest bands (absolute days since last visit). Edit freely — see RESTART.md
    'reminders' => [
        'bands' => [
            [
                'id' => 'digest',
                'min_days' => 14,
                'max_days' => 44,
                'min_interval_days' => 14,
            ],
            [
                'id' => 'away',
                'min_days' => 45,
                'max_days' => 90,
                'min_interval_days' => 30,
            ],
            [
                'id' => 'long',
                'min_days' => 91,
                'max_days' => 180,
                'min_interval_days' => 90,
            ],
            [
                'id' => 'dormant',
                'min_days' => 181,
                'max_days' => null, // unlimited
                'min_interval_days' => 90,
            ],
        ],
        // Joined ≈ last seen, long ago → dormant “find us” (not welcome)
        'never_engaged' => [
            'join_last_seen_within_days' => 7,
            'min_days_ago' => 100,
            'kind' => 'dormant',
        ],
        'new_since_show' => 5, // 3, 5, or 10
        'popular_days' => 30,
        'popular_limit' => 5,
    ],
];
