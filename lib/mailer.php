<?php
/**
 * Minimal SMTP / mail() sender — no Composer dependencies (FTPS-friendly).
 */

require_once __DIR__ . '/bootstrap.php';

/**
 * @return array{ok:bool,error:?string}
 */
function sig_send_mail(array $config, string $to, string $subject, string $textBody, string $htmlBody = ''): array
{
    $fromEmail = (string) ($config['mail']['from_email'] ?? '');
    $fromName  = (string) ($config['mail']['from_name'] ?? '');
    if ($fromEmail === '' || $to === '') {
        return ['ok' => false, 'error' => 'Missing from_email or recipient'];
    }

    $smtp = $config['mail']['smtp'] ?? [];
    $host = is_array($smtp) ? trim((string) ($smtp['host'] ?? '')) : '';

    if ($host !== '') {
        return sig_smtp_send($config, $to, $subject, $textBody, $htmlBody);
    }

    $headers = [];
    $headers[] = 'From: ' . sig_mailbox($fromName, $fromEmail);
    $headers[] = 'MIME-Version: 1.0';
    if ($htmlBody !== '') {
        $boundary = 'bnd_' . bin2hex(random_bytes(8));
        $headers[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';
        $body = "--{$boundary}\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n\r\n"
            . $textBody . "\r\n"
            . "--{$boundary}\r\n"
            . "Content-Type: text/html; charset=UTF-8\r\n\r\n"
            . $htmlBody . "\r\n"
            . "--{$boundary}--\r\n";
    } else {
        $headers[] = 'Content-Type: text/plain; charset=UTF-8';
        $body = $textBody;
    }

    $ok = @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, implode("\r\n", $headers));
    return $ok ? ['ok' => true, 'error' => null] : ['ok' => false, 'error' => 'PHP mail() returned false'];
}

function sig_mailbox(string $name, string $email): string
{
    $email = trim($email);
    $name = trim($name);
    if ($name === '') {
        return $email;
    }
    return sprintf('"%s" <%s>', addcslashes($name, '"\\'), $email);
}

/**
 * @return array{ok:bool,error:?string}
 */
function sig_smtp_send(array $config, string $to, string $subject, string $textBody, string $htmlBody): array
{
    $smtp = $config['mail']['smtp'];
    $host = (string) $smtp['host'];
    $port = (int) ($smtp['port'] ?? 587);
    $enc  = strtolower((string) ($smtp['encryption'] ?? 'tls'));
    $user = (string) ($smtp['username'] ?? '');
    $pass = (string) ($smtp['password'] ?? '');
    $fromEmail = (string) $config['mail']['from_email'];
    $fromName  = (string) ($config['mail']['from_name'] ?? '');

    $remote = ($enc === 'ssl' ? 'ssl://' : '') . $host . ':' . $port;
    $errno = 0;
    $errstr = '';
    $fp = @stream_socket_client($remote, $errno, $errstr, 30);
    if (!$fp) {
        return ['ok' => false, 'error' => "SMTP connect failed: {$errstr} ({$errno})"];
    }
    stream_set_timeout($fp, 30);

    $read = function () use ($fp): string {
        $data = '';
        while (!feof($fp)) {
            $line = fgets($fp, 515);
            if ($line === false) {
                break;
            }
            $data .= $line;
            if (isset($line[3]) && $line[3] === ' ') {
                break;
            }
        }
        return $data;
    };
    $write = function (string $cmd) use ($fp): void {
        fwrite($fp, $cmd . "\r\n");
    };
    $expect = function (string $prefix, string $what) use ($read): ?string {
        $resp = $read();
        if (strpos($resp, $prefix) !== 0) {
            return trim($resp) !== '' ? trim($resp) : "bad response during {$what}";
        }
        return null;
    };

    if ($err = $expect('220', 'banner')) {
        fclose($fp);
        return ['ok' => false, 'error' => $err];
    }

    $ehloHost = gethostname() ?: 'localhost';
    $write('EHLO ' . $ehloHost);
    if ($err = $expect('250', 'EHLO')) {
        fclose($fp);
        return ['ok' => false, 'error' => $err];
    }

    if ($enc === 'tls') {
        $write('STARTTLS');
        if ($err = $expect('220', 'STARTTLS')) {
            fclose($fp);
            return ['ok' => false, 'error' => $err];
        }
        if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            fclose($fp);
            return ['ok' => false, 'error' => 'STARTTLS crypto failed'];
        }
        $write('EHLO ' . $ehloHost);
        if ($err = $expect('250', 'EHLO after TLS')) {
            fclose($fp);
            return ['ok' => false, 'error' => $err];
        }
    }

    if ($user !== '') {
        $write('AUTH LOGIN');
        if ($err = $expect('334', 'AUTH LOGIN')) {
            fclose($fp);
            return ['ok' => false, 'error' => $err];
        }
        $write(base64_encode($user));
        if ($err = $expect('334', 'AUTH user')) {
            fclose($fp);
            return ['ok' => false, 'error' => $err];
        }
        $write(base64_encode($pass));
        if ($err = $expect('235', 'AUTH pass')) {
            fclose($fp);
            return ['ok' => false, 'error' => $err];
        }
    }

    $write('MAIL FROM:<' . $fromEmail . '>');
    if ($err = $expect('250', 'MAIL FROM')) {
        fclose($fp);
        return ['ok' => false, 'error' => $err];
    }
    $write('RCPT TO:<' . $to . '>');
    if ($err = $expect('250', 'RCPT TO')) {
        fclose($fp);
        return ['ok' => false, 'error' => $err];
    }
    $write('DATA');
    if ($err = $expect('354', 'DATA')) {
        fclose($fp);
        return ['ok' => false, 'error' => $err];
    }

    $headers = [];
    $headers[] = 'Date: ' . date('r');
    $headers[] = 'From: ' . sig_mailbox($fromName, $fromEmail);
    $headers[] = 'To: <' . $to . '>';
    $headers[] = 'Subject: =?UTF-8?B?' . base64_encode($subject) . '?=';
    $headers[] = 'MIME-Version: 1.0';
    $headers[] = 'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . ($ehloHost) . '>';

    if ($htmlBody !== '') {
        $boundary = 'bnd_' . bin2hex(random_bytes(8));
        $headers[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';
        $body = "--{$boundary}\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: 8bit\r\n\r\n"
            . $textBody . "\r\n"
            . "--{$boundary}\r\n"
            . "Content-Type: text/html; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: 8bit\r\n\r\n"
            . $htmlBody . "\r\n"
            . "--{$boundary}--";
    } else {
        $headers[] = 'Content-Type: text/plain; charset=UTF-8';
        $headers[] = 'Content-Transfer-Encoding: 8bit';
        $body = $textBody;
    }

    // Dot-stuffing
    $payload = implode("\r\n", $headers) . "\r\n\r\n" . $body;
    $payload = preg_replace('/^\./m', '..', $payload);
    $write($payload . "\r\n.");

    if ($err = $expect('250', 'message body')) {
        fclose($fp);
        return ['ok' => false, 'error' => $err];
    }
    $write('QUIT');
    fclose($fp);
    return ['ok' => true, 'error' => null];
}
