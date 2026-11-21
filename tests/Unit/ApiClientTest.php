<?php declare(strict_types=1);

namespace WSystem\EInvoice\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WSystem\EInvoice\Service\ApiClient;

final class ApiClientTest extends TestCase
{
    public function testSendsKeyAndParsesResult(): void
    {
        $t = new FakeTransport([['status' => 200, 'body' => '{"valid":true,"xml":"<Invoice/>"}']]);
        $r = (new ApiClient($t))->create(['format' => 'xrechnung'], ' abc ');
        self::assertTrue($r['ok']);
        self::assertSame('<Invoice/>', $r['data']['xml']);
        self::assertSame('https://api.wsystem.dev/einvoice/create', $t->calls[0]['url']);
        self::assertSame('abc', $t->calls[0]['headers']['X-API-Key']);
    }

    public function testNoKeyHeaderWithoutKey(): void
    {
        $t = new FakeTransport([['status' => 200, 'body' => '{}']]);
        (new ApiClient($t))->create([]);
        self::assertArrayNotHasKey('X-API-Key', $t->calls[0]['headers']);
    }

    public function testQuotaErrorIsExplained(): void
    {
        $r = (new ApiClient(new FakeTransport([['status' => 402, 'body' => '{}']])))->create([]);
        self::assertFalse($r['ok']);
        self::assertStringContainsString('free daily quota', $r['error']);
    }

    public function testApiErrorDetail(): void
    {
        $r = (new ApiClient(new FakeTransport([['status' => 400, 'body' => '{"detail":"seller.name missing"}']])))->create([]);
        self::assertSame('W System API error 400: seller.name missing', $r['error']);
    }

    public function testRetriesOnceOnNetworkError(): void
    {
        $t = new FakeTransport([new \RuntimeException('ssl eof'), ['status' => 200, 'body' => '{"valid":true}']]);
        $r = (new ApiClient($t))->create([]);
        self::assertTrue($r['ok']);
        self::assertCount(2, $t->calls);
    }

    public function testGivesUpAfterTwoNetworkErrors(): void
    {
        $r = (new ApiClient(new FakeTransport([new \RuntimeException('a'), new \RuntimeException('b')])))->create([]);
        self::assertFalse($r['ok']);
        self::assertSame('b', $r['error']);
    }

    public function testKeyTestVariants(): void
    {
        $ok = (new ApiClient(new FakeTransport([['status' => 200, 'body' => '{"plan":"free","quota":500,"remaining":269}']])))->testKey('k');
        self::assertTrue($ok['ok']);
        self::assertStringContainsString('269 of 500', $ok['message']);
        $bad = (new ApiClient(new FakeTransport([['status' => 401, 'body' => '{"error":"invalid_api_key"}']])))->testKey('k');
        self::assertFalse($bad['ok']);
        self::assertStringContainsString('not accepted', $bad['message']);
        self::assertTrue((new ApiClient(new FakeTransport([])))->testKey(' ')['ok']);
        $down = (new ApiClient(new FakeTransport([new \RuntimeException('ssl')])))->testKey('k');
        self::assertFalse($down['ok']);
    }
}
