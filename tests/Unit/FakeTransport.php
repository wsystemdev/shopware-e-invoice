<?php declare(strict_types=1);

namespace WSystem\EInvoice\Tests\Unit;

use WSystem\EInvoice\Service\HttpTransport;

final class FakeTransport implements HttpTransport
{
    /** @var list<array{url:string,headers:array<string,string>,body:string}> */
    public array $calls = [];

    /** @param list<array{status:int,body:string}|\RuntimeException> $queue */
    public function __construct(private array $queue)
    {
    }

    public function get(string $url, array $headers, int $timeoutSeconds): array
    {
        return $this->post($url, $headers, '', $timeoutSeconds);
    }

    public function post(string $url, array $headers, string $body, int $timeoutSeconds): array
    {
        $this->calls[] = ['url' => $url, 'headers' => $headers, 'body' => $body];
        $next = array_shift($this->queue);
        if ($next instanceof \RuntimeException) {
            throw $next;
        }

        return $next ?? ['status' => 500, 'body' => '{}'];
    }
}
