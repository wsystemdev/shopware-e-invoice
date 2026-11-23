<?php declare(strict_types=1);

namespace WSystem\EInvoice\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Cart\Price\Struct\CartPrice;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTax;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderAddress\OrderAddressCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderAddress\OrderAddressEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderCustomer\OrderCustomerEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\System\Country\CountryEntity;
use Shopware\Core\System\Currency\CurrencyEntity;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use WSystem\EInvoice\Service\ApiClient;
use WSystem\EInvoice\Service\InvoiceService;

final class CreditNoteServiceTest extends TestCase
{
    /** @var list<array<string,mixed>> */
    private array $updates = [];

    private function order(array $cf): OrderEntity
    {
        $o = new OrderEntity();
        $o->setId('o1');
        $o->setUniqueIdentifier('o1');
        $o->setOrderNumber('10001');
        $o->setOrderDateTime(new \DateTimeImmutable('2026-11-20'));
        $o->setAmountTotal(119.0);
        $o->setPrice(new CartPrice(100.0, 119.0, 119.0, new CalculatedTaxCollection(), new TaxRuleCollection(), CartPrice::TAX_STATE_GROSS));
        $o->setBillingAddressId('a1');
        $o->setCustomFields($cf);
        $o->setSalesChannelId('sc1');
        $cur = new CurrencyEntity();
        $cur->setId('c');
        $cur->setIsoCode('EUR');
        $o->setCurrency($cur);
        $c = new CountryEntity();
        $c->setId('k');
        $c->setIso('DE');
        $a = new OrderAddressEntity();
        $a->setId('a1');
        $a->setUniqueIdentifier('a1');
        $a->setFirstName('Anna');
        $a->setLastName('Kunde');
        $a->setStreet('Teststr. 1');
        $a->setZipcode('10115');
        $a->setCity('Berlin');
        $a->setCountry($c);
        $o->setAddresses(new OrderAddressCollection([$a]));
        $oc = new OrderCustomerEntity();
        $oc->setId('oc');
        $oc->setEmail('anna@example.com');
        $o->setOrderCustomer($oc);
        $p = new CalculatedPrice(119.0, 119.0, new CalculatedTaxCollection([new CalculatedTax(19.0, 19.0, 119.0)]), new TaxRuleCollection());
        $i = new OrderLineItemEntity();
        $i->setId('l1');
        $i->setUniqueIdentifier('l1');
        $i->setLabel('Widget');
        $i->setQuantity(1);
        $i->setPrice($p);
        $i->setPayload(['productNumber' => 'W-1']);
        $o->setLineItems(new OrderLineItemCollection([$i]));
        $o->setShippingCosts(new CalculatedPrice(0, 0, new CalculatedTaxCollection(), new TaxRuleCollection()));

        return $o;
    }

    private function service(array $config, FakeTransport $t): InvoiceService
    {
        $repo = $this->createMock(EntityRepository::class);
        $repo->method('update')->willReturnCallback(function (array $rows) {
            $this->updates[] = $rows[0]['customFields'];

            return new \Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenContainerEvent(Context::createDefaultContext(), new \Shopware\Core\Framework\Event\NestedEventCollection(), []);
        });
        $cfg = $this->createMock(SystemConfigService::class);
        $cfg->method('get')->willReturnCallback(static function (string $key) use ($config) {
            return $config[substr($key, \strlen('WSystemEInvoice.config.'))] ?? null;
        });

        return new InvoiceService($repo, $cfg, new ApiClient($t));
    }

    private const SELLER = ['sellerName' => 'Shop', 'sellerStreet' => 'S 1', 'sellerPostcode' => '10115', 'sellerCity' => 'Berlin', 'sellerVatId' => 'DE123456789', 'sellerEmail' => 's@example.com', 'sellerPhone' => '1', 'sellerContact' => 'E M'];
    private const VALID_ORIG = [InvoiceService::F_XML => '<Invoice/>', InvoiceService::F_VALID => 'yes', InvoiceService::F_NUMBER => '10001', InvoiceService::F_CREATED => '2026-11-20T10:00:00+00:00'];

    public function testNothingHappensWhileTheOptionIsOff(): void
    {
        $t = new FakeTransport([]);
        $svc = $this->service(self::SELLER, $t);
        $o = $this->order(self::VALID_ORIG);
        self::assertFalse($svc->shouldCreateCreditNote($o, 'order-cancelled', true));
        $r = $svc->createCreditNote($o, Context::createDefaultContext(), 'order-cancelled');
        self::assertTrue($r['skipped']);
        self::assertSame([], $t->calls);
        self::assertSame([], $this->updates);
    }

    public function testNoCreditNoteWithoutValidOriginal(): void
    {
        $svc = $this->service(self::SELLER + ['creditNotes' => true], new FakeTransport([]));
        self::assertFalse($svc->shouldCreateCreditNote($this->order([]), 'k', true));
        self::assertFalse($svc->shouldCreateCreditNote($this->order([InvoiceService::F_XML => '<x/>', InvoiceService::F_VALID => 'no']), 'k', true));
        self::assertTrue($svc->shouldCreateCreditNote($this->order(self::VALID_ORIG), 'k', true));
    }

    public function testFullCreditNoteIsCreatedStoredAndNotRepeated(): void
    {
        $t = new FakeTransport([['status' => 200, 'body' => json_encode(['valid' => true, 'xml' => '<CreditNote/>', 'pdf_base64' => base64_encode('%PDF-1.7'), 'errors' => []])]]);
        $svc = $this->service(self::SELLER + ['creditNotes' => true, 'zugferd' => true], $t);
        $o = $this->order(self::VALID_ORIG);
        $r = $svc->createCreditNote($o, Context::createDefaultContext(), 'order-cancelled');
        self::assertTrue($r['valid']);
        $sent = json_decode($t->calls[0]['body'], true);
        self::assertStringEndsWith('/einvoice/create', $t->calls[0]['url']);
        self::assertSame('381', $sent['type_code']);
        self::assertSame('10001', $sent['preceding_invoice']);
        self::assertSame('2026-11-20', $sent['preceding_invoice_date']);
        self::assertSame('zugferd', $sent['format']);
        $list = $this->updates[0][InvoiceService::F_CREDIT_NOTES];
        self::assertCount(1, $list);
        self::assertSame('order-cancelled', $list[0]['key']);
        self::assertTrue($list[0]['full']);
        self::assertSame(base64_encode('%PDF-1.7'), $list[0]['pdf']);
        // replay (same key) and a second full credit note (e.g. payment refunded after cancellation) are skipped
        $o2 = $this->order(self::VALID_ORIG + [InvoiceService::F_CREDIT_NOTES => $list]);
        self::assertFalse($svc->shouldCreateCreditNote($o2, 'order-cancelled', true));
        self::assertFalse($svc->shouldCreateCreditNote($o2, 'tx-1-refunded', true));
        self::assertTrue($svc->shouldCreateCreditNote($o2, 'refund-9', false)); // a partial refund record is still its own credit note
    }

    public function testApiFailureIsStoredNotThrown(): void
    {
        $t = new FakeTransport([['status' => 503, 'body' => '{}'], ['status' => 503, 'body' => '{}']]);
        $svc = $this->service(self::SELLER + ['creditNotes' => true], $t);
        $r = $svc->createCreditNote($this->order(self::VALID_ORIG), Context::createDefaultContext(), 'k');
        self::assertFalse($r['valid']);
        self::assertNotSame('', $r['error']);
        self::assertFalse($this->updates[0][InvoiceService::F_CREDIT_NOTES][0]['valid']);
    }

    public function testGeneratePdfOnlyWhenZugferdIsOn(): void
    {
        $body = json_encode(['valid' => true, 'xml' => '<rsm:CrossIndustryInvoice/>', 'pdf_base64' => base64_encode('%PDF'), 'errors' => [], 'totals' => ['total_with_vat' => 119.0]]);
        $svc = $this->service(self::SELLER + ['zugferd' => true], new FakeTransport([['status' => 200, 'body' => $body]]));
        $svc->generate($this->order([]), Context::createDefaultContext());
        self::assertSame(base64_encode('%PDF'), $this->updates[0][InvoiceService::F_PDF]);
        self::assertSame('zugferd', $this->updates[0][InvoiceService::F_FORMAT]);

        $this->updates = [];
        $t = new FakeTransport([['status' => 200, 'body' => $body]]);
        $svc = $this->service(self::SELLER, $t);
        $svc->generate($this->order([]), Context::createDefaultContext());
        self::assertSame('xrechnung', json_decode($t->calls[0]['body'], true)['format']);
        self::assertNull($this->updates[0][InvoiceService::F_PDF]);
    }
}
