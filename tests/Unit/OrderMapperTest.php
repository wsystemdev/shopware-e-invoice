<?php declare(strict_types=1);

namespace WSystem\EInvoice\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Cart\Price\Struct\CartPrice;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTax;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;
use Shopware\Core\Checkout\Customer\Aggregate\CustomerAddress\CustomerAddressEntity;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderAddress\OrderAddressCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderAddress\OrderAddressEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderCustomer\OrderCustomerEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\System\Country\CountryEntity;
use Shopware\Core\System\Currency\CurrencyEntity;
use WSystem\EInvoice\Service\OrderMapper;
use WSystem\EInvoice\Service\Settings;

final class OrderMapperTest extends TestCase
{
    private function price(float $total, float $rate, float $tax, float $qty = 1.0): CalculatedPrice
    {
        $taxes = new CalculatedTaxCollection($tax != 0 ? [new CalculatedTax($tax, $rate, $total)] : []);

        return new CalculatedPrice($total / $qty, $total, $taxes, new TaxRuleCollection());
    }

    private function item(string $id, string $label, int $qty, CalculatedPrice $p, ?string $parent = null, string $sku = ''): OrderLineItemEntity
    {
        $i = new OrderLineItemEntity();
        $i->setId($id);
        $i->setUniqueIdentifier($id);
        $i->setLabel($label);
        $i->setQuantity($qty);
        $i->setPrice($p);
        if ($parent !== null) {
            $i->setParentId($parent);
        }
        $i->setPayload(['productNumber' => $sku]);

        return $i;
    }

    private function order(string $country = 'DE', string $company = '', array $vat = [], string $status = CartPrice::TAX_STATE_GROSS, array $cf = []): OrderEntity
    {
        $o = new OrderEntity();
        $o->setId('o1');
        $o->setUniqueIdentifier('o1');
        $o->setOrderNumber('10001');
        $o->setOrderDateTime(new \DateTimeImmutable('2026-11-20'));
        $o->setAmountTotal(109.87);
        $o->setPrice(new CartPrice(94.21, 109.87, 109.87, new CalculatedTaxCollection(), new TaxRuleCollection(), $status));
        $o->setBillingAddressId('a1');
        $o->setCustomFields($cf);
        $cur = new CurrencyEntity();
        $cur->setId('c');
        $cur->setIsoCode('EUR');
        $o->setCurrency($cur);
        $c = new CountryEntity();
        $c->setId('k');
        $c->setIso($country);
        $a = new OrderAddressEntity();
        $a->setId('a1');
        $a->setUniqueIdentifier('a1');
        $a->setFirstName('Anna');
        $a->setLastName('Kunde');
        $a->setCompany($company ?: null);
        $a->setStreet('Teststr. 1');
        $a->setZipcode('10115');
        $a->setCity('Berlin');
        $a->setCountry($c);
        $o->setAddresses(new OrderAddressCollection([$a]));
        $oc = new OrderCustomerEntity();
        $oc->setId('oc');
        $oc->setEmail('anna@example.com');
        $oc->setVatIds($vat);
        $o->setOrderCustomer($oc);
        $o->setShippingCosts($this->price(0, 0, 0));
        $o->setLineItems(new OrderLineItemCollection([]));

        return $o;
    }

    private function settings(array $over = []): Settings
    {
        return new Settings($over + ['sellerName' => 'Shop', 'sellerStreet' => 'S 1', 'sellerPostcode' => '10115', 'sellerCity' => 'Berlin', 'sellerCountry' => 'DE', 'sellerVatId' => 'de 123456789', 'sellerEmail' => 's@example.com', 'sellerPhone' => '1', 'sellerContact' => 'E M', 'sellerIban' => 'DE89 3704 0044 0532 0130 00']);
    }

    public function testTwoVatRatesAndRoundingReconcile(): void
    {
        $o = $this->order();
        $o->setLineItems(new OrderLineItemCollection([
            $this->item('l1', 'Widget', 3, $this->price(89.97, 19, 14.36, 3), null, 'W-1'),
            $this->item('l2', 'Book', 1, $this->price(19.90, 7, 1.30)),
        ]));
        $b = OrderMapper::invoice($o, $this->settings(), null, new \DateTimeImmutable('2026-11-21'));
        self::assertSame('xrechnung', $b['format']);
        self::assertSame('10001', $b['invoice_number']);
        self::assertSame('2026-11-21', $b['issue_date']);
        self::assertSame('DE123456789', $b['seller']['vat_id']);
        self::assertCount(2, $b['lines']);
        self::assertSame('S', $b['lines'][0]['vat_category']);
        self::assertSame(19.0, $b['lines'][0]['vat_rate']);
        self::assertSame('W-1', $b['lines'][0]['seller_item_id']);
        self::assertSame('58', $b['payment']['means_code']);
        self::assertSame('DE89370400440532013000', $b['payment']['iban']);
        // total the API will compute equals the order total 109.87 (cent shifted on the 7 % line)
        $by = [];
        foreach ($b['lines'] as $l) {
            $by[(string) $l['vat_rate']] = ($by[(string) $l['vat_rate']] ?? 0) + round($l['quantity'] * $l['unit_price'], 2);
        }
        $tot = 0;
        foreach ($by as $r => $n) {
            $n = round($n, 2);
            $tot += $n + round($n * (float) $r / 100, 2);
        }
        self::assertEqualsWithDelta(109.87, $tot, 0.001);
    }

    private function multi(float $total, array $parts): CalculatedPrice
    {
        $t = [];
        foreach ($parts as [$price, $rate, $tax]) {
            $t[] = new CalculatedTax($tax, $rate, $price);
        }

        return new CalculatedPrice($total, $total, new CalculatedTaxCollection($t), new TaxRuleCollection());
    }

    /** @param array<string,mixed> $b */
    private static function apiTotal(array $b): float
    {
        $by = [];
        foreach ($b['lines'] as $l) {
            $by[(string) $l['vat_rate']] = ($by[(string) $l['vat_rate']] ?? 0) + round($l['quantity'] * $l['unit_price'], 2);
        }
        $over = [];
        foreach ($b['vat_amounts'] ?? [] as $v) {
            $over[(string) $v['vat_rate']] = $v['tax_amount'];
        }
        $tot = 0;
        foreach ($by as $r => $n) {
            $n = round($n, 2);
            $tot += $n + ($over[$r] ?? round($n * (float) $r / 100, 2));
        }

        return round($tot, 2);
    }

    public function testShippingWithTwoTaxRatesIsSplitPerRate(): void
    {
        // BET3: Shopware tax type "auto" splits shipping 4.90 gross over 19 % and 7 %; it used to be put entirely at the larger share's rate
        $o = $this->order();
        $o->setAmountTotal(65.76);
        $o->setLineItems(new OrderLineItemCollection([
            $this->item('l1', 'Kugelschreiber', 3, $this->price(29.97, 19, 4.79, 3)),
            $this->item('l2', 'Fachbuch', 2, $this->price(25.90, 7, 1.69, 2)),
            $this->item('l3', 'Kaffee', 1, $this->price(4.99, 7, 0.33)),
        ]));
        $o->setShippingCosts($this->multi(4.90, [[2.42, 19, 0.39], [2.48, 7, 0.16]]));
        $b = OrderMapper::invoice($o, $this->settings());
        $ship = array_values(array_filter($b['lines'], static fn ($l) => str_starts_with($l['description'], 'Shipping')));
        self::assertCount(2, $ship);
        self::assertSame([19.0, 7.0], [$ship[0]['vat_rate'], $ship[1]['vat_rate']]);
        self::assertEqualsWithDelta(65.76, self::apiTotal($b), 0.001);
    }

    public function testPercentagePromotionIsSplitPerRate(): void
    {
        $o = $this->order();
        $o->setAmountTotal(54.77);
        $o->setLineItems(new OrderLineItemCollection([
            $this->item('l1', 'Kugelschreiber', 3, $this->price(29.97, 19, 4.79, 3)),
            $this->item('l2', 'Fachbuch', 2, $this->price(25.90, 7, 1.69, 2)),
            $this->item('l3', 'Kaffee', 1, $this->price(4.99, 7, 0.33)),
            $this->item('p', '10 % Rabatt', 1, $this->multi(-6.09, [[-3.00, 19, -0.48], [-3.09, 7, -0.20]])),
        ]));
        $b = OrderMapper::invoice($o, $this->settings());
        $promo = array_values(array_filter($b['lines'], static fn ($l) => str_starts_with($l['description'], '10 % Rabatt')));
        self::assertCount(2, $promo);
        self::assertEqualsWithDelta(54.77, self::apiTotal($b), 0.001);
    }

    public function testUnreachableGrossSendsShopVatAmount(): void
    {
        // 9.99 gross at 19 %: no net gives 9.99 with VAT = round(net x 19 %); the shop charged 1.60 VAT on 8.39 -> vat_amounts
        $o = $this->order();
        $o->setAmountTotal(9.99);
        $o->setLineItems(new OrderLineItemCollection([$this->item('l1', 'Kugelschreiber', 1, $this->price(9.99, 19, 1.60))]));
        $b = OrderMapper::invoice($o, $this->settings());
        self::assertSame([['vat_category' => 'S', 'vat_rate' => 19.0, 'tax_amount' => 1.6]], $b['vat_amounts']);
        self::assertEqualsWithDelta(9.99, self::apiTotal($b), 0.001);
    }

    public function testCentLinesSendShopVatWithinLineBound(): void
    {
        // PC-5: 40 x 0.01 gross at 19 % -> Shopware charges 0.00 VAT per line; base x rate would add 0.06-0.08. The shop VAT goes out as
        // vat_amounts (|diff| <= 0.005 x 40 lines), so the e-invoice shows exactly what the shop charged.
        $o = $this->order();
        $o->setAmountTotal(0.40);
        $items = [];
        for ($i = 0; $i < 40; ++$i) {
            $items[] = $this->item('c' . $i, 'Schraube ' . $i, 1, new CalculatedPrice(0.01, 0.01, new CalculatedTaxCollection([new CalculatedTax(0.0, 19, 0.01)]), new TaxRuleCollection()));
        }
        $o->setLineItems(new OrderLineItemCollection($items));
        $b = OrderMapper::invoice($o, $this->settings());
        self::assertSame([['vat_category' => 'S', 'vat_rate' => 19.0, 'tax_amount' => 0.0]], $b['vat_amounts']);
        self::assertEqualsWithDelta(0.40, self::apiTotal($b), 0.001);
    }

    public function testNoVatAmountsWhenReconcileReachesTheTotal(): void
    {
        $o = $this->order();
        $o->setAmountTotal(140.40);
        $o->setLineItems(new OrderLineItemCollection([
            $this->item('l1', 'Widget', 1, $this->price(119.00, 19, 19.00)),
            $this->item('l2', 'Book', 2, $this->price(21.40, 7, 1.40, 2)),
        ]));
        $b = OrderMapper::invoice($o, $this->settings());
        self::assertArrayNotHasKey('vat_amounts', $b);
        self::assertEqualsWithDelta(140.40, self::apiTotal($b), 0.001);
    }

    public function testChildrenAreSkippedAndZeroLinesDropped(): void
    {
        $o = $this->order();
        $o->setLineItems(new OrderLineItemCollection([
            $this->item('p', 'Bundle', 1, $this->price(119.0, 19, 19.0)),
            $this->item('c', 'Child', 1, $this->price(50.0, 19, 7.98), 'p'),
            $this->item('z', 'Free gift', 1, $this->price(0.0, 19, 0.0)),
        ]));
        $b = OrderMapper::invoice($o, $this->settings());
        self::assertCount(1, $b['lines']);
        self::assertSame('Bundle', $b['lines'][0]['description']);
        self::assertSame(100.0, $b['lines'][0]['unit_price']);
    }

    public function testDiscountBecomesNegativeLine(): void
    {
        $o = $this->order();
        $o->setLineItems(new OrderLineItemCollection([
            $this->item('p', 'Thing', 1, $this->price(119.0, 19, 19.0)),
            $this->item('d', 'SUMMER10', 1, $this->price(-11.9, 19, -1.9)),
        ]));
        $b = OrderMapper::invoice($o, $this->settings());
        self::assertCount(2, $b['lines']);
        self::assertSame(-1.0, $b['lines'][1]['quantity']);
        self::assertSame(10.0, $b['lines'][1]['unit_price']);
    }

    public function testIntraEuBusinessWithVatIdIsReverseCharge(): void
    {
        $o = $this->order('NL', 'Voorbeeld B.V.', ['nl 123456782 b01'], CartPrice::TAX_STATE_FREE);
        $o->setLineItems(new OrderLineItemCollection([$this->item('p', 'Thing', 2, $this->price(200.0, 0, 0, 2))]));
        $b = OrderMapper::invoice($o, $this->settings());
        self::assertSame('AE', $b['lines'][0]['vat_category']);
        self::assertSame('NL123456782B01', $b['buyer']['vat_id']);
        self::assertSame('Voorbeeld B.V.', $b['buyer']['name']);
        self::assertSame('Anna Kunde', $b['buyer']['contact_name']);
        self::assertTrue(OrderMapper::isB2b($o));
    }

    public function testExportOutsideEuIsG(): void
    {
        $o = $this->order('CH', '', [], CartPrice::TAX_STATE_FREE);
        $o->setLineItems(new OrderLineItemCollection([$this->item('p', 'Thing', 1, $this->price(100.0, 0, 0))]));
        $b = OrderMapper::invoice($o, $this->settings());
        self::assertSame('G', $b['lines'][0]['vat_category']);
        self::assertFalse(OrderMapper::isB2b($o));
    }

    public function testSmallBusinessUsesCategoryEAndReason(): void
    {
        $o = $this->order();
        $o->setLineItems(new OrderLineItemCollection([$this->item('p', 'Thing', 1, $this->price(100.0, 0, 0))]));
        $b = OrderMapper::invoice($o, $this->settings(['smallBusiness' => true]));
        self::assertSame('E', $b['lines'][0]['vat_category']);
        self::assertArrayHasKey('E', $b['vat_exemption_reason']);
    }

    public function testBuyerReferenceFromOrderThenCustomerThenOrderNumber(): void
    {
        $o = $this->order('DE', '', [], CartPrice::TAX_STATE_GROSS, ['wsystem_einvoice_buyer_reference' => '04011000-12345-34']);
        self::assertSame('04011000-12345-34', OrderMapper::buyerReference($o));
        $o2 = $this->order();
        self::assertSame('10001', OrderMapper::buyerReference($o2));
        $cust = new CustomerEntity();
        $cust->setId('cu');
        $cust->setCustomFields(['wsystem_einvoice_buyer_reference' => 'LEIT-1']);
        $o2->getOrderCustomer()->setCustomer($cust);
        self::assertSame('LEIT-1', OrderMapper::buyerReference($o2));
    }

    public function testFormatOverrideAndSettingsDefaults(): void
    {
        $o = $this->order();
        $o->setLineItems(new OrderLineItemCollection([$this->item('p', 'Thing', 1, $this->price(119.0, 19, 19.0))]));
        self::assertSame('peppol-bis3', OrderMapper::invoice($o, $this->settings(), 'peppol-bis3')['format']);
        self::assertSame('en16931', OrderMapper::invoice($o, $this->settings(['format' => 'en16931']))['format']);
        $s = new Settings([]);
        self::assertTrue($s->bool('attachEmail'));
        self::assertFalse($s->bool('onlyB2b'));
        self::assertTrue($s->bool('attachOrderConfirmation'));
        self::assertFalse($s->bool('attachShipped'));
        self::assertSame('completed', $s->str('trigger'));
    }

    public function testZugferdAndCreditNoteOptionsAreOffByDefault(): void
    {
        $s = new Settings([]);
        self::assertFalse($s->bool('zugferd'));
        self::assertFalse($s->bool('creditNotes'));
        self::assertTrue($s->bool('attachCreditNote'));
        self::assertSame('xrechnung', OrderMapper::formatFor($s));
        self::assertSame('en16931', OrderMapper::formatFor(new Settings(['format' => 'en16931'])));
    }

    public function testZugferdOptionSwitchesFormatButNotPeppol(): void
    {
        $o = $this->order();
        $o->setLineItems(new OrderLineItemCollection([$this->item('p', 'Thing', 1, $this->price(119.0, 19, 19.0))]));
        self::assertSame('zugferd', OrderMapper::invoice($o, $this->settings(['zugferd' => true]))['format']);
        self::assertSame('zugferd', OrderMapper::invoice($o, $this->settings(['zugferd' => true, 'format' => 'en16931']))['format']);
        self::assertSame('peppol-bis3', OrderMapper::invoice($o, $this->settings(['zugferd' => true, 'format' => 'peppol-bis3']))['format']);
        self::assertSame('xrechnung', OrderMapper::invoice($o, $this->settings(['zugferd' => false]))['format']);
    }

    public function testFullCreditNoteReferencesOriginalAndHasNoPrepaid(): void
    {
        $o = $this->order();
        $o->setLineItems(new OrderLineItemCollection([
            $this->item('l1', 'Widget', 3, $this->price(89.97, 19, 14.36, 3)),
            $this->item('l2', 'Book', 1, $this->price(19.90, 7, 1.30)),
        ]));
        $b = OrderMapper::creditNote($o, $this->settings(), 'order-cancelled', '10001', '2026-11-20', null, new \DateTimeImmutable('2026-11-22'));
        self::assertSame('381', $b['type_code']);
        self::assertSame('10001', $b['preceding_invoice']);
        self::assertSame('2026-11-20', $b['preceding_invoice_date']);
        self::assertSame('CN-10001-ancelled', $b['invoice_number']);
        self::assertSame('2026-11-22', $b['issue_date']);
        self::assertArrayNotHasKey('prepaid_amount', $b);
        self::assertCount(2, $b['lines']);
        self::assertSame('58', $b['payment']['means_code']);
        self::assertSame('xrechnung', $b['format']);
    }

    public function testPartialCreditNoteUsesOnlyRefundedLinesAtTheirRates(): void
    {
        $o = $this->order();
        $o->setLineItems(new OrderLineItemCollection([$this->item('l1', 'Widget', 3, $this->price(89.97, 19, 14.36, 3))]));
        $b = OrderMapper::creditNote($o, $this->settings(['zugferd' => true, 'sellerIban' => '']), 'refund-abc123', '10001', '2026-11-20', [
            ['label' => 'Widget', 'qty' => 1.0, 'price' => $this->price(29.99, 19, 4.79), 'sku' => 'W-1'],
        ], new \DateTimeImmutable('2026-11-22'));
        self::assertSame('zugferd', $b['format']);
        self::assertCount(1, $b['lines']);
        self::assertSame(25.2, $b['lines'][0]['unit_price']);
        self::assertSame(19.0, $b['lines'][0]['vat_rate']);
        self::assertSame('W-1', $b['lines'][0]['seller_item_id']);
        self::assertSame('1', $b['payment']['means_code']);
        self::assertArrayNotHasKey('iban', $b['payment']);
        self::assertStringStartsWith('CN-10001-', $b['invoice_number']);
    }

    public function testCreditNoteSmallBusinessKeepsCategoryE(): void
    {
        $o = $this->order();
        $b = OrderMapper::creditNote($o, $this->settings(['smallBusiness' => true]), 'k1', '10001', '2026-11-20', [
            ['label' => 'Thing', 'qty' => 1.0, 'price' => $this->price(50.0, 0, 0)],
        ]);
        self::assertSame('E', $b['lines'][0]['vat_category']);
        self::assertArrayHasKey('vat_exemption_reason', $b);
    }
}
