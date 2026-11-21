<?php declare(strict_types=1);

namespace WSystem\EInvoice\Service;

final class CurlTransport implements HttpTransport
{
    public function post(string $url, array $headers, string $body, int $timeoutSeconds): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('curl_init failed');
        }
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }
        curl_setopt_array($ch, [
            \CURLOPT_POST => true,
            \CURLOPT_POSTFIELDS => $body,
            \CURLOPT_RETURNTRANSFER => true,
            \CURLOPT_TIMEOUT => $timeoutSeconds,
            \CURLOPT_CONNECTTIMEOUT => min(10, $timeoutSeconds),
            \CURLOPT_HTTPHEADER => $lines,
            \CURLOPT_USERAGENT => 'wsystem-shopware-e-invoice/' . ApiClient::VERSION,
        ]);
        $out = curl_exec($ch);
        if ($out === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException($err !== '' ? $err : 'network error');
        }
        $status = (int) curl_getinfo($ch, \CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        return ['status' => $status, 'body' => (string) $out];
    }
}
