<?php
/**
 * Copy templates for welcome + tiered re-engage messages.
 */

require_once __DIR__ . '/bootstrap.php';

/**
 * @return array{subject:string,text:string,html:string}
 */
function sig_welcome_message(array $config, array $user): array
{
    $forum = (string) ($config['forum']['name'] ?? 'our community');
    $base  = rtrim((string) ($config['forum']['base_url'] ?? ''), '/');
    $name  = trim((string) ($user['nickname'] ?: $user['username']));

    $subject = "Welcome to {$forum}";
    $text = "Hello {$name},\n\n"
        . "Welcome to {$forum}. We're glad you joined.\n\n"
        . "When you have a moment, come say hello and look around:\n{$base}\n\n"
        . "There is no rush — visit whenever it suits you.\n\n"
        . "— {$forum}\n";

    $html = '<p>Hello ' . sig_h($name) . ',</p>'
        . '<p>Welcome to ' . sig_h($forum) . '. We\'re glad you joined.</p>'
        . '<p>When you have a moment, come <a href="' . sig_h($base) . '">say hello and look around</a>.</p>'
        . '<p>There is no rush — visit whenever it suits you.</p>'
        . '<p>— ' . sig_h($forum) . '</p>';

    return compact('subject', 'text', 'html');
}

/**
 * @param 'soft'|'softer'|'gentle' $tier
 * @param array<int, array{category:string,threads:array<int,array{id:int,title:string,slug:string,created_at:string,url:string}>}> $grouped
 * @return array{subject:string,text:string,html:string}
 */
function sig_reengage_message(array $config, array $user, string $tier, int $daysAway, array $grouped): array
{
    $forum = (string) ($config['forum']['name'] ?? 'our community');
    $base  = rtrim((string) ($config['forum']['base_url'] ?? ''), '/');
    $name  = trim((string) ($user['nickname'] ?: $user['username']));

    if ($tier === 'gentle') {
        $subject = "A quiet hello from {$forum}";
        $introText = "Hello {$name},\n\n"
            . "It has been a while since you last visited {$forum} — that is completely fine.\n"
            . "No pressure at all; we just wanted to leave a gentle note in case anything here is still of interest.\n";
        $introHtml = '<p>Hello ' . sig_h($name) . ',</p>'
            . '<p>It has been a while since you last visited ' . sig_h($forum)
            . ' — that is completely fine. No pressure at all; we just wanted to leave a gentle note '
            . 'in case anything here is still of interest.</p>';
    } elseif ($tier === 'softer') {
        $subject = "Still here when you are — {$forum}";
        $introText = "Hello {$name},\n\n"
            . "We noticed it has been some time since your last visit to {$forum}.\n"
            . "You are welcome back whenever it suits you; here are a few newer threads that appeared since then.\n";
        $introHtml = '<p>Hello ' . sig_h($name) . ',</p>'
            . '<p>We noticed it has been some time since your last visit to ' . sig_h($forum)
            . '. You are welcome back whenever it suits you; here are a few newer threads that appeared since then.</p>';
    } else {
        $subject = "Recent discussions at {$forum}";
        $introText = "Hello {$name},\n\n"
            . "Here is a short digest of newer threads at {$forum} since your last visit.\n";
        $introHtml = '<p>Hello ' . sig_h($name) . ',</p>'
            . '<p>Here is a short digest of newer threads at ' . sig_h($forum) . ' since your last visit.</p>';
    }

    $textParts = [$introText];
    $htmlParts = [$introHtml];

    if ($grouped === []) {
        $textParts[] = "\n(There were no public new threads to list, but the door is open: {$base})\n";
        $htmlParts[] = '<p>There were no public new threads to list, but the door is open: '
            . '<a href="' . sig_h($base) . '">' . sig_h($base) . '</a>.</p>';
    } else {
        foreach ($grouped as $block) {
            $cat = $block['category'];
            $textParts[] = "\n## {$cat}\n";
            $htmlParts[] = '<h3 style="margin:1.2em 0 0.4em">' . sig_h($cat) . '</h3><ul>';
            foreach ($block['threads'] as $t) {
                $textParts[] = '- ' . $t['title'] . "\n  " . $t['url'] . "\n";
                $htmlParts[] = '<li><a href="' . sig_h($t['url']) . '">' . sig_h($t['title']) . '</a>'
                    . ' <span style="color:#666;font-size:0.9em">(' . sig_h($t['created_at']) . ')</span></li>';
            }
            $htmlParts[] = '</ul>';
        }
    }

    $textParts[] = "\nVisit whenever you like: {$base}\n\n— {$forum}\n";
    $htmlParts[] = '<p>Visit whenever you like: <a href="' . sig_h($base) . '">' . sig_h($base) . '</a></p>'
        . '<p>— ' . sig_h($forum) . '</p>';

    return [
        'subject' => $subject,
        'text'    => implode('', $textParts),
        'html'    => implode('', $htmlParts),
    ];
}

/**
 * Map days-away and base n to tone tier.
 *
 * @return 'soft'|'softer'|'gentle'
 */
function sig_reengage_tier(int $daysAway, int $n): string
{
    if ($daysAway >= 3 * $n) {
        return 'gentle';
    }
    if ($daysAway >= 2 * $n) {
        return 'softer';
    }
    return 'soft';
}
