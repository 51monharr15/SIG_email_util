<?php
/**
 * Email copy: edit HTML under templates/ only.
 * Plain-text parts are derived from that HTML so they cannot drift.
 */

require_once __DIR__ . '/bootstrap.php';

function sig_templates_dir(): string
{
    return dirname(__DIR__) . '/templates';
}

/**
 * @param array<string, string|int|float> $vars
 */
function sig_fill_placeholders(string $tpl, array $vars): string
{
    $search = [];
    $replace = [];
    foreach ($vars as $key => $value) {
        $search[] = '{{' . $key . '}}';
        $replace[] = (string) $value;
    }
    return str_replace($search, $replace, $tpl);
}

function sig_load_html_template(string $relativePath): string
{
    $path = sig_templates_dir() . '/' . ltrim(str_replace('\\', '/', $relativePath), '/');
    if (!is_readable($path)) {
        throw new RuntimeException('Missing template: ' . $relativePath);
    }
    $html = file_get_contents($path);
    if ($html === false) {
        throw new RuntimeException('Could not read template: ' . $relativePath);
    }
    return $html;
}

function sig_format_date(?string $dbDate): string
{
    if ($dbDate === null || trim($dbDate) === '' || $dbDate === '0000-00-00 00:00:00') {
        return '';
    }
    $ts = strtotime($dbDate);
    if ($ts === false) {
        return $dbDate;
    }
    return date('j M Y', $ts);
}

function sig_profile_url(array $config): string
{
    $base = rtrim((string) ($config['forum']['base_url'] ?? ''), '/');
    $path = (string) ($config['forum']['profile_path'] ?? '/settings');
    if ($path === '') {
        $path = '/settings';
    }
    if ($path[0] !== '/') {
        $path = '/' . $path;
    }
    return $base . $path;
}

/**
 * @return array<string, string>
 */
function sig_message_vars(array $config, array $user, int $daysAway = 0): array
{
    $joined = sig_format_date(isset($user['joined_at']) ? (string) $user['joined_at'] : null);
    $last   = sig_format_date(isset($user['last_seen_at']) ? (string) $user['last_seen_at'] : null);

    return [
        'name'         => sig_h(trim((string) ($user['nickname'] ?: $user['username']))),
        'forum'        => sig_h((string) ($config['forum']['name'] ?? 'our community')),
        'base_url'     => sig_h(rtrim((string) ($config['forum']['base_url'] ?? ''), '/')),
        'joined_on'    => sig_h($joined !== '' ? $joined : 'recently'),
        'last_visited' => sig_h($last !== '' ? $last : 'some time ago'),
        'days_ago'     => sig_h((string) max(0, $daysAway)),
        'profile_url'  => sig_h(sig_profile_url($config)),
    ];
}

/**
 * @return array{subject:string,html:string}
 */
function sig_extract_band(string $doc, string $bandId): array
{
    $id = preg_quote($bandId, '/');
    $pattern = '/<!--\s*band:' . $id
        . '\s+subject:(.*?)\s*-->(.*?)<!--\s*\/band:' . $id . '\s*-->/is';
    if (!preg_match($pattern, $doc, $m)) {
        throw new RuntimeException("reminders.html missing band block for: {$bandId}");
    }
    return [
        'subject' => trim($m[1]),
        'html'    => trim($m[2]),
    ];
}

/**
 * @return array{subject:string,html:string}
 */
function sig_split_html_email(string $doc): array
{
    $subject = '';
    if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $doc, $m)) {
        $subject = trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    if (preg_match('/<body[^>]*>(.*)<\/body>/is', $doc, $m)) {
        $html = trim($m[1]);
    } else {
        $html = preg_replace('/<title[^>]*>.*?<\/title>/is', '', $doc) ?? $doc;
        $html = preg_replace('/<\/?(?:!DOCTYPE|html|head|meta|link)[^>]*>/i', '', $html) ?? $html;
        $html = trim($html);
    }

    return ['subject' => $subject, 'html' => $html];
}

function sig_html_to_text(string $html): string
{
    $s = $html;

    $s = preg_replace_callback(
        '/<a\s+[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is',
        static function (array $m): string {
            $label = trim(html_entity_decode(strip_tags($m[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $url = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($label === '' || strcasecmp($label, $url) === 0) {
                return $url;
            }
            return $label . "\n" . $url;
        },
        $s
    ) ?? $s;

    $s = preg_replace('/<\s*br\s*\/?\s*>/i', "\n", $s) ?? $s;
    $s = preg_replace('/<\s*\/\s*p\s*>/i', "\n\n", $s) ?? $s;
    $s = preg_replace('/<\s*\/\s*h[1-6]\s*>/i', "\n\n", $s) ?? $s;
    $s = preg_replace('/<\s*h[1-6][^>]*>/i', '', $s) ?? $s;
    $s = preg_replace('/<\s*li[^>]*>/i', '- ', $s) ?? $s;
    $s = preg_replace('/<\s*\/\s*li\s*>/i', "\n", $s) ?? $s;
    $s = preg_replace('/<\s*\/\s*(?:ul|ol)\s*>/i', "\n", $s) ?? $s;
    $s = preg_replace('/<\s*(?:ul|ol|p)[^>]*>/i', '', $s) ?? $s;

    $s = strip_tags($s);
    $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $s = str_replace("\r\n", "\n", $s);
    $s = preg_replace("/[ \t]+\n/", "\n", $s) ?? $s;
    $s = preg_replace("/\n{3,}/", "\n\n", $s) ?? $s;

    return trim($s) . "\n";
}

/**
 * @param array<int, array{title:string,url:string,created_at?:string}> $shown
 */
function sig_render_thread_list_html(array $config, array $shown, int $totalCount): string
{
    $base = sig_h(rtrim((string) ($config['forum']['base_url'] ?? ''), '/'));
    $totalCount = max(0, $totalCount);
    $shown = array_values($shown);

    if ($totalCount === 0) {
        return '<p>There were no public new threads since your last visit, but the door is open: '
            . '<a href="' . $base . '">' . $base . '</a>.</p>';
    }

    $parts = [];
    if ($totalCount === 1) {
        $parts[] = '<p>There has been <strong>1</strong> new public thread since your last visit:</p>';
    } elseif ($totalCount <= count($shown)) {
        $parts[] = '<p>There have been <strong>' . $totalCount
            . '</strong> new public threads since your last visit:</p>';
    } else {
        $parts[] = '<p>There have been <strong>' . $totalCount
            . '</strong> new public threads since your last visit. Here are '
            . count($shown) . ' of them:</p>';
    }

    $parts[] = '<ul>';
    foreach ($shown as $t) {
        $parts[] = '<li><a href="' . sig_h((string) $t['url']) . '">'
            . sig_h((string) $t['title']) . '</a></li>';
    }
    $parts[] = '</ul>';

    if ($totalCount > count($shown)) {
        $parts[] = '<p>See the rest on the forum: <a href="' . $base . '">' . $base . '</a></p>';
    }

    return implode('', $parts);
}

/**
 * @param array<int, array{title:string,url:string,comment_count?:int}> $popular
 */
function sig_render_popular_html(array $popular): string
{
    if ($popular === []) {
        return '<p><em>Nothing stood out as especially busy just now — the forum is still open when you are ready.</em></p>';
    }
    $parts = ['<ul>'];
    foreach ($popular as $t) {
        $title = sig_h((string) $t['title']);
        $url = sig_h((string) $t['url']);
        $extra = '';
        if (isset($t['comment_count'])) {
            $n = (int) $t['comment_count'];
            $extra = ' <span style="color:#666;font-size:0.9em">(' . $n
                . ($n === 1 ? ' reply' : ' replies') . ')</span>';
        }
        $parts[] = '<li><a href="' . $url . '">' . $title . '</a>' . $extra . '</li>';
    }
    $parts[] = '</ul>';
    return implode('', $parts);
}

/**
 * @return array<int, array{id:int,title:string,slug:string,url:string,comment_count:int}>
 */
function sig_fetch_popular_threads(PDO $pdo, array $config): array
{
    $cfg = $config['reminders'] ?? $config['reengage'] ?? [];
    $days = max(1, (int) ($cfg['popular_days'] ?? 30));
    $limit = max(1, (int) ($cfg['popular_limit'] ?? 5));
    $since = date('Y-m-d H:i:s', time() - $days * 86400);

    $discTable = sig_table($config, 'discussions');
    $sql = "SELECT id, title, slug, comment_count, last_posted_at
            FROM {$discTable}
            WHERE hidden_at IS NULL
              AND (is_private = 0 OR is_private IS NULL)
              AND last_posted_at IS NOT NULL
              AND last_posted_at >= :since
            ORDER BY comment_count DESC, last_posted_at DESC
            LIMIT {$limit}";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':since' => $since]);
    $rows = $stmt->fetchAll();

    $out = [];
    foreach ($rows as $row) {
        $id = (int) $row['id'];
        $out[] = [
            'id'            => $id,
            'title'         => (string) $row['title'],
            'slug'          => (string) ($row['slug'] ?? ''),
            'url'           => sig_discussion_url($config, $id, (string) ($row['slug'] ?? '')),
            'comment_count' => (int) ($row['comment_count'] ?? 0),
        ];
    }
    return $out;
}

/**
 * @return array{subject:string,text:string,html:string}
 */
function sig_finalize_message(string $subjectTpl, string $htmlTpl, array $vars): array
{
    $subject = trim(html_entity_decode(
        strip_tags(sig_fill_placeholders($subjectTpl, $vars)),
        ENT_QUOTES | ENT_HTML5,
        'UTF-8'
    ));
    $html = sig_fill_placeholders($htmlTpl, $vars);
    $html = preg_replace('/<!--.*?-->/s', '', $html) ?? $html;
    $html = trim($html);
    if ($subject === '') {
        throw new RuntimeException('Email subject resolved empty');
    }
    return [
        'subject' => $subject,
        'html'    => $html,
        'text'    => sig_html_to_text($html),
    ];
}

/**
 * @return array{subject:string,text:string,html:string}
 */
function sig_welcome_message(array $config, array $user): array
{
    $doc = sig_load_html_template('welcome.html');
    $parts = sig_split_html_email($doc);
    if ($parts['subject'] === '') {
        throw new RuntimeException('welcome.html missing <title> subject');
    }
    return sig_finalize_message($parts['subject'], $parts['html'], sig_message_vars($config, $user, 0));
}

/**
 * @param array{total:int,shown:array<int,array{title:string,url:string}>} $newSince
 * @param array<int, array{title:string,url:string,comment_count?:int}> $popular
 * @return array{subject:string,text:string,html:string}
 */
function sig_reminder_message(
    array $config,
    array $user,
    string $bandId,
    int $daysAway,
    array $newSince,
    array $popular = []
): array {
    $doc = sig_load_html_template('reminders.html');
    $parts = sig_extract_band($doc, $bandId);

    $total = (int) ($newSince['total'] ?? 0);
    $shown = is_array($newSince['shown'] ?? null) ? $newSince['shown'] : [];

    $vars = sig_message_vars($config, $user, $daysAway);
    $vars['new_since_count'] = sig_h((string) $total);
    $vars['thread_list'] = sig_render_thread_list_html($config, $shown, $total);
    $vars['popular'] = sig_render_popular_html($popular);

    return sig_finalize_message($parts['subject'], $parts['html'], $vars);
}
