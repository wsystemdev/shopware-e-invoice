<?php declare(strict_types=1);

namespace WSystem\EInvoice\Service;

/**
 * Client for POST https://api.wsystem.dev/einvoice/create (builds the XML and validates it).
 */
final class ApiClient
{
    public const VERSION = '1.0.1';
    public const BASE = 'https://api.wsystem.dev';

    public function __construct(private readonly HttpTransport $transport)
    {
    }

    /**
     * @param array<string,mixed> $invoice
     *
     * @return array{ok:bool,status:int,data:array<string,mixed>,error:string}
     */
    public function create(array $invoice, string $apiKey = ''): array
    {
        $headers = ['Content-Type' => 'application/json', 'Accept' => 'application/json'];
        if (trim($apiKey) !== '') {
            $headers['X-API-Key'] = trim($apiKey);
        }
        $payload = json_encode($invoice, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PRESERVE_ZERO_FRACTION);
        $res = null;
        $lastError = '';
        // Transient TLS/network errors: one retry.
        for ($try = 0; $try < 2; ++$try) {
            try {
                $res = $this->transport->post(self::BASE . '/einvoice/create', $headers, (string) $payload, 25);
                break;
            } catch (\RuntimeException $e) {
                $lastError = $e->getMessage();
            }
        }
        if ($res === null) {
            return ['ok' => false, 'status' => 0, 'data' => [], 'error' => $lastError];
        }
        $data = json_decode($res['body'], true);
        $data = \is_array($data) ? $data : [];
        $error = '';
        if ($res['status'] < 200 || $res['status'] >= 300) {
            $error = self::errorText($res['status'], $data);
        }

        return ['ok' => $error === '', 'status' => $res['status'], 'data' => $data, 'error' => $error];
    }

    /**
     * Checks an API key against GET /billing/usage.
     *
     * @return array{ok:bool,message:string}
     */
    public function testKey(string $apiKey): array
    {
        $apiKey = trim($apiKey);
        if ($apiKey === '') {
            return ['ok' => true, 'message' => 'No API key set: the free tier applies (20 API calls per day per server IP address).'];
        }
        try {
            $res = $this->transport->get(self::BASE . '/billing/usage', ['Accept' => 'application/json', 'X-API-Key' => $apiKey], 15);
        } catch (\RuntimeException $e) {
            return ['ok' => false, 'message' => 'The W System API could not be reached: ' . $e->getMessage()];
        }
        $data = json_decode($res['body'], true);
        $data = \is_array($data) ? $data : [];
        if ($res['status'] === 200 && isset($data['plan'])) {
            return ['ok' => true, 'message' => sprintf('API key accepted. Plan: %s, %s of %s units left this month.', (string) $data['plan'], (string) ($data['remaining'] ?? '?'), (string) ($data['quota'] ?? '?'))];
        }
        if ($res['status'] === 401) {
            return ['ok' => false, 'message' => 'The API key was not accepted. Check it or get a free key at https://api.wsystem.dev/account/free-key?ref=shopware-einvoice'];
        }

        return ['ok' => false, 'message' => self::errorText($res['status'], $data)];
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function errorText(int $status, array $data): string
    {
        if ($status === 402 || $status === 429) {
            return 'The free daily quota of the W System API is used up. Add an API key in the plugin settings or try again tomorrow.';
        }
        $detail = $data['detail'] ?? ($data['error'] ?? '');
        if (\is_array($detail)) {
            $detail = $detail['message'] ?? json_encode($detail);
        }

        return sprintf('W System API error %d: %s', $status, (string) $detail);
    }
}
