<?php
declare(strict_types=1);

namespace Services;

use Core\Database;
use Core\Logger;

/**
 * SMS gateway. Every attempt is written to sms_logs so the delivery history is
 * auditable even when no provider is configured.
 */
final class Sms
{
    public function __construct(private Database $db)
    {
    }

    private function config(): array
    {
        return $GLOBALS['cahu_config']['sms'] ?? [];
    }

    public function enabled(): bool
    {
        return (bool) ($this->config()['enabled'] ?? false);
    }

    /**
     * @param array{recipient_type?:string,recipient_id?:int,related_type?:string,related_id?:int} $context
     */
    public function send(string $phone, string $message, array $context = []): bool
    {
        $phone   = preg_replace('/[^0-9+]/', '', $phone) ?? '';
        $logId   = $this->db->insert('sms_logs', [
            'phone'          => $phone,
            'message'        => mb_substr($message, 0, 900),
            'provider'       => (string) ($this->config()['provider'] ?? 'generic'),
            'recipient_type' => $context['recipient_type'] ?? 'other',
            'recipient_id'   => $context['recipient_id'] ?? null,
            'related_type'   => $context['related_type'] ?? null,
            'related_id'     => $context['related_id'] ?? null,
            'status'         => 'queued',
        ]);

        $sms = $this->config();

        if (!$this->enabled() || ($sms['api_url'] ?? '') === '') {
            Logger::info('SMS not configured. Queued to ' . $phone . ': ' . $message);
            return false;
        }

        $payload = [
            'to'      => $phone,
            'from'    => $sms['sender_id'] ?? 'CAHU',
            'message' => $message,
        ];

        $response = $this->post((string) $sms['api_url'], $payload, (string) ($sms['api_key'] ?? ''));

        if ($response === null) {
            $this->db->update('sms_logs', [
                'status'        => 'failed',
                'error_message' => 'No response from SMS provider',
                'sent_at'       => date('Y-m-d H:i:s'),
            ], 'id = :id', ['id' => $logId]);

            return false;
        }

        $this->db->update('sms_logs', [
            'status'               => 'sent',
            'sent_at'              => date('Y-m-d H:i:s'),
            'provider_message_id'  => (string) ($response['id'] ?? ''),
        ], 'id = :id', ['id' => $logId]);

        return true;
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>|null
     */
    private function post(string $url, array $payload, string $apiKey): ?array
    {
        if (function_exists('curl_init')) {
            $handle = curl_init($url);
            curl_setopt_array($handle, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => json_encode($payload),
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Bearer ' . $apiKey],
                CURLOPT_TIMEOUT        => 20,
            ]);

            $body   = curl_exec($handle);
            $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
            curl_close($handle);

            if ($body === false || $status < 200 || $status >= 300) {
                return null;
            }

            $decoded = json_decode((string) $body, true);

            return is_array($decoded) ? $decoded : [];
        }

        $context = stream_context_create(['http' => [
            'method'        => 'POST',
            'header'        => "Content-Type: application/json\r\nAuthorization: Bearer {$apiKey}\r\n",
            'content'       => (string) json_encode($payload),
            'timeout'       => 20,
            'ignore_errors' => true,
        ]]);

        $body = @file_get_contents($url, false, $context);

        if ($body === false) {
            return null;
        }

        $decoded = json_decode($body, true);

        return is_array($decoded) ? $decoded : [];
    }
}
