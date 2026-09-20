<?php
/**
 * Daily: welcome emails for recent signups.
 *
 * File log explains everyone in the lookback window.
 * Database updated only on real successful send (sig_mail_state + thin sent log).
 *
 *   php cron/welcome_new_users.php --dry-run
 *   php cron/welcome_new_users.php --send
 */

require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once dirname(__DIR__) . '/lib/mailer.php';
require_once dirname(__DIR__) . '/lib/messages.php';

sig_require_cli();
$config = sig_load_config();
$args = sig_parse_args($argv, $config);

if ($args['help']) {
    echo "Usage: php welcome_new_users.php [--dry-run|--send] [--limit=N] [--user-id=N]\n";
    exit(0);
}

$pdo = sig_pdo($config);
$usersTable = sig_table($config, 'users');
$lookback = (int) ($config['welcome']['lookback_hours'] ?? 36);
$requireConfirmed = (bool) ($config['welcome']['require_confirmed'] ?? true);
$activeAfter = max(0, (int) ($config['welcome']['already_active_after_days'] ?? 1));
$since = date('Y-m-d H:i:s', time() - max(1, $lookback) * 3600);

sig_log('welcome_new_users starting; mode=' . ($args['dry_run'] ? 'DRY-RUN' : 'SEND')
    . "; lookback_hours={$lookback}; since={$since}");

$sql = "SELECT id, username, nickname, email, joined_at, last_seen_at, is_email_confirmed
        FROM {$usersTable}
        WHERE joined_at >= :since
          AND email IS NOT NULL AND email <> ''
          AND (suspended_until IS NULL OR suspended_until < NOW())";
$params = [':since' => $since];

if ($requireConfirmed) {
    $sql .= ' AND is_email_confirmed = 1';
}
if ($args['user_id'] !== null) {
    $sql .= ' AND id = :uid';
    $params[':uid'] = $args['user_id'];
}
$sql .= ' ORDER BY joined_at ASC';
if ($args['limit'] !== null) {
    $sql .= ' LIMIT ' . (int) $args['limit'];
}

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$candidates = $stmt->fetchAll();

$stats = ['candidates' => count($candidates), 'sent' => 0, 'would_send' => 0, 'nothing' => 0, 'error' => 0];

foreach ($candidates as $user) {
    $uid = (int) $user['id'];
    $email = (string) $user['email'];
    $label = "uid={$uid} {$email}";

    if (sig_already_sent_welcome($pdo, $uid)) {
        sig_log("NOTHING {$label} : welcome already sent");
        $stats['nothing']++;
        continue;
    }

    $joinedTs = strtotime((string) $user['joined_at']);
    $seenRaw = $user['last_seen_at'] ?? null;
    if ($joinedTs && $seenRaw) {
        $seenTs = strtotime((string) $seenRaw);
        if ($seenTs && $seenTs > $joinedTs) {
            $activeDays = (int) floor(($seenTs - $joinedTs) / 86400);
            if ($activeDays >= $activeAfter) {
                sig_log("NOTHING {$label} : already active (last_seen {$activeDays}d after join)");
                $stats['nothing']++;
                continue;
            }
        }
    }

    if (!$args['dry_run'] && !sig_in_allowlist($config, $email)) {
        sig_log("NOTHING {$label} : not on allowlist");
        $stats['nothing']++;
        continue;
    }

    $msg = sig_welcome_message($config, $user);

    if ($args['dry_run']) {
        sig_log("WOULD-SEND welcome {$label} | {$msg['subject']}");
        $stats['would_send']++;
        continue;
    }

    $result = sig_send_mail($config, $email, $msg['subject'], $msg['text'], $msg['html']);
    if ($result['ok']) {
        sig_state_mark_welcome($pdo, $uid);
        sig_log("SENT welcome {$label} | {$msg['subject']}");
        $stats['sent']++;
    } else {
        sig_log("ERROR welcome {$label}: {$result['error']}");
        $stats['error']++;
    }
}

sig_log('welcome_new_users done: ' . json_encode($stats));
exit($stats['error'] > 0 ? 2 : 0);
