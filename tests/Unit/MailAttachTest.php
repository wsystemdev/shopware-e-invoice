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
}
