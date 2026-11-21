<?php declare(strict_types=1);

namespace WSystem\EInvoice\Subscriber;

use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Content\MailTemplate\Service\Event\MailBeforeValidateEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use WSystem\EInvoice\Service\InvoiceService;
use WSystem\EInvoice\Service\Settings;

/**
 * Attaches the e-invoice XML to the configured order emails when it exists and passed validation.
 */
class MailAttachSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly InvoiceService $invoices)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [MailBeforeValidateEvent::class => 'onMail'];
    }

    public function onMail(MailBeforeValidateEvent $event): void
    {
        $tpl = $event->getTemplateData();
        $order = $tpl['order'] ?? null;
        if (!$order instanceof OrderEntity) {
            return;
        }
        $eventName = (string) ($tpl['eventName'] ?? '');
        try {
            $settings = $this->invoices->settingsFor($order);
            if (!$settings->bool('attachEmail') || !$this->matches($settings, $eventName)) {
                return;
            }
            $fresh = $this->invoices->loadOrder($order->getId(), $event->getContext()) ?? $order;
            $status = InvoiceService::status($fresh);
            if (!$status['exists'] || !$status['valid']) {
                return;
            }
            $data = $event->getData();
            $att = $data['binAttachments'] ?? [];
            $att[] = ['content' => InvoiceService::xml($fresh), 'fileName' => InvoiceService::fileName($fresh), 'mimeType' => 'application/xml'];
            $event->addData('binAttachments', $att);
        } catch (\Throwable $e) {
            // never block the email
        }
    }

    private function matches(Settings $settings, string $eventName): bool
    {
        $map = [
            'checkout.order.placed' => 'attachOrderConfirmation',
            'state_enter.order.state.completed' => 'attachCompleted',
            'state_enter.order_transaction.state.paid' => 'attachPaid',
            'state_enter.order_delivery.state.shipped' => 'attachShipped',
        ];
        $key = $map[$eventName] ?? null;

        return $key !== null && $settings->bool($key);
    }
}
