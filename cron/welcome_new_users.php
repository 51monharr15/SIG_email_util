<?php
/**
 * Daily: welcome emails for recent signups.
 *
 * Dry-run by default (config mail.dry_run or omit --send).
 *
 *   php cron/welcome_new_users.php
 *   php cron/welcome_new_users.php --dry-run
 *   php cron/welcome_new_users.php --send --limit=5
 *   php cron/welcome_new_users.php --send --user-id=123
 *
 * cPanel cron example (daily ~09:00):
 *   /usr/local/bin/php /home/USER/path/to/SIG-Stakeholder-Database/cron/welcome_new_users.php --send
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
$since = date('Y-m-d H:i:s', time() - max(1, $lookback) * 3600);

sig_log('welcome_new_users starting; mode=' . ($args['dry_run'] ? 'DRY-RUN' : 'SEND')
    . "; lookback_hours={$lookback}; since={$since}");

$sql = "SELECT id, username, nickname, email, joined_at, is_email_confirmed, preferences
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

$stats = ['candidates' => count($candidates), 'sent' => 0, 'dry_run' => 0, 'skipped' => 0, 'error' => 0];

foreach ($candidates as $user) {
    $uid = (int) $user['id'];
    $email = (string) $user['email'];

    if (sig_already_sent_welcome($pdo, $uid)) {
        sig_log("skip user {$uid} {$email}: welcome already sent");
        sig_mail_log_insert($pdo, $uid, $email, 'welcome', null, 'skipped', '', 'already_sent');
        $stats['skipped']++;
        continue;
    }

    // Also skip if a prior dry_run logged for same user in lookback? No — allow dry_run repeats; only 'sent' blocks.

    if (!$args['dry_run'] && !sig_in_allowlist($config, $email)) {
        sig_log("skip user {$uid} {$email}: not on allowlist");
        sig_mail_log_insert($pdo, $uid, $email, 'welcome', null, 'skipped', '', 'not_on_allowlist');
        $stats['skipped']++;
        continue;
    }

    $msg = sig_welcome_message($config, $user);

    if ($args['dry_run']) {
        sig_log("DRY-RUN welcome -> {$email} | {$msg['subject']}");
        sig_mail_log_insert($pdo, $uid, $email, 'welcome', null, 'dry_run', $msg['subject'], null, [
            'joined_at' => $user['joined_at'],
        ]);
        $stats['dry_run']++;
        continue;
    }

    $result = sig_send_mail($config, $email, $msg['subject'], $msg['text'], $msg['html']);
    if ($result['ok']) {
        sig_log("SENT welcome -> {$email}");
        sig_mail_log_insert($pdo, $uid, $email, 'welcome', null, 'sent', $msg['subject'], null, [
            'joined_at' => $user['joined_at'],
        ]);
        $stats['sent']++;
    } else {
        sig_log("ERROR welcome -> {$email}: {$result['error']}");
        sig_mail_log_insert($pdo, $uid, $email, 'welcome', null, 'error', $msg['subject'], $result['error']);
        $stats['error']++;
    }
}

sig_log('welcome_new_users done: ' . json_encode($stats));
exit($stats['error'] > 0 ? 2 : 0);
