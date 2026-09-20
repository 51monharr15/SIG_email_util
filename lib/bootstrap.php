<?php
/**
 * Shared bootstrap for CLI mail utilities (and helpers usable by other tools).
 * Requires config.php in the repo root (same as lusers.php).
 */

function sig_require_cli(): void
{
    if (PHP_SAPI !== 'cli') {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo "CLI only.\n";
        exit(1);
    }
}

/**
 * @return array<string, mixed>
 */
function sig_load_config(): array
{
    $configFile = dirname(__DIR__) . '/config.php';
    if (!is_readable($configFile)) {
        fwrite(STDERR, "Missing config.php (copy from config.example.php).\n");
        exit(1);
    }
    $config = require $configFile;
    if (!is_array($config)) {
        fwrite(STDERR, "config.php must return an array.\n");
        exit(1);
    }
    sig_apply_timezone($config);
    return $config;
}

/**
 * App-level timezone (dates in logs / email copy).
 * Does NOT silence the PHP Startup warning if date.timezone is empty in php.ini —
 * fix that in cPanel MultiPHP INI Editor (see DEVELOPER_GUIDE).
 */
function sig_apply_timezone(array $config): void
{
    $tz = trim((string) ($config['timezone'] ?? 'Europe/London'));
    if ($tz === '') {
        $tz = 'Europe/London';
    }
    if (@date_default_timezone_set($tz) === false) {
        date_default_timezone_set('UTC');
        fwrite(STDERR, "Invalid config timezone '{$tz}'; using UTC.\n");
    }
}

function sig_pdo(array $config): PDO
{
    $db = $config['database'] ?? null;
    if (!is_array($db)) {
        fwrite(STDERR, "config.php has no database settings.\n");
        exit(1);
    }
    $host    = $db['host'] ?? 'localhost';
    $port    = (int) ($db['port'] ?? 3306);
    $dbname  = $db['database'] ?? '';
    $user    = $db['username'] ?? '';
    $pass    = $db['password'] ?? '';
    $charset = $db['charset'] ?? 'utf8mb4';

    $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset={$charset}";
    try {
        return new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    } catch (PDOException $e) {
        fwrite(STDERR, 'DB connection failed: ' . $e->getMessage() . "\n");
        exit(1);
    }
}

function sig_prefix(array $config): string
{
    return (string) (($config['database']['prefix'] ?? '') ?: '');
}

function sig_table(array $config, string $name): string
{
    return '`' . str_replace('`', '``', sig_prefix($config) . $name) . '`';
}

/** Unprefixed utility tables live beside Flarum tables. */
function sig_util_table(string $name): string
{
    return '`' . str_replace('`', '``', $name) . '`';
}

/**
 * Parse argv flags: --send, --dry-run, --limit=N, --user-id=N, --n-days=N
 *
 * @return array{send:bool,dry_run:bool,limit:?int,user_id:?int,n_days:?int,help:bool}
 */
function sig_parse_args(array $argv, array $config): array
{
    $mailDry = (bool) ($config['mail']['dry_run'] ?? true);
    $out = [
        'send'    => false,
        'dry_run' => $mailDry,
        'limit'   => null,
        'user_id' => null,
        'n_days'  => null,
        'help'    => false,
    ];

    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--help' || $arg === '-h') {
            $out['help'] = true;
        } elseif ($arg === '--send') {
            $out['send'] = true;
            $out['dry_run'] = false;
        } elseif ($arg === '--dry-run') {
            $out['dry_run'] = true;
            $out['send'] = false;
        } elseif (preg_match('/^--limit=(\d+)$/', $arg, $m)) {
            $out['limit'] = (int) $m[1];
        } elseif (preg_match('/^--user-id=(\d+)$/', $arg, $m)) {
            $out['user_id'] = (int) $m[1];
        } elseif (preg_match('/^--n-days=(\d+)$/', $arg, $m)) {
            $out['n_days'] = (int) $m[1];
        }
    }

    // --dry-run wins if both somehow set
    if ($out['dry_run']) {
        $out['send'] = false;
    }

    return $out;
}

function sig_log(string $msg): void
{
    $ts = date('Y-m-d H:i:s');
    echo "[{$ts}] {$msg}\n";
}

/**
 * @return array{welcome_sent_at:?string,last_kind:?string,last_sent_at:?string,updated_at:?string}|null
 */
function sig_state_get(PDO $pdo, int $userId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT welcome_sent_at, last_kind, last_sent_at, updated_at
         FROM ' . sig_util_table('sig_mail_state') . ' WHERE user_id = :uid LIMIT 1'
    );
    $stmt->execute([':uid' => $userId]);
    $row = $stmt->fetch();
    return $row === false ? null : $row;
}

function sig_state_mark_welcome(PDO $pdo, int $userId): void
{
    $now = date('Y-m-d H:i:s');
    $pdo->prepare(
        'INSERT INTO ' . sig_util_table('sig_mail_state') . '
         (user_id, welcome_sent_at, last_kind, last_sent_at, updated_at)
         VALUES (:uid, :now, NULL, NULL, :now)
         ON DUPLICATE KEY UPDATE welcome_sent_at = VALUES(welcome_sent_at), updated_at = VALUES(updated_at)'
    )->execute([':uid' => $userId, ':now' => $now]);
}

function sig_state_mark_reminder(PDO $pdo, int $userId, string $kind): void
{
    $now = date('Y-m-d H:i:s');
    $pdo->prepare(
        'INSERT INTO ' . sig_util_table('sig_mail_state') . '
         (user_id, welcome_sent_at, last_kind, last_sent_at, updated_at)
         VALUES (:uid, NULL, :kind, :now, :now)
         ON DUPLICATE KEY UPDATE last_kind = VALUES(last_kind), last_sent_at = VALUES(last_sent_at), updated_at = VALUES(updated_at)'
    )->execute([':uid' => $userId, ':kind' => $kind, ':now' => $now]);
}

function sig_already_sent_welcome(PDO $pdo, int $userId): bool
{
    $state = sig_state_get($pdo, $userId);
    return $state !== null && !empty($state['welcome_sent_at']);
}

function sig_in_allowlist(array $config, string $email): bool
{
    $list = $config['mail']['allowlist'] ?? [];
    if (!is_array($list) || $list === []) {
        return true;
    }
    $email = strtolower(trim($email));
    foreach ($list as $allowed) {
        if (strtolower(trim((string) $allowed)) === $email) {
            return true;
        }
    }
    return false;
}

/**
 * @return list<array{id:string,min_days:int,max_days:?int,min_interval_days:int}>
 */
function sig_reminder_bands(array $config): array
{
    $bands = $config['reminders']['bands'] ?? null;
    if (!is_array($bands) || $bands === []) {
        return [
            ['id' => 'digest', 'min_days' => 14, 'max_days' => 44, 'min_interval_days' => 14],
            ['id' => 'away', 'min_days' => 45, 'max_days' => 90, 'min_interval_days' => 30],
            ['id' => 'long', 'min_days' => 91, 'max_days' => 180, 'min_interval_days' => 90],
            ['id' => 'dormant', 'min_days' => 181, 'max_days' => null, 'min_interval_days' => 90],
        ];
    }
    $out = [];
    foreach ($bands as $b) {
        if (!is_array($b) || empty($b['id'])) {
            continue;
        }
        $out[] = [
            'id' => (string) $b['id'],
            'min_days' => max(0, (int) ($b['min_days'] ?? 0)),
            'max_days' => array_key_exists('max_days', $b) && $b['max_days'] !== null && $b['max_days'] !== ''
                ? (int) $b['max_days'] : null,
            'min_interval_days' => max(1, (int) ($b['min_interval_days'] ?? 90)),
        ];
    }
    return $out;
}

/**
 * Pick band id for days-away, or null if still “active” (below first band).
 */
function sig_band_for_days(array $config, int $daysAway): ?string
{
    foreach (sig_reminder_bands($config) as $b) {
        if ($daysAway < $b['min_days']) {
            continue;
        }
        if ($b['max_days'] !== null && $daysAway > $b['max_days']) {
            continue;
        }
        return $b['id'];
    }
    return null;
}

function sig_band_interval(array $config, string $bandId): int
{
    foreach (sig_reminder_bands($config) as $b) {
        if ($b['id'] === $bandId) {
            return $b['min_interval_days'];
        }
    }
    return 90;
}

function sig_active_threshold_days(array $config): int
{
    $min = null;
    foreach (sig_reminder_bands($config) as $b) {
        $min = $min === null ? $b['min_days'] : min($min, $b['min_days']);
    }
    return $min ?? 14;
}

/**
 * Joined ≈ last seen, long ago → never-engaged priming band (default dormant).
 */
function sig_never_engaged_kind(array $config, array $user, int $daysAway): ?string
{
    $ne = $config['reminders']['never_engaged'] ?? [];
    if (!is_array($ne) || $ne === []) {
        return null;
    }
    $within = max(0, (int) ($ne['join_last_seen_within_days'] ?? 7));
    $minAgo = max(1, (int) ($ne['min_days_ago'] ?? 100));
    $kind = (string) ($ne['kind'] ?? 'dormant');
    if ($daysAway < $minAgo) {
        return null;
    }
    $joined = isset($user['joined_at']) ? strtotime((string) $user['joined_at']) : false;
    $seen = isset($user['last_seen_at']) ? strtotime((string) $user['last_seen_at']) : false;
    if ($joined === false || $seen === false) {
        return null;
    }
    $gapDays = (int) floor(abs($seen - $joined) / 86400);
    if ($gapDays > $within) {
        return null;
    }
    return $kind;
}

function sig_discussion_url(array $config, int $id, string $slug): string
{
    $base = rtrim((string) ($config['forum']['base_url'] ?? ''), '/');
    $slug = trim($slug);
    if ($slug === '') {
        return $base . '/d/' . $id;
    }
    return $base . '/d/' . $id . '-' . rawurlencode($slug);
}

function sig_h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
