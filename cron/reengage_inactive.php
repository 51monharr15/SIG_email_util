<?php
/**
 * Weekly: re-engage users inactive for >= n days with new-thread digests.
 *
 * Tiers (kindness): soft (n..2n), softer (2n..3n), gentle (>=3n).
 * Discussions grouped by primary tag/category, then by age.
 *
 *   php cron/reengage_inactive.php
 *   php cron/reengage_inactive.php --dry-run --limit=10
 *   php cron/reengage_inactive.php --send --n-days=30
 *   php cron/reengage_inactive.php --send --user-id=123
 *
 * cPanel cron example (weekly Monday 10:00):
 *   /usr/local/bin/php /home/USER/path/to/SIG-Stakeholder-Database/cron/reengage_inactive.php --send
 */

require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once dirname(__DIR__) . '/lib/mailer.php';
require_once dirname(__DIR__) . '/lib/messages.php';

sig_require_cli();
$config = sig_load_config();
$args = sig_parse_args($argv, $config);

if ($args['help']) {
    echo "Usage: php reengage_inactive.php [--dry-run|--send] [--limit=N] [--user-id=N] [--n-days=N]\n";
    exit(0);
}

$pdo = sig_pdo($config);
$usersTable = sig_table($config, 'users');
$discTable  = sig_table($config, 'discussions');
$tagTable   = sig_table($config, 'tags');
$dtTable    = sig_table($config, 'discussion_tag');

$n = $args['n_days'] ?? (int) ($config['reengage']['n_days'] ?? 30);
$n = max(1, (int) $n);
$extraNs = $config['reengage']['extra_n_days'] ?? [];
if (!is_array($extraNs)) {
    $extraNs = [];
}
// This run uses one n (primary or overridden). Separate cron lines can pass --n-days=180.
$maxThreads = max(1, (int) ($config['reengage']['max_threads'] ?? 30));
$honourPrefs = (bool) ($config['reengage']['honour_email_prefs'] ?? true);
$minInterval = max(1, (int) ($config['reengage']['min_interval_days'] ?? 6));
$cutoff = date('Y-m-d H:i:s', time() - $n * 86400);

sig_log('reengage_inactive starting; mode=' . ($args['dry_run'] ? 'DRY-RUN' : 'SEND')
    . "; n_days={$n}; cutoff={$cutoff}");

$sql = "SELECT id, username, nickname, email, last_seen_at, preferences, joined_at
        FROM {$usersTable}
        WHERE email IS NOT NULL AND email <> ''
          AND last_seen_at IS NOT NULL
          AND last_seen_at <= :cutoff
          AND (suspended_until IS NULL OR suspended_until < NOW())";
$params = [':cutoff' => $cutoff];

if ($args['user_id'] !== null) {
    $sql .= ' AND id = :uid';
    $params[':uid'] = $args['user_id'];
}
$sql .= ' ORDER BY last_seen_at ASC';
if ($args['limit'] !== null) {
    $sql .= ' LIMIT ' . (int) $args['limit'];
}

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$candidates = $stmt->fetchAll();

$threadStmt = $pdo->prepare(
    "SELECT d.id, d.title, d.slug, d.created_at,
            COALESCE(t.name, 'Uncategorised') AS category,
            COALESCE(t.position, 9999) AS tag_position,
            CASE WHEN t.parent_id IS NULL THEN 0 ELSE 1 END AS tag_depth
     FROM {$discTable} d
     LEFT JOIN {$dtTable} dt ON dt.discussion_id = d.id
     LEFT JOIN {$tagTable} t ON t.id = dt.tag_id
     WHERE d.created_at > :since
       AND d.hidden_at IS NULL
       AND (d.is_private = 0 OR d.is_private IS NULL)
     ORDER BY tag_depth ASC, tag_position ASC, category ASC, d.created_at DESC"
);

$stats = [
    'candidates' => count($candidates),
    'sent' => 0,
    'dry_run' => 0,
    'skipped' => 0,
    'error' => 0,
    'by_tier' => ['soft' => 0, 'softer' => 0, 'gentle' => 0],
];

foreach ($candidates as $user) {
    $uid = (int) $user['id'];
    $email = (string) $user['email'];
    $lastSeen = (string) $user['last_seen_at'];
    $daysAway = (int) floor((time() - strtotime($lastSeen)) / 86400);
    $tier = sig_reengage_tier($daysAway, $n);

    if (!sig_allow_reengage_pref($pdo, $uid)) {
        sig_log("skip user {$uid}: sig_mail_prefs.allow_reengage=0");
        sig_mail_log_insert($pdo, $uid, $email, 'reengage', $tier, 'skipped', '', 'pref_opt_out');
        $stats['skipped']++;
        continue;
    }

    if ($honourPrefs && !sig_flarum_allows_email($user['preferences'] ?? null)) {
        sig_log("skip user {$uid}: Flarum email prefs all off");
        sig_mail_log_insert($pdo, $uid, $email, 'reengage', $tier, 'skipped', '', 'flarum_email_prefs_off');
        $stats['skipped']++;
        continue;
    }

    $last = sig_last_reengage_at($pdo, $uid);
    if ($last !== null) {
        $elapsed = time() - strtotime($last);
        if ($elapsed < $minInterval * 86400) {
            sig_log("skip user {$uid}: reengage within min_interval_days");
            sig_mail_log_insert($pdo, $uid, $email, 'reengage', $tier, 'skipped', '', 'min_interval');
            $stats['skipped']++;
            continue;
        }
    }

    if (!$args['dry_run'] && !sig_in_allowlist($config, $email)) {
        sig_log("skip user {$uid} {$email}: not on allowlist");
        sig_mail_log_insert($pdo, $uid, $email, 'reengage', $tier, 'skipped', '', 'not_on_allowlist');
        $stats['skipped']++;
        continue;
    }

    $threadStmt->execute([':since' => $lastSeen]);
    $rows = $threadStmt->fetchAll();

    // Dedupe discussions (multiple tags) — keep first (primary-ish) category
    $seen = [];
    $byCat = [];
    foreach ($rows as $row) {
        $did = (int) $row['id'];
        if (isset($seen[$did])) {
            continue;
        }
        $seen[$did] = true;
        $cat = (string) $row['category'];
        if (!isset($byCat[$cat])) {
            $byCat[$cat] = [
                'category' => $cat,
                'position' => (int) $row['tag_position'],
                'threads'  => [],
            ];
        }
        if (count_threads($byCat) >= $maxThreads) {
            break;
        }
        $byCat[$cat]['threads'][] = [
            'id'         => $did,
            'title'      => (string) $row['title'],
            'slug'       => (string) ($row['slug'] ?? ''),
            'created_at' => (string) $row['created_at'],
            'url'        => sig_discussion_url($config, $did, (string) ($row['slug'] ?? '')),
        ];
    }

    // Sort categories by tag position; within each, threads already newest-first from SQL —
    // user asked organised by category and within category by age (oldest first reads naturally in digest).
    uasort($byCat, function ($a, $b) {
        return $a['position'] <=> $b['position'] ?: strcmp($a['category'], $b['category']);
    });
    $grouped = [];
    foreach ($byCat as $block) {
        usort($block['threads'], function ($a, $b) {
            return strcmp($a['created_at'], $b['created_at']); // age ascending
        });
        if ($block['threads'] !== []) {
            $grouped[] = ['category' => $block['category'], 'threads' => $block['threads']];
        }
    }

    $msg = sig_reengage_message($config, $user, $tier, $daysAway, $grouped);
    $meta = [
        'days_away'     => $daysAway,
        'n_days'        => $n,
        'last_seen_at'  => $lastSeen,
        'thread_count'  => array_sum(array_map(function ($g) { return count($g['threads']); }, $grouped)),
        'categories'    => array_map(function ($g) { return $g['category']; }, $grouped),
    ];

    if ($args['dry_run']) {
        sig_log("DRY-RUN reengage [{$tier}] {$daysAway}d -> {$email} | threads={$meta['thread_count']} | {$msg['subject']}");
        sig_mail_log_insert($pdo, $uid, $email, 'reengage', $tier, 'dry_run', $msg['subject'], null, $meta);
        $stats['dry_run']++;
        $stats['by_tier'][$tier]++;
        continue;
    }

    $result = sig_send_mail($config, $email, $msg['subject'], $msg['text'], $msg['html']);
    if ($result['ok']) {
        sig_log("SENT reengage [{$tier}] -> {$email}");
        sig_mail_log_insert($pdo, $uid, $email, 'reengage', $tier, 'sent', $msg['subject'], null, $meta);
        $stats['sent']++;
        $stats['by_tier'][$tier]++;
    } else {
        sig_log("ERROR reengage -> {$email}: {$result['error']}");
        sig_mail_log_insert($pdo, $uid, $email, 'reengage', $tier, 'error', $msg['subject'], $result['error'], $meta);
        $stats['error']++;
    }
}

sig_log('reengage_inactive done: ' . json_encode($stats));
if ($extraNs !== [] && $args['n_days'] === null) {
    sig_log('Note: config reengage.extra_n_days=' . json_encode($extraNs)
        . ' — run separate cron with --n-days=180 (etc.) for deeper passes.');
}
exit($stats['error'] > 0 ? 2 : 0);

/** @param array<string, array{threads:array}> $byCat */
function count_threads(array $byCat): int
{
    $n = 0;
    foreach ($byCat as $b) {
        $n += count($b['threads']);
    }
    return $n;
}
