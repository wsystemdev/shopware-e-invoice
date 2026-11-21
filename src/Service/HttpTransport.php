<?php declare(strict_types=1);

namespace WSystem\EInvoice\Service;

/**
 * Minimal HTTP POST abstraction so the API client can be tested offline.
 */
interface HttpTransport
{
    /**
     * @param array<string,string> $headers
     *
     * @return array{status:int,body:string}
     *
     * @throws \RuntimeException on network errors
     */
    public function post(string $url, array $headers, string $body, int $timeoutSeconds): array;
}
