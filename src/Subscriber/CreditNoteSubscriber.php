<?php declare(strict_types=1);

namespace WSystem\EInvoice\Subscriber;

use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\System\StateMachine\Event\StateMachineStateChangeEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use WSystem\EInvoice\Service\InvoiceService;

/**
 * Creates a credit note (type 381, referencing the original e-invoice) when the option "Create credit notes" is on and
 *  - an order is cancelled (whole order),
 *  - a payment is refunded without Shopware refund records (whole order),
 *  - a Shopware refund (order_transaction_capture_refund) is completed (the refunded positions, else the refund amount).
 * Needs a valid e-invoice on the order. Errors never break the order workflow.
 */
class CreditNoteSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly InvoiceService $invoices,
        private readonly EntityRepository $transactionRepository,
        private readonly ?EntityRepository $refundRepository = null
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        // priority above the mail flow so the credit note exists when the cancellation / refund mail is built
        return [
            'state_machine.order.state_changed' => ['onOrderState', 100],
            'state_machine.order_transaction.state_changed' => ['onTransactionState', 100],
            'state_machine.order_transaction_capture_refund.state_changed' => ['onRefundState', 100],
        ];
    }

    public function onOrderState(StateMachineStateChangeEvent $event): void
    {
        if ($event->getStateEventName() === 'state_enter.order.state.cancelled') {
            $this->run($event->getTransition()->getEntityId(), 'order-cancelled', null, $event->getContext());
        }
    }

    public function onTransactionState(StateMachineStateChangeEvent $event): void
    {
        if ($event->getStateEventName() !== 'state_enter.order_transaction.state.refunded') {
            return;
        }
        try {
            $id = $event->getTransition()->getEntityId();
            $tx = $this->transactionRepository->search(new Criteria([$id]), $event->getContext())->getEntities()->first();
            if (!$tx instanceof OrderTransactionEntity) {
                return;
            }
            // Shopware refund records exist: the credit note comes from them (onRefundState), not from the payment state
            if ($this->refundRepository !== null) {
                $c = new Criteria();
                $c->addFilter(new EqualsFilter('transactionCapture.transactionId', $id));
                $c->setLimit(1);
                if ($this->refundRepository->searchIds($c, $event->getContext())->getTotal() > 0) {
                    return;
                }
            }
            $this->run($tx->getOrderId(), 'tx-' . $id . '-refunded', null, $event->getContext());
        } catch (\Throwable $e) {
            $this->invoices->log('error', 'Credit note after refund failed: ' . $e->getMessage());
        }
    }

    public function onRefundState(StateMachineStateChangeEvent $event): void
    {
        if ($this->refundRepository === null || $event->getStateEventName() !== 'state_enter.order_transaction_capture_refund.state.completed') {
            return;
        }
        try {
            $c = new Criteria([$event->getTransition()->getEntityId()]);
            $c->addAssociations(['transactionCapture.transaction', 'positions.orderLineItem']);
            $refund = $this->refundRepository->search($c, $event->getContext())->getEntities()->first();
            $orderId = $refund?->getTransactionCapture()?->getTransaction()?->getOrderId();
            if ($refund === null || $orderId === null) {
                return;
            }
            $lines = [];
            foreach ($refund->getPositions() ?? [] as $pos) {
                $item = $pos->getOrderLineItem();
                $lines[] = ['label' => (string) ($item?->getLabel() ?? 'Refund'), 'qty' => (float) $pos->getQuantity(), 'price' => $pos->getAmount(), 'sku' => (string) (($item?->getPayload() ?? [])['productNumber'] ?? '')];
            }
            if ($lines === []) {
                $lines[] = ['label' => 'Refund', 'qty' => 1.0, 'price' => $refund->getAmount()];
            }
            $this->run($orderId, 'refund-' . $refund->getId(), $lines, $event->getContext());
        } catch (\Throwable $e) {
            $this->invoices->log('error', 'Credit note for refund failed: ' . $e->getMessage());
        }
    }

    /**
     * @param list<array{label:string,qty:float,price:\Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice,sku?:string}>|null $lines
     */
    private function run(string $orderId, string $key, ?array $lines, Context $context): void
    {
        try {
            $order = $this->invoices->loadOrder($orderId, $context);
            if ($order !== null && $this->invoices->shouldCreateCreditNote($order, $key, $lines === null)) {
                $this->invoices->createCreditNote($order, $context, $key, $lines);
            }
        } catch (\Throwable $e) {
            $this->invoices->log('error', 'Automatic credit note failed: ' . $e->getMessage());
        }
    }
}
