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
    private const CREDIT_NOTE_EVENTS = [
        'state_enter.order.state.cancelled',
        'state_enter.order_transaction.state.refunded',
        'state_enter.order_transaction.state.refunded_partially',
    ];

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
            $creditMail = \in_array($eventName, self::CREDIT_NOTE_EVENTS, true) && $settings->bool('creditNotes') && $settings->bool('attachCreditNote');
            $invoiceMail = $settings->bool('attachEmail') && $this->matches($settings, $eventName);
            if (!$creditMail && !$invoiceMail) {
                return;
            }
            $fresh = $this->invoices->loadOrder($order->getId(), $event->getContext()) ?? $order;
            $data = $event->getData();
            $att = $data['binAttachments'] ?? [];
            $status = InvoiceService::status($fresh);
            if ($invoiceMail && $status['exists'] && $status['valid']) {
                $pdf = InvoiceService::pdf($fresh);
                $att[] = $pdf !== ''
                    ? ['content' => base64_decode($pdf, true) ?: '', 'fileName' => InvoiceService::pdfFileName($fresh), 'mimeType' => 'application/pdf']
                    : ['content' => InvoiceService::xml($fresh), 'fileName' => InvoiceService::fileName($fresh), 'mimeType' => 'application/xml'];
            }
            if ($creditMail) {
                foreach (InvoiceService::creditNotes($fresh) as $cn) {
                    if (empty($cn['valid']) || ($cn['xml'] ?? '') === '') {
                        continue;
                    }
                    $att[] = !empty($cn['pdf'])
                        ? ['content' => base64_decode((string) $cn['pdf'], true) ?: '', 'fileName' => InvoiceService::creditNoteFileName($cn, 'pdf'), 'mimeType' => 'application/pdf']
                        : ['content' => (string) $cn['xml'], 'fileName' => InvoiceService::creditNoteFileName($cn, 'xml'), 'mimeType' => 'application/xml'];
                }
            }
            if ($att !== ($data['binAttachments'] ?? [])) {
                $event->addData('binAttachments', $att);
            }
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
