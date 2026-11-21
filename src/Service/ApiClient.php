<?php declare(strict_types=1);

namespace WSystem\EInvoice\Service;

/**
 * Client for POST https://api.wsystem.dev/einvoice/create (builds the XML and validates it).
 */
final class ApiClient
{
    public const VERSION = '1.0.0';
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
                $res = $this->transport->post(self::BASE . '/einvoice/create', $headers, (string) $payload, 45);
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
