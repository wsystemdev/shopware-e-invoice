<?php declare(strict_types=1);

namespace WSystem\EInvoice\Subscriber;

use Shopware\Core\Checkout\Cart\Event\CheckoutOrderPlacedEvent;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\System\StateMachine\Event\StateMachineStateChangeEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use WSystem\EInvoice\Service\InvoiceService;

/**
 * Creates the e-invoice automatically when the order is placed, paid or completed (setting "trigger").
 * Errors never break the checkout or the order workflow: they are stored on the order.
 */
class AutoCreateSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly InvoiceService $invoices,
        private readonly EntityRepository $transactionRepository
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            CheckoutOrderPlacedEvent::class => 'onPlaced',
            'state_machine.order.state_changed' => 'onOrderState',
            'state_machine.order_transaction.state_changed' => 'onTransactionState',
        ];
    }

    public function onPlaced(CheckoutOrderPlacedEvent $event): void
    {
        $this->run($event->getOrderId(), 'placed', $event->getContext());
    }

    public function onOrderState(StateMachineStateChangeEvent $event): void
    {
        if ($event->getStateEventName() === 'state_enter.order.state.completed') {
            $this->run($event->getTransition()->getEntityId(), 'completed', $event->getContext());
        }
    }

    public function onTransactionState(StateMachineStateChangeEvent $event): void
    {
        if ($event->getStateEventName() !== 'state_enter.order_transaction.state.paid') {
            return;
        }
        $tx = $this->transactionRepository->search(new \Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria([$event->getTransition()->getEntityId()]), $event->getContext())->getEntities()->first();
        if ($tx instanceof \Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity) {
            $this->run($tx->getOrderId(), 'paid', $event->getContext());
        }
    }

    private function run(string $orderId, string $trigger, \Shopware\Core\Framework\Context $context): void
    {
        try {
            $order = $this->invoices->loadOrder($orderId, $context);
            if ($order !== null && $this->invoices->shouldCreateAutomatically($order, $trigger)) {
                $this->invoices->generate($order, $context);
            }
        } catch (\Throwable $e) {
            $this->invoices->log('error', 'Automatic e-invoice creation failed: ' . $e->getMessage());
        }
    }
}
