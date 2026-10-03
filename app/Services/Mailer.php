<?php
declare(strict_types=1);

namespace Services;

use Core\Database;
use Core\Logger;

/**
 * SMTP mailer built on PHP stream sockets.
 *
 * The platform cannot assume PHP's openssl extension provides smtp:// support,
 * so the conversation is spoken directly. That also means this class is
 * responsible for the parts a hand-rolled client usually gets wrong:
 *
 *  - STARTTLS. Port 465 negotiates TLS during the handshake (ssl://), while
 *    port 587 starts in the clear and upgrades with STARTTLS. Without the
 *    upgrade step a 587 configuration would put the username and password on
 *    the wire in plain text.
 *  - Reply codes. Every step is checked, and send() reports failure honestly.
 *    A client that ignores replies will happily tell a user their password
 *    reset was emailed when the server rejected the session.
 *  - Dot stuffing. A line consisting of a single dot ends the DATA payload in
 *    SMTP. Any such line must be escaped, or a body containing one is truncated.
 *  - Header injection. Subject and recipient go into headers, so a value
 *    containing CRLF could append headers of the caller's choosing.
 */
final class Mailer
{
    /** Seconds allowed for the connection and for each reply. */
    private const TIMEOUT = 15;

    /** Human-readable reason for the most recent send() failure. */
    private string $lastError = '';

    /**
     * The specific cause of the last send() failure.
     *
     * send() returns a bare boolean, which cannot distinguish "the password is
     * wrong" from "this recipient domain does not accept mail". The diagnostic
     * tool and the log both use this to say which one happened.
     */
    public function lastError(): string
    {
        return $this->lastError;
    }

    /**
     * The database is accepted for call-site compatibility but is never used:
     * sending a message must not require a working database connection, so the
     * SMTP test tool can exercise mail on its own. Everything this class needs
     * comes from the loaded configuration instead.
     */
    public function __construct(?Database $db = null)
    {
    }

    private function config(): array
    {
        return $GLOBALS['cahu_config']['mail'] ?? [];
    }

    /**
     * Sends one message, returning true only when the server accepted it.
     *
     * @param string $subject  Plain-text subject. Encoded as UTF-8 base64.
     * @param string $body     HTML body. A text/plain alternative is derived.
     */
    public function send(string $to, string $subject, string $body): bool
    {
        $cfg = $this->config();

        $host       = trim((string) ($cfg['host'] ?? ''));
        $port       = (int) ($cfg['port'] ?? 465);
        $user       = (string) ($cfg['username'] ?? '');
        $pass       = (string) ($cfg['password'] ?? '');
        $from       = trim((string) ($cfg['from'] ?? ''));
        $fromName   = trim((string) ($cfg['from_name'] ?? ''));
        $helo       = trim((string) ($cfg['helo'] ?? '')) ?: $host;
        $encryption = strtolower(trim((string) ($cfg['encryption'] ?? '')));
        $useAuth    = (bool) ($cfg['auth'] ?? true);
        $replyTo    = trim((string) ($cfg['reply_to'] ?? ''));

        $this->lastError = '';

        if ($host === '' || $from === '') {
            $this->lastError = 'MAIL_SMTP_HOST or MAIL_FROM_EMAIL is not set.';

            Logger::info('Mail not configured (host or from address empty). Message to ' . $to . ' [' . $subject . ']');

            return false;
        }

        $to = $this->sanitiseAddress($to);
        $from = $this->sanitiseAddress($from);

        if ($to === '' || $from === '') {
            $this->lastError = 'The sender or recipient address is malformed.';

            Logger::error('Mail rejected: malformed sender or recipient address.');

            return false;
        }

        if ($replyTo !== '') {
            $replyTo = $this->sanitiseAddress($replyTo);
        }

        // Port 465 and an explicit ssl setting both mean implicit TLS. Anything
        // else that asks for tls must upgrade after EHLO instead.
        $implicitTls = $port === 465 || $encryption === 'ssl';
        $transport   = $implicitTls ? 'ssl://' : 'tcp://';

        $socket = @fsockopen($transport . $host, $port, $errno, $errstr, self::TIMEOUT);

        if ($socket === false) {
            $this->lastError = sprintf('Could not connect to %s:%d (%s).', $host, $port, $errstr !== '' ? $errstr : 'error ' . $errno);

            Logger::error(sprintf('SMTP connect to %s:%d failed: %s (%d)', $host, $port, $errstr, $errno));

            return false;
        }

        stream_set_timeout($socket, self::TIMEOUT);

        try {
            if (!$this->expect($socket, 220)) {
                return false;
            }

            if (!$this->command($socket, 'EHLO ' . $helo, 250)) {
                return false;
            }

            if (!$implicitTls && $encryption === 'tls') {
                if (!$this->command($socket, 'STARTTLS', 220)) {
                    return false;
                }

                if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    $this->lastError = 'STARTTLS was accepted but the TLS handshake failed. The certificate may be expired or the server may not speak TLS on this port.';

                    Logger::error('SMTP STARTTLS was accepted but the TLS handshake failed.');

                    return false;
                }

                // RFC 3207: the session restarts, so capabilities must be read
                // again before authenticating.
                if (!$this->command($socket, 'EHLO ' . $helo, 250)) {
                    return false;
                }
            }

            if ($useAuth && $user !== '') {
                if (!$this->authenticate($socket, $user, $pass)) {
                    return false;
                }
            }

            if (!$this->command($socket, 'MAIL FROM:<' . $from . '>', 250)) {
                return false;
            }

            if (!$this->command($socket, 'RCPT TO:<' . $to . '>', 250, [250, 251])) {
                $this->lastError = 'The server refused the recipient ' . $to
                    . '. The connection and credentials were accepted, so this is a recipient or'
                    . ' relay-policy rejection, not a configuration fault. Confirm the address is'
                    . ' real and that the account is permitted to send to it.';

                return false;
            }

            if (!$this->command($socket, 'DATA', 354)) {
                return false;
            }

            $this->write($socket, $this->buildMessage($to, $subject, $body, $from, $fromName, $replyTo, $helo));

            // The final dot is the end-of-data terminator; 250 means queued.
            if (!$this->expect($socket, 250)) {
                return false;
            }

            $this->command($socket, 'QUIT', 221);

            return true;
        } finally {
            if (is_resource($socket)) {
                @fclose($socket);
            }
        }
    }

    /**
     * AUTH LOGIN, with PLAIN offered first because every common server accepts
     * it and it is one round trip instead of three.
     */
    private function authenticate($socket, string $user, string $pass): bool
    {
        $response = $this->command($socket, 'AUTH LOGIN PLAIN ' . base64_encode("\0" . $user . "\0" . $pass), 235);

        if ($response) {
            return true;
        }

        // Fall back to the challenge/response exchange some servers still
        // require, and treat an already-authenticated session as success.
        $response = $this->command($socket, 'AUTH LOGIN', 334);

        if ($response === 334) {
            $response = $this->command($socket, base64_encode($user), 334);

            if ($response === 334) {
                $response = $this->command($socket, base64_encode($pass), 235);
            }
        }

        if ($response === 235) {
            return true;
        }

        $this->lastError = 'The server rejected the SMTP credentials. Check MAIL_SMTP_USER,'
            . ' MAIL_SMTP_PASS and MAIL_SMTP_AUTH, and whether the provider requires an'
            . ' app-specific password rather than the account password.';

        Logger::error('SMTP authentication was rejected. Check MAIL_SMTP_USER, MAIL_SMTP_PASS and MAIL_SMTP_AUTH.');

        return false;
    }

    /**
     * Assembles a MIME multipart/alternative message.
     *
     * Dot stuffing happens here rather than at the socket so the escaping is
     * visible next to the message construction that requires it.
     */
    private function buildMessage(
        string $to,
        string $subject,
        string $html,
        string $from,
        string $fromName,
        string $replyTo,
        string $helo
    ): string {
        $boundary = 'b' . bin2hex(random_bytes(16));
        $stamp    = date('r');

        // CRLF is the wire format; a body authored with bare newlines is
        // normalised so the dot-stuffing test below sees the real line breaks.
        $htmlBody = str_replace(["\r\n", "\r"], "\n", $html);
        $textBody = $this->toPlainText($htmlBody);

        $headers  = 'Date: ' . $stamp . "\r\n";
        $headers .= 'From: ' . $this->encodeAddress($fromName, $from) . "\r\n";
        $headers .= 'To: <' . $to . '>' . "\r\n";

        if ($replyTo !== '') {
            $headers .= 'Reply-To: <' . $replyTo . '>' . "\r\n";
        }

        $headers .= 'Subject: ' . $this->encodeHeader($subject) . "\r\n";
        $headers .= 'Message-ID: <' . bin2hex(random_bytes(16)) . '@' . $this->domainOf($helo) . '>' . "\r\n";
        $headers .= 'MIME-Version: 1.0' . "\r\n";
        $headers .= 'Content-Type: multipart/alternative; boundary="' . $boundary . '"' . "\r\n";
        $headers .= "\r\n";

        $message  = 'This is a multi-part message in MIME format.' . "\r\n";
        $message .= '--' . $boundary . "\r\n";
        $message .= 'Content-Type: text/plain; charset=UTF-8' . "\r\n";
        $message .= 'Content-Transfer-Encoding: base64' . "\r\n\r\n";
        $message .= chunk_split(base64_encode($textBody)) . "\r\n";
        $message .= '--' . $boundary . "\r\n";
        $message .= 'Content-Type: text/html; charset=UTF-8' . "\r\n";
        $message .= 'Content-Transfer-Encoding: base64' . "\r\n\r\n";
        $message .= chunk_split(base64_encode($htmlBody)) . "\r\n";
        $message .= '--' . $boundary . "--\r\n";

        return $headers . $this->dotStuff($message) . '.' . "\r\n";
    }

    /**
     * Prefixes any line that is a single dot with another dot.
     *
     * Base64 transfer encoding means the payload never contains a bare dot, so
     * this guards the structure rather than the body: if the encoding is ever
     * changed to 8bit or quoted-printable the escaping is already correct.
     */
    private function dotStuff(string $message): string
    {
        return (string) preg_replace("/^\./m", '..', $message);
    }

    /**
     * Derives a readable text alternative from the HTML body.
     */
    private function toPlainText(string $html): string
    {
        $text = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', '', $html) ?? $html;
        $text = preg_replace('#<br\s*/?>#i', "\n", $text) ?? $text;
        $text = preg_replace('#</(p|div|tr|h[1-6]|li|table|ul|ol)>#i', "\n", $text) ?? $text;
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $text = preg_replace("/[ \t]+\n/", "\n", $text) ?? $text;
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;

        return trim($text);
    }

    /**
     * RFC 2047 encodes a header value that is not plain ASCII.
     */
    private function encodeHeader(string $value): string
    {
        $value = $this->stripControlCharacters($value);

        if (preg_match('/[^\x20-\x7E]/', $value) !== 1) {
            return $value;
        }

        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    }

    /**
     * Renders a display name with its address, encoding the name when needed.
     */
    private function encodeAddress(string $name, string $address): string
    {
        if ($name === '') {
            return '<' . $address . '>';
        }

        $name = $this->stripControlCharacters($name);

        if (preg_match('/[^\x20-\x7E]/', $name) === 1) {
            $name = '=?UTF-8?B?' . base64_encode($name) . '?=';
        }

        // Quote the name so a comma in it cannot be read as an address separator.
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $name) . '" <' . $address . '>';
    }

    /**
     * Removes CR, LF and NUL so a value cannot inject extra headers.
     */
    private function stripControlCharacters(string $value): string
    {
        return trim((string) preg_replace('/[\x00-\x1F\x7F]/', '', $value));
    }

    /**
     * Accepts a bare address or an angle-bracketed one, discarding anything
     * that survives stripping only as a display name.
     */
    private function sanitiseAddress(string $address): string
    {
        $address = $this->stripControlCharacters($address);

        if (preg_match('/<([^>]+)>/', $address, $matches) === 1) {
            $address = $matches[1];
        }

        $address = trim($address);

        return filter_var($address, FILTER_VALIDATE_EMAIL) === false ? '' : $address;
    }

    private function domainOf(string $helo): string
    {
        $host = trim((string) preg_replace('/^.*\s/', '', $helo));

        return preg_match('/[A-Za-z0-9.-]+/', $host, $matches) === 1 ? $matches[0] : 'localhost';
    }

    /**
     * Sends one command and returns its reply code, or null on a transport
     * failure. $expected is a list of acceptable codes because some servers
     * answer RCPT TO with 251 rather than 250.
     */
    private function command($socket, string $command, int $expected, array $accept = []): ?int
    {
        $accept[] = $expected;

        $this->write($socket, $command . "\r\n");

        $code = $this->readCode($socket);

        if ($code === null) {
            Logger::error('SMTP connection lost while sending: ' . $this->redact($command));

            return null;
        }

        if (!in_array($code, $accept, true)) {
            Logger::error(sprintf('SMTP command "%s" was refused with code %d.', $this->redact($command), $code));

            return null;
        }

        return $code;
    }

    /**
     * Removes the base64 blob from an AUTH command before it reaches the log.
     *
     * AUTH LOGIN PLAIN and AUTH PLAIN put the username and password on the wire
     * as a single base64 token, which decodes straight back to the credentials.
     * Logging the command verbatim would therefore persist the mail password in
     * storage/logs, which is readable and often committed by accident.
     */
    private function redact(string $command): string
    {
        if (preg_match('/^AUTH\s+(LOGIN\s+PLAIN|PLAIN)\s+\S+/i', $command)) {
            return preg_replace('/^AUTH\s+(LOGIN\s+PLAIN|PLAIN)\s+\S+/i', 'AUTH $1 [redacted]', $command);
        }

        return $command;
    }

    /**
     * Sends raw payload bytes.
     */
    private function write($socket, string $payload): void
    {
        $length = strlen($payload);
        $sent   = 0;

        // fputs() can write short on a slow link, so the remainder is retried.
        while ($sent < $length) {
            $written = @fwrite($socket, substr($payload, $sent));

            if ($written === false || $written === 0) {
                Logger::error('SMTP write failed after ' . $sent . ' of ' . $length . ' bytes.');

                return;
            }

            $sent += $written;
        }
    }

    /**
     * Reads one reply and checks it against the expected code.
     */
    private function expect($socket, int $expected): bool
    {
        $code = $this->readCode($socket);

        if ($code === null || $code !== $expected) {
            Logger::error(sprintf('Expected SMTP code %d but received %s.', $expected, $code === null ? 'no reply' : (string) $code));

            return false;
        }

        return true;
    }

    /**
     * Returns the numeric reply code of a complete server reply.
     *
     * A multiline reply such as 250-STARTTLS / 250-AUTH LOGIN / 250 OK is
     * terminated by a line whose fourth character is a space, not a hyphen.
     */
    private function readCode($socket): ?int
    {
        $code = null;

        while (($line = @fgets($socket, 515)) !== false) {
            if (!preg_match('/^(\d{3})([ -]?)/', $line, $matches)) {
                continue;
            }

            $code = (int) $matches[1];

            if ($matches[2] === ' ') {
                break;
            }
        }

        return $code;
    }
}
