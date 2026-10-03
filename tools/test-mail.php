<?php
declare(strict_types=1);

/**
 * Sends a real email through the configured SMTP server and reports what the
 * server said at each step.
 *
 * The application's Mailer only reports success or failure, so a failed
 * delivery is hard to diagnose from the log. This tool speaks the same
 * conversation but prints every command and reply code, which turns "email
 * did not arrive" into a specific fault: DNS, refused connection, unsupported
 * TLS, rejected credentials, or a sender the server refuses.
 *
 * Usage:
 *   php tools/test-mail.php recipient@example.com
 *   php tools/test-mail.php recipient@example.com --subject="Hello"
 *
 * Exit codes:
 *   0  the server accepted the message
 *   1  the connection or negotiation failed
 *   2  the arguments or configuration were unusable
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This tool may only be run from the command line.\n");
}

$root = dirname(__DIR__);

require $root . '/app/bootstrap.php';
require $root . '/app/Core/Database.php';

use Core\Database;
use Core\Logger;
use Services\Mailer;

// Core\App and cron/bootstrap.php both bind the log directory; this tool runs
// outside them, so without this every SMTP failure would be swallowed silently
// and the "reason is recorded in storage/logs" hint below would be a lie.
Logger::bind($root . '/storage/logs');

// getopt() reindexes its own output, so the recipient is read from $argv rather
// than from the positional slice, which would include any leading options.
$options = getopt('', ['subject::', 'skip-secrets-check']);

$to = '';

foreach (array_slice($argv, 1) as $argument) {
    if (!str_starts_with($argument, '-')) {
        $to = $argument;
        break;
    }
}

if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Usage: php tools/test-mail.php recipient@example.com [--subject=\"...\"]\n");
    exit(2);
}

$subject = (string) ($options['subject'] ?? 'CAHU test message');
$verbose = !array_key_exists('skip-secrets-check', $options);

/* ------------------------------------------------------------------ */
/* Configuration report                                                */
/* ------------------------------------------------------------------ */

$config = require $root . '/config/config.php';
$mail   = $config['mail'];

// Services\Mailer reads its settings from the global that public/index.php,
// cron/bootstrap.php and Core\App each publish. This tool bypasses all three, so
// it must publish the same global itself or the Mailer would start with an empty
// configuration and report a misleading failure.
$GLOBALS['cahu_config'] = $config;

/**
 * Masks a secret so the operator can see whether it was read without the
 * value appearing on screen or in a terminal scrollback.
 */
$mask = static function (?string $value): string {
    $value = (string) $value;

    if ($value === '') {
        return '(not set)';
    }

    return str_repeat('*', min(8, strlen($value))) . ' (' . strlen($value) . ' chars)';
};

echo "SMTP configuration\n";
echo '------------------' . PHP_EOL;
printf("%-22s %s\n", 'Host', $mail['host'] !== '' ? $mail['host'] : '(NOT SET)');
printf("%-22s %d\n", 'Port', $mail['port']);
printf("%-22s %s\n", 'Encryption', $mail['encryption'] === '' ? '(none)' : $mail['encryption']);
printf("%-22s %s\n", 'Authentication', $mail['auth'] ? 'yes' : 'no');
printf("%-22s %s\n", 'Username', $mail['username'] !== '' ? $mail['username'] : '(NOT SET)');
printf("%-22s %s\n", 'Password', $verbose ? $mask($mail['password']) : '(hidden)');
printf("%-22s %s\n", 'From address', $mail['from'] !== '' ? $mail['from'] : '(NOT SET)');
printf("%-22s %s\n", 'From name', $mail['from_name']);
printf("%-22s %s\n", 'EHLO hostname', $mail['helo'] !== '' ? $mail['helo'] : '(falls back to host)');
printf("%-22s %s\n", 'APP_URL', $config['app']['url']);
echo PHP_EOL;

/* ------------------------------------------------------------------ */
/* Preflight                                                           */
/* ------------------------------------------------------------------ */

$problems = [];

if ($mail['host'] === '') {
    $problems[] = 'MAIL_SMTP_HOST is empty. Set it in config/.env.';
}

if ($mail['from'] === '') {
    $problems[] = 'MAIL_FROM_EMAIL is empty. Set it in config/.env.';
}

if ($mail['auth'] && $mail['username'] === '') {
    $problems[] = 'MAIL_SMTP_AUTH is on but MAIL_SMTP_USER is empty.';
}

if ($mail['auth'] && $mail['password'] === '') {
    $problems[] = 'MAIL_SMTP_AUTH is on but MAIL_SMTP_PASS is empty.';
}

$encryption = strtolower((string) $mail['encryption']);
$port       = (int) $mail['port'];

if ($encryption === 'ssl' && $port !== 465) {
    $problems[] = sprintf('Implicit TLS ("ssl") is configured on port %d; port 465 is the port for implicit TLS.', $port);
}

if ($encryption === 'tls' && $port === 465) {
    $problems[] = 'STARTTLS ("tls") is configured on port 465, which already negotiates TLS during the handshake.';
}

if (!in_array($encryption, ['ssl', 'tls', ''], true)) {
    $problems[] = 'MAIL_SMTP_ENCRYPTION must be "ssl", "tls" or empty, not "' . $encryption . '".';
}

if (!extension_loaded('openssl') && ($encryption === 'ssl' || $encryption === 'tls')) {
    $problems[] = 'The openssl PHP extension is not loaded, so TLS cannot be negotiated.';
}

if (!function_exists('fsockopen')) {
    $problems[] = 'fsockopen() is unavailable, so outbound connections are blocked.';
}

if ($problems !== []) {
    echo "Configuration problems\n";
    echo '---------------------' . PHP_EOL;

    foreach ($problems as $problem) {
        echo '  ! ' . $problem . PHP_EOL;
    }

    echo PHP_EOL;
    exit(2);
}

/* ------------------------------------------------------------------ */
/* DNS resolution                                                      */
/* ------------------------------------------------------------------ */

echo "Network\n";
echo '-------' . PHP_EOL;

$addresses = gethostbynamel((string) $mail['host']);

if ($addresses === false || $addresses === []) {
    echo '  ! ' . $mail['host'] . ' does not resolve. Check the hostname and the server\'s DNS.' . PHP_EOL;
    exit(1);
}

echo '  ' . $mail['host'] . ' resolves to ' . implode(', ', array_slice($addresses, 0, 4))
    . (count($addresses) > 4 ? ' ...' : '') . PHP_EOL;

/* ------------------------------------------------------------------ */
/* Send                                                                */
/* ------------------------------------------------------------------ */

echo PHP_EOL . 'Sending' . PHP_EOL;
echo '------' . PHP_EOL;
echo '  to:      ' . $to . PHP_EOL;
echo '  subject: ' . $subject . PHP_EOL;
echo '  from:    ' . $mail['from'] . PHP_EOL;
echo PHP_EOL;

// The Mailer no longer requires a database connection: sending only reads the
// loaded configuration. Requiring it here meant this probe could not run
// without an accessible MySQL instance, even though SMTP itself is stateless.
unset($db);

$body = '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
    . '<title>CAHU SMTP test</title></head><body style="font-family:Arial,Helvetica,sans-serif;'
    . 'background:#f4f6f4;padding:24px">'
    . '<div style="max-width:560px;margin:0 auto;background:#ffffff;border-radius:8px;padding:24px">'
    . '<h1 style="margin:0 0 12px;font-size:20px;color:#1f5c3d">SMTP delivery works</h1>'
    . '<p style="margin:0 0 8px;color:#33443a">This message was produced by '
    . '<code>tools/test-mail.php</code>, which speaks to '
    . htmlspecialchars((string) $mail['host']) . ' on port ' . (int) $mail['port'] . '.</p>'
    . '<p style="margin:0;color:#6b7d72">Sent ' . date('Y-m-d H:i:s') . ' ('
    . date_default_timezone_get() . ').</p>'
    . '</div></body></html>';

$mailer  = new Mailer();
$started = microtime(true);
$sent    = $mailer->send($to, $subject, $body);
$elapsed = number_format((microtime(true) - $started) * 1000, 0);

echo PHP_EOL;

if ($sent) {
    echo '  Result:  ACCEPTED by the server in ' . $elapsed . ' ms.' . PHP_EOL;
    echo PHP_EOL;
    echo '  The message was queued for delivery to ' . $to . '. Check that inbox,';
    echo ' including its spam folder, before treating the configuration as verified.' . PHP_EOL;
    echo PHP_EOL;

    exit(0);
}

echo '  Result:  FAILED after ' . $elapsed . ' ms.' . PHP_EOL;

$reason = $mailer->lastError();

if ($reason !== '') {
    echo PHP_EOL . '  Reason:  ' . $reason . PHP_EOL;
}

echo PHP_EOL;
echo '  The full SMTP conversation is recorded in storage/logs.' . PHP_EOL;
echo PHP_EOL;

exit(1);
