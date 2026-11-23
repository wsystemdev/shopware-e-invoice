<?php declare(strict_types=1);

namespace WSystem\EInvoice\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransactionCapture\OrderTransactionCaptureEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransactionCaptureRefund\OrderTransactionCaptureRefundEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransactionCaptureRefundPosition\OrderTransactionCaptureRefundPositionCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransactionCaptureRefundPosition\OrderTransactionCaptureRefundPositionEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Search\IdSearchResult;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;
use Shopware\Core\System\StateMachine\Aggregation\StateMachineState\StateMachineStateEntity;
use Shopware\Core\System\StateMachine\Event\StateMachineStateChangeEvent;
use Shopware\Core\System\StateMachine\StateMachineEntity;
use Shopware\Core\System\StateMachine\Transition;
use WSystem\EInvoice\Service\InvoiceService;
use WSystem\EInvoice\Subscriber\CreditNoteSubscriber;

final class CreditNoteSubscriberTest extends TestCase
{
    private function event(string $machine, string $entityId, string $state): StateMachineStateChangeEvent
    {
        $m = new StateMachineEntity();
        $m->setId('m');
        $m->setUniqueIdentifier('m');
        $m->setTechnicalName($machine);
        $from = new StateMachineStateEntity();
        $from->setId('a');
        $from->setUniqueIdentifier('a');
        $from->setTechnicalName('open');
        $to = new StateMachineStateEntity();
        $to->setId('b');
        $to->setUniqueIdentifier('b');
        $to->setTechnicalName($state);

        return new StateMachineStateChangeEvent(Context::createDefaultContext(), StateMachineStateChangeEvent::STATE_MACHINE_TRANSITION_SIDE_ENTER, new Transition(explode('.', $machine)[0], $entityId, 't', 'stateId'), $m, $from, $to);
    }

    private function order(): OrderEntity
    {
        $o = new OrderEntity();
        $o->setId('o1');
        $o->setUniqueIdentifier('o1');

        return $o;
    }

    public function testCancelledOrderCreatesFullCreditNote(): void
    {
        $svc = $this->createMock(InvoiceService::class);
        $svc->method('loadOrder')->willReturn($this->order());
        $svc->method('shouldCreateCreditNote')->willReturn(true);
        $svc->expects(self::once())->method('createCreditNote')->with(self::anything(), self::anything(), 'order-cancelled', null);
        $sub = new CreditNoteSubscriber($svc, $this->createMock(EntityRepository::class));
        $sub->onOrderState($this->event('order.state', 'o1', 'cancelled'));
        $sub->onOrderState($this->event('order.state', 'o1', 'completed')); // ignored
    }

    public function testNoCreditNoteWhenServiceSaysNo(): void
    {
        $svc = $this->createMock(InvoiceService::class);
        $svc->method('loadOrder')->willReturn($this->order());
        $svc->method('shouldCreateCreditNote')->willReturn(false);
        $svc->expects(self::never())->method('createCreditNote');
        (new CreditNoteSubscriber($svc, $this->createMock(EntityRepository::class)))->onOrderState($this->event('order.state', 'o1', 'cancelled'));
    }

    public function testRefundedPaymentWithoutRefundRecordsCreditsWholeOrderButWithRecordsDefersToRefund(): void
    {
        $tx = new OrderTransactionEntity();
        $tx->setId('t1');
        $tx->setUniqueIdentifier('t1');
        $tx->setOrderId('o1');
        $txRepo = $this->createMock(EntityRepository::class);
        $txRepo->method('search')->willReturn(new EntitySearchResult('order_transaction', 1, new EntityCollection([$tx]), null, new \Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria(), Context::createDefaultContext()));

        $svc = $this->createMock(InvoiceService::class);
        $svc->method('loadOrder')->willReturn($this->order());
        $svc->method('shouldCreateCreditNote')->willReturn(true);
        $svc->expects(self::once())->method('createCreditNote')->with(self::anything(), self::anything(), 'tx-t1-refunded', null);
        $none = $this->createMock(EntityRepository::class);
        $none->method('searchIds')->willReturn(new IdSearchResult(0, [], new \Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria(), Context::createDefaultContext()));
        (new CreditNoteSubscriber($svc, $txRepo, $none))->onTransactionState($this->event('order_transaction.state', 't1', 'refunded'));

        $svc2 = $this->createMock(InvoiceService::class);
        $svc2->expects(self::never())->method('createCreditNote');
        $some = $this->createMock(EntityRepository::class);
        $some->method('searchIds')->willReturn(new IdSearchResult(1, [['primaryKey' => 'r1', 'data' => []]], new \Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria(), Context::createDefaultContext()));
        (new CreditNoteSubscriber($svc2, $txRepo, $some))->onTransactionState($this->event('order_transaction.state', 't1', 'refunded'));
    }

    public function testCompletedRefundCreatesCreditNoteFromItsPositions(): void
    {
        $item = new OrderLineItemEntity();
        $item->setId('l1');
        $item->setUniqueIdentifier('l1');
        $item->setLabel('Widget');
        $item->setPayload(['productNumber' => 'W-1']);
        $price = new CalculatedPrice(10, 10, new CalculatedTaxCollection(), new TaxRuleCollection());
        $pos = new OrderTransactionCaptureRefundPositionEntity();
        $pos->setId('p1');
        $pos->setUniqueIdentifier('p1');
        $pos->setQuantity(2);
        $pos->setAmount($price);
        $pos->setOrderLineItem($item);
        $tx = new OrderTransactionEntity();
        $tx->setId('t1');
        $tx->setUniqueIdentifier('t1');
        $tx->setOrderId('o1');
        $cap = new OrderTransactionCaptureEntity();
        $cap->setId('c1');
        $cap->setUniqueIdentifier('c1');
        $cap->setTransaction($tx);
        $refund = new OrderTransactionCaptureRefundEntity();
        $refund->setId('abcdef0123456789');
        $refund->setUniqueIdentifier('abcdef0123456789');
        $refund->setTransactionCapture($cap);
        $refund->setPositions(new OrderTransactionCaptureRefundPositionCollection([$pos]));
        $refund->setAmount($price);
        $repo = $this->createMock(EntityRepository::class);
        $repo->method('search')->willReturn(new EntitySearchResult('order_transaction_capture_refund', 1, new EntityCollection([$refund]), null, new \Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria(), Context::createDefaultContext()));

        $svc = $this->createMock(InvoiceService::class);
        $svc->method('loadOrder')->willReturn($this->order());
        $svc->method('shouldCreateCreditNote')->willReturn(true);
        $svc->expects(self::once())->method('createCreditNote')->with(self::anything(), self::anything(), 'refund-abcdef0123456789', self::callback(static fn ($l) => \count($l) === 1 && $l[0]['qty'] === 2.0 && $l[0]['label'] === 'Widget' && $l[0]['sku'] === 'W-1'));
        (new CreditNoteSubscriber($svc, $this->createMock(EntityRepository::class), $repo))->onRefundState($this->event('order_transaction_capture_refund.state', 'abcdef0123456789', 'completed'));
    }
}
