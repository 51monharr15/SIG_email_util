<?php
/**
 * Reminder / digest job: classify every member, file-log everyone,
 * DB state only on real sends.
 *
 *   php cron/reengage_inactive.php --dry-run
 *   php cron/reengage_inactive.php --send
 *   php cron/reengage_inactive.php --dry-run --limit=50
 *   php cron/reengage_inactive.php --send --user-id=123
 *
 * Cron (this host):
 *   /usr/local/bin/php -d date.timezone=Europe/London /home/logicalm/stroke/SIG_email_util/cron/reengage_inactive.php --dry-run >> /home/logicalm/stroke/SIG_email_util/reengage_log.txt 2>&1
 */

require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once dirname(__DIR__) . '/lib/mailer.php';
require_once dirname(__DIR__) . '/lib/messages.php';

sig_require_cli();
$config = sig_load_config();
$args = sig_parse_args($argv, $config);

if ($args['help']) {
    echo "Usage: php reengage_inactive.php [--dry-run|--send] [--limit=N] [--user-id=N]\n";
    exit(0);
}

$pdo = sig_pdo($config);
$usersTable = sig_table($config, 'users');
$discTable  = sig_table($config, 'discussions');

$showNew = (int) (($config['reminders']['new_since_show'] ?? 5));
if (!in_array($showNew, [3, 5, 10], true)) {
    $showNew = max(1, $showNew);
}
$activeBefore = sig_active_threshold_days($config);
$popular = sig_fetch_popular_threads($pdo, $config);

sig_log('reminders starting; mode=' . ($args['dry_run'] ? 'DRY-RUN' : 'SEND')
    . "; active_if_under={$activeBefore}d; new_since_show={$showNew}; popular=" . count($popular));

$sql = "SELECT id, username, nickname, email, last_seen_at, joined_at
        FROM {$usersTable}
        WHERE email IS NOT NULL AND email <> ''
          AND (suspended_until IS NULL OR suspended_until < NOW())";
$params = [];

if ($args['user_id'] !== null) {
    $sql .= ' AND id = :uid';
    $params[':uid'] = $args['user_id'];
}
$sql .= ' ORDER BY id ASC';
if ($args['limit'] !== null) {
    $sql .= ' LIMIT ' . (int) $args['limit'];
}

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$users = $stmt->fetchAll();

$threadStmt = $pdo->prepare(
    "SELECT d.id, d.title, d.slug, d.created_at
     FROM {$discTable} d
     WHERE d.created_at > :since
       AND d.hidden_at IS NULL
       AND (d.is_private = 0 OR d.is_private IS NULL)
     ORDER BY d.created_at DESC"
);

$stats = [
    'users' => count($users),
    'nothing' => 0,
    'would_send' => 0,
    'sent' => 0,
    'error' => 0,
    'by_kind' => [],
];

foreach ($users as $user) {
    $uid = (int) $user['id'];
    $email = (string) $user['email'];
    $lastSeen = $user['last_seen_at'] !== null ? (string) $user['last_seen_at'] : '';
    $label = "uid={$uid} {$email}";

    if ($lastSeen === '' || $lastSeen === '0000-00-00 00:00:00') {
        sig_log("NOTHING {$label} : no last_seen_at");
        $stats['nothing']++;
        continue;
    }

    $daysAway = (int) floor((time() - strtotime($lastSeen)) / 86400);
    $state = sig_state_get($pdo, $uid);

    $kind = sig_never_engaged_kind($config, $user, $daysAway);
    $primed = $kind !== null;
    if ($kind === null) {
        $kind = sig_band_for_days($config, $daysAway);
    }

    if ($kind === null) {
        sig_log("NOTHING {$label} : active ({$daysAway}d < {$activeBefore}d)");
        $stats['nothing']++;
        continue;
    }

    $interval = sig_band_interval($config, $kind);
    if ($state && !empty($state['last_sent_at'])) {
        $elapsed = time() - strtotime((string) $state['last_sent_at']);
        if ($elapsed < $interval * 86400) {
            $prev = (string) ($state['last_kind'] ?? '?');
            $when = (string) $state['last_sent_at'];
            sig_log("NOTHING {$label} : too soon (last={$prev} on {$when}, need {$interval}d gap; away {$daysAway}d would be {$kind}"
                . ($primed ? ', never-engaged' : '') . ')');
            $stats['nothing']++;
            continue;
        }
    }

    if (!$args['dry_run'] && !sig_in_allowlist($config, $email)) {
        sig_log("NOTHING {$label} : not on allowlist");
        $stats['nothing']++;
        continue;
    }

    $threadStmt->execute([':since' => $lastSeen]);
    $rows = $threadStmt->fetchAll();
    $allNew = [];
    foreach ($rows as $row) {
        $did = (int) $row['id'];
        $allNew[] = [
            'id'    => $did,
            'title' => (string) $row['title'],
            'url'   => sig_discussion_url($config, $did, (string) ($row['slug'] ?? '')),
        ];
    }
    $newSince = [
        'total' => count($allNew),
        'shown' => array_slice($allNew, 0, $showNew),
    ];

    $msg = sig_reminder_message($config, $user, $kind, $daysAway, $newSince, $popular);
    $stats['by_kind'][$kind] = ($stats['by_kind'][$kind] ?? 0) + 1;

    if ($args['dry_run']) {
        sig_log("WOULD-SEND {$kind} {$label} {$daysAway}d"
            . ($primed ? ' (never-engaged)' : '')
            . " | {$msg['subject']}");
        $stats['would_send']++;
        continue;
    }

    $result = sig_send_mail($config, $email, $msg['subject'], $msg['text'], $msg['html']);
    if ($result['ok']) {
        sig_state_mark_reminder($pdo, $uid, $kind);
        sig_log("SENT {$kind} {$label} {$daysAway}d | {$msg['subject']}");
        $stats['sent']++;
    } else {
        sig_log("ERROR {$kind} {$label}: {$result['error']}");
        $stats['error']++;
    }
}

sig_log('reminders done: ' . json_encode($stats));
exit($stats['error'] > 0 ? 2 : 0);
