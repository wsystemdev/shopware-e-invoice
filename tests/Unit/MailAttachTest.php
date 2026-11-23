<?php declare(strict_types=1);

namespace WSystem\EInvoice\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Content\MailTemplate\Service\Event\MailBeforeValidateEvent;
use Shopware\Core\Framework\Context;
use WSystem\EInvoice\Service\InvoiceService;
use WSystem\EInvoice\Subscriber\MailAttachSubscriber;
use WSystem\EInvoice\Service\Settings;

final class MailAttachTest extends TestCase
{
    private function order(array $cf): OrderEntity
    {
        $o = new OrderEntity();
        $o->setId('o1');
        $o->setUniqueIdentifier('o1');
        $o->setOrderNumber('10001');
        $o->setCustomFields($cf);

        return $o;
    }

    private function fire(OrderEntity $order, string $eventName, array $settings = []): array
    {
        $svc = $this->createMock(InvoiceService::class);
        $svc->method('settingsFor')->willReturn(new Settings($settings));
        $svc->method('loadOrder')->willReturn($order);
        $ev = new MailBeforeValidateEvent(['subject' => 's'], Context::createDefaultContext(), ['order' => $order, 'eventName' => $eventName]);
        (new MailAttachSubscriber($svc))->onMail($ev);

        return $ev->getData();
    }

    public function testAttachesValidXmlToConfirmation(): void
    {
        $o = $this->order([InvoiceService::F_XML => '<Invoice/>', InvoiceService::F_VALID => 'yes', InvoiceService::F_NUMBER => '10001']);
        $d = $this->fire($o, 'checkout.order.placed');
        self::assertSame('<Invoice/>', $d['binAttachments'][0]['content']);
        self::assertSame('e-invoice-10001.xml', $d['binAttachments'][0]['fileName']);
        self::assertSame('application/xml', $d['binAttachments'][0]['mimeType']);
    }

    public function testNothingAttachedWhenInvalidMissingOrOtherMail(): void
    {
        self::assertArrayNotHasKey('binAttachments', $this->fire($this->order([InvoiceService::F_XML => '<x/>', InvoiceService::F_VALID => 'no']), 'checkout.order.placed'));
        self::assertArrayNotHasKey('binAttachments', $this->fire($this->order([]), 'checkout.order.placed'));
        $ok = $this->order([InvoiceService::F_XML => '<x/>', InvoiceService::F_VALID => 'yes']);
        self::assertArrayNotHasKey('binAttachments', $this->fire($ok, 'state_enter.order_delivery.state.shipped'));
        self::assertArrayNotHasKey('binAttachments', $this->fire($ok, 'checkout.order.placed', ['attachEmail' => false]));
    }

    public function testPdfReplacesXmlWhenAZugferdPdfExists(): void
    {
        $o = $this->order([InvoiceService::F_XML => '<Invoice/>', InvoiceService::F_VALID => 'yes', InvoiceService::F_NUMBER => '10001', InvoiceService::F_PDF => base64_encode('%PDF-1.7 x')]);
        $d = $this->fire($o, 'checkout.order.placed');
        self::assertCount(1, $d['binAttachments']);
        self::assertSame('%PDF-1.7 x', $d['binAttachments'][0]['content']);
        self::assertSame('e-invoice-10001.pdf', $d['binAttachments'][0]['fileName']);
        self::assertSame('application/pdf', $d['binAttachments'][0]['mimeType']);
    }

    public function testCreditNoteAttachedToCancellationMailOnlyWhenEnabled(): void
    {
        $cn = [['key' => 'k', 'number' => 'CN-10001-1', 'valid' => true, 'xml' => '<CreditNote/>', 'pdf' => base64_encode('%PDF cn')]];
        $o = $this->order([InvoiceService::F_XML => '<Invoice/>', InvoiceService::F_VALID => 'yes', InvoiceService::F_CREDIT_NOTES => $cn]);
        // default: credit notes off -> nothing attached to a cancellation mail
        self::assertArrayNotHasKey('binAttachments', $this->fire($o, 'state_enter.order.state.cancelled'));
        $d = $this->fire($o, 'state_enter.order.state.cancelled', ['creditNotes' => true]);
        self::assertCount(1, $d['binAttachments']);
        self::assertSame('credit-note-CN-10001-1.pdf', $d['binAttachments'][0]['fileName']);
        self::assertSame('%PDF cn', $d['binAttachments'][0]['content']);
        $x = $this->order([InvoiceService::F_CREDIT_NOTES => [['key' => 'k', 'number' => 'CN-2', 'valid' => true, 'xml' => '<CreditNote/>']]]);
        $d = $this->fire($x, 'state_enter.order_transaction.state.refunded', ['creditNotes' => true]);
        self::assertSame('application/xml', $d['binAttachments'][0]['mimeType']);
        self::assertArrayNotHasKey('binAttachments', $this->fire($o, 'state_enter.order.state.cancelled', ['creditNotes' => true, 'attachCreditNote' => false]));
    }
}
