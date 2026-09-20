<?php
/**
 * Drop unused test tables; create / verify sig_mail_state.
 *
 *   php cron/install_mail_tables.php
 */
require_once dirname(__DIR__) . '/lib/bootstrap.php';

sig_require_cli();
$config = sig_load_config();
$pdo = sig_pdo($config);

$sqlFile = dirname(__DIR__) . '/sql/sig_mail_tables.sql';
if (!is_readable($sqlFile)) {
    fwrite(STDERR, "Missing sql/sig_mail_tables.sql\n");
    exit(1);
}
$sql = file_get_contents($sqlFile);
if ($sql === false) {
    fwrite(STDERR, "Could not read sql/sig_mail_tables.sql\n");
    exit(1);
}

$lines = preg_replace('/^--.*$/m', '', $sql) ?? $sql;
$parts = array_filter(array_map('trim', explode(';', $lines)));
foreach ($parts as $statement) {
    if ($statement !== '') {
        $pdo->exec($statement);
    }
}

sig_log('Dropped sig_mail_log / sig_mail_prefs if present; installed/verified sig_mail_state');
