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
    return $config;
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
 * @param array<string, mixed>|null $meta
 */
function sig_mail_log_insert(
    PDO $pdo,
    int $userId,
    string $email,
    string $kind,
    ?string $tier,
    string $status,
    string $subject,
    ?string $skipReason = null,
    ?array $meta = null
): void {
    $stmt = $pdo->prepare(
        'INSERT INTO ' . sig_util_table('sig_mail_log') . '
         (user_id, email, kind, tier, status, subject, skip_reason, meta_json, created_at)
         VALUES (:user_id, :email, :kind, :tier, :status, :subject, :skip_reason, :meta_json, :created_at)'
    );
    $stmt->execute([
        ':user_id'     => $userId,
        ':email'       => $email,
        ':kind'        => $kind,
        ':tier'        => $tier,
        ':status'      => $status,
        ':subject'     => substr($subject, 0, 255),
        ':skip_reason' => $skipReason,
        ':meta_json'   => $meta === null ? null : json_encode($meta, JSON_UNESCAPED_UNICODE),
        ':created_at'  => date('Y-m-d H:i:s'),
    ]);
}

function sig_already_sent_welcome(PDO $pdo, int $userId): bool
{
    $stmt = $pdo->prepare(
        'SELECT 1 FROM ' . sig_util_table('sig_mail_log') . '
         WHERE user_id = :uid AND kind = \'welcome\' AND status = \'sent\'
         LIMIT 1'
    );
    $stmt->execute([':uid' => $userId]);
    return (bool) $stmt->fetchColumn();
}

function sig_last_reengage_at(PDO $pdo, int $userId): ?string
{
    // Only real sends enforce the interval so dry-runs stay repeatable while testing.
    $stmt = $pdo->prepare(
        'SELECT created_at FROM ' . sig_util_table('sig_mail_log') . '
         WHERE user_id = :uid AND kind = \'reengage\' AND status = \'sent\'
         ORDER BY id DESC LIMIT 1'
    );
    $stmt->execute([':uid' => $userId]);
    $v = $stmt->fetchColumn();
    return $v === false ? null : (string) $v;
}

function sig_allow_reengage_pref(PDO $pdo, int $userId): bool
{
    $stmt = $pdo->prepare(
        'SELECT allow_reengage FROM ' . sig_util_table('sig_mail_prefs') . '
         WHERE user_id = :uid LIMIT 1'
    );
    $stmt->execute([':uid' => $userId]);
    $v = $stmt->fetchColumn();
    if ($v === false) {
        return true; // no row = allowed
    }
    return (bool) (int) $v;
}

/**
 * If the user has stored notify_*_email prefs and every one is false, treat as opted out of email digests.
 * Missing / empty preferences => allow (Flarum defaults are extension-dependent).
 */
function sig_flarum_allows_email(?string $preferencesJson): bool
{
    if ($preferencesJson === null || $preferencesJson === '') {
        return true;
    }
    $prefs = json_decode($preferencesJson, true);
    if (!is_array($prefs)) {
        return true;
    }
    $emailKeys = [];
    foreach ($prefs as $key => $val) {
        if (is_string($key) && preg_match('/^notify_.+_email$/', $key)) {
            $emailKeys[$key] = (bool) $val;
        }
    }
    if ($emailKeys === []) {
        return true;
    }
    foreach ($emailKeys as $on) {
        if ($on) {
            return true;
        }
    }
    return false;
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
