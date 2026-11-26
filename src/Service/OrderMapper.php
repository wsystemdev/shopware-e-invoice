<?php declare(strict_types=1);

namespace WSystem\EInvoice\Service;

use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Cart\Price\Struct\CartPrice;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTax;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderAddress\OrderAddressEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use WSystem\EInvoice\WSystemEInvoice;

/**
 * Maps a loaded Shopware order to the JSON body of POST /einvoice/create.
 * Needs the associations: lineItems, currency, orderCustomer(.customer), addresses(.country),
 * transactions(.stateMachineState, .paymentMethod), documents(.documentType).
 */
final class OrderMapper
{
    public const EU = ['AT', 'BE', 'BG', 'CY', 'CZ', 'DE', 'DK', 'EE', 'ES', 'FI', 'FR', 'GR', 'HR', 'HU', 'IE', 'IT', 'LT', 'LU', 'LV', 'MT', 'NL', 'PL', 'PT', 'RO', 'SE', 'SI', 'SK'];

    public static function buyerVat(OrderEntity $order): string
    {
        $oc = $order->getOrderCustomer();
        $ids = $oc !== null ? $oc->getVatIds() : null;
        foreach ((array) $ids as $id) {
            if (trim((string) $id) !== '') {
                return strtoupper((string) preg_replace('/[\s.\-]/', '', (string) $id));
            }
        }

        return '';
    }

    public static function billingAddress(OrderEntity $order): ?OrderAddressEntity
    {
        $id = $order->getBillingAddressId();
        foreach ($order->getAddresses() ?? [] as $address) {
            if ($address->getId() === $id) {
                return $address;
            }
        }

        return null;
    }

    public static function isB2b(OrderEntity $order): bool
    {
        $a = self::billingAddress($order);

        return ($a !== null && trim((string) $a->getCompany()) !== '') || self::buyerVat($order) !== '';
    }

    /** Number of Shopware's own invoice document if one exists, else the order number. */
    public static function invoiceNumber(OrderEntity $order): string
    {
        foreach ($order->getDocuments() ?? [] as $doc) {
            // 6.5 to 6.8 expose the type through the documentType association; newer versions through getTypeName().
            $typeName = method_exists($doc, 'getTypeName') // @phpstan-ignore function.alreadyNarrowedType
                ? $doc->getTypeName()
                : $doc->getDocumentType()?->getTechnicalName(); // @phpstan-ignore method.deprecated, method.deprecatedClass
            if ($typeName === 'invoice') {
                $cfg = $doc->getConfig();
                if (!empty($cfg['documentNumber'])) {
                    return (string) $cfg['documentNumber'];
                }
            }
        }

        return (string) $order->getOrderNumber();
    }

    public static function buyerReference(OrderEntity $order): string
    {
        $cf = $order->getCustomFields() ?? [];
        $v = trim((string) ($cf[WSystemEInvoice::BUYER_REFERENCE] ?? ''));
        if ($v === '') {
            $customer = $order->getOrderCustomer()?->getCustomer();
            $ccf = $customer?->getCustomFields() ?? [];
            $v = trim((string) ($ccf[WSystemEInvoice::BUYER_REFERENCE] ?? ''));
        }

        return $v !== '' ? $v : (string) $order->getOrderNumber();
    }

    public static function isPaid(OrderEntity $order): bool
    {
        foreach ($order->getTransactions() ?? [] as $t) {
            $s = $t->getStateMachineState();
            if ($s !== null && $s->getTechnicalName() === 'paid') {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{0:string,1:float} VAT category and rate of one price
     */
    private static function vatOf(CalculatedPrice $price, string $taxStatus, string $zero): array
    {
        $taxes = $price->getCalculatedTaxes();
        $tax = 0.0;
        $rate = 0.0;
        $big = -1.0;
        foreach ($taxes as $t) {
            $tax += $t->getTax();
            if (abs($t->getPrice()) > $big) {
                $big = abs($t->getPrice());
                $rate = $t->getTaxRate();
            }
        }
        if ($taxStatus === CartPrice::TAX_STATE_FREE || ($rate <= 0.0 && abs($tax) < 0.005)) {
            return [$zero, 0.0];
        }

        return ['S', round($rate, 2)];
    }

    /**
     * Raw lines for one Shopware price. A price carrying several calculated taxes (shipping with tax type "auto", percentage
     * promotions, mixed-rate bundles) is split into one raw line per tax rate, so every amount lands at its own VAT rate.
     *
     * @return list<array<string,mixed>> empty for zero-value prices (raw lines, finished by finish())
     */
    private static function lines(string $desc, float $qty, CalculatedPrice $price, string $taxStatus, string $zero, bool $small, string $sku = ''): array
    {
        $taxes = $price->getCalculatedTaxes();
        if (\count($taxes) <= 1 || $small || $taxStatus === CartPrice::TAX_STATE_FREE) {
            $l = self::line($desc, $qty, $price, $taxStatus, $zero, $small, $sku);

            return $l !== null ? [$l] : [];
        }
        $gross = $taxStatus === CartPrice::TAX_STATE_GROSS;
        $out = [];
        foreach ($taxes as $t) {
            $part = $gross ? $t->getPrice() : $t->getPrice() + $t->getTax();
            $sub = new CalculatedPrice($t->getPrice(), $t->getPrice(), new CalculatedTaxCollection([new CalculatedTax($t->getTax(), $t->getTaxRate(), $t->getPrice())]), new TaxRuleCollection());
            $l = self::line($desc . ' (' . rtrim(rtrim(number_format($t->getTaxRate(), 2, '.', ''), '0'), '.') . ' %)', 1, $sub, $taxStatus, $zero, $small, $sku);
            if ($l !== null && abs($part) > 0.0) {
                $out[] = $l;
            }
        }

        return $out;
    }

    /**
     * @return array<string,mixed>|null null for zero-value lines (raw line, finished by finish())
     */
    private static function line(string $desc, float $qty, CalculatedPrice $price, string $taxStatus, string $zero, bool $small, string $sku = ''): ?array
    {
        $total = $price->getTotalPrice();
        $tax = $price->getCalculatedTaxes()->getAmount();
        $gross = $taxStatus === CartPrice::TAX_STATE_GROSS;
        $net = round($gross ? $total - $tax : $total, 2);
        if ($net == 0.0) {
            return null;
        }
        [$cat, $rate] = self::vatOf($price, $taxStatus, $zero);
        if ($small) {
            [$cat, $rate] = ['E', 0.0];
        }

        return ['desc' => $desc, 'qty' => $qty, 'net' => $net, 'gross' => $gross ? round($total, 2) : null, 'tax' => round($tax, 2), 'cat' => $cat, 'rate' => $rate, 'sku' => $sku];
    }

    /**
     * Shopware rounds VAT on the summed gross of each tax rate, the e-invoice computes it on the summed net.
     * Shift the net of the last line of a rate by a cent where needed so the e-invoice total equals the order total.
     *
     * @param list<array<string,mixed>> $raw
     *
     * @return list<array<string,mixed>>
     */
    private static function rateGroups(array $raw): array
    {
        $groups = [];
        foreach ($raw as $i => $l) {
            if ($l['gross'] !== null && $l['cat'] === 'S') {
                $groups[(string) $l['rate']][] = $i;
            }
        }

        return $groups;
    }

    /** Per-rate: if a net within 3 cents reaches the shop gross at base x rate, shift that rate's last line. */
    private static function firstPass(array $raw): array
    {
        foreach (self::rateGroups($raw) as $rate => $idx) {
            $grossSum = 0.0;
            $netSum = 0.0;
            foreach ($idx as $i) {
                $grossSum += $raw[$i]['gross'];
                $netSum += $raw[$i]['net'];
            }
            $grossSum = round($grossSum, 2);
            $netSum = round($netSum, 2);
            $r = (float) $rate;
            if (abs(round($netSum + round($netSum * $r / 100, 2), 2) - $grossSum) < 0.001) {
                continue;
            }
            $best = null;
            for ($d = -3; $d <= 3; ++$d) {
                $n = round($netSum + $d / 100, 2);
                if (abs(round($n + round($n * $r / 100, 2), 2) - $grossSum) < 0.001 && ($best === null || abs($d) < abs($best))) {
                    $best = $d;
                }
            }
            if ($best !== null) {
                $last = end($idx);
                $raw[$last]['net'] = round($raw[$last]['net'] + $best / 100, 2);
            }
        }

        return $raw;
    }

    /** Cross-rate 1-2 cent shift so today's API (base x rate, no vat_amounts) still matches the order total. */
    private static function secondPass(array $raw, float $target): array
    {
        if (abs(self::total($raw) - round($target, 2)) <= 0.001) {
            return $raw;
        }
        foreach ([1, -1, 2, -2] as $d) {
            foreach (self::rateGroups($raw) as $idx) {
                $last = end($idx);
                $try = $raw;
                $try[$last]['net'] = round($try[$last]['net'] + $d / 100, 2);
                if (abs(self::total($try) - round($target, 2)) < 0.001) {
                    return $try;
                }
            }
        }

        return $raw;
    }

    /**
     * Total the API will compute when it honours vat_amounts (shop VAT per rate wherever sent).
     *
     * @param list<array<string,mixed>> $raw
     * @param list<array{vat_category:string,vat_rate:float,tax_amount:float}> $va
     */
    private static function totalWithVat(array $raw, array $va): float
    {
        $ov = [];
        foreach ($va as $v) {
            $ov[(string) $v['vat_rate']] = $v['tax_amount'];
        }
        $by = [];
        foreach ($raw as $l) {
            $k = $l['cat'] . '|' . $l['rate'];
            $by[$k] = ($by[$k] ?? 0.0) + $l['net'];
        }
        $t = 0.0;
        foreach ($by as $k => $net) {
            $net = round($net, 2);
            [$cat, $rateS] = explode('|', $k, 2);
            $rate = (float) $rateS;
            if ($cat === 'S' && array_key_exists((string) $rate, $ov)) {
                $tax = $ov[(string) $rate];
            } elseif ($cat === 'S') {
                $tax = round($net * $rate / 100, 2);
            } else {
                $tax = 0.0;
            }
            $t += $net + $tax;
        }

        return round($t, 2);
    }

    /**
     * First pass + optional second pass, then pick nets and vat_amounts so an API that honours vat_amounts
     * matches the order total (PC-10: vat_amounts must not be derived from a second-pass-shifted net in a
     * way that double-corrects). Prefer a pair that also matches without vat_amounts (PC-4).
     *
     * @param list<array<string,mixed>> $raw
     *
     * @return array{0:list<array<string,mixed>>,1:list<array{vat_category:string,vat_rate:float,tax_amount:float}>}
     */
    private static function reconcileChoose(array $raw, float $target): array
    {
        $target = round($target, 2);
        $first = self::firstPass($raw);
        $second = self::secondPass($first, $target);
        $candidates = [
            [$second, self::vatAmounts($second)],
            [$second, self::vatAmounts($first)],
            [$first, self::vatAmounts($first)],
            [$second, []],
            [$first, []],
        ];
        $best = [$second, self::vatAmounts($second)];
        $bestScore = -1;
        foreach ($candidates as [$cand, $va]) {
            if (abs(self::totalWithVat($cand, $va) - $target) >= 0.001) {
                continue;
            }
            $oldOk = abs(self::total($cand) - $target) < 0.001;
            $score = 4 + ($oldOk ? 2 : 0) + ($va !== [] ? 1 : 0);
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = [$cand, $va];
            }
        }

        return $best;
    }

    /**
     * Total the API will compute for these raw lines (VAT per category and rate on the summed net).
     *
     * @param list<array<string,mixed>> $raw
     */
    private static function total(array $raw): float
    {
        $by = [];
        foreach ($raw as $l) {
            $k = $l['cat'] . '|' . $l['rate'];
            $by[$k] = ($by[$k] ?? 0.0) + $l['net'];
        }
        $t = 0.0;
        foreach ($by as $k => $net) {
            $net = round($net, 2);
            $rate = (float) explode('|', $k)[1];
            $t += $net + round($net * $rate / 100, 2);
        }

        return round($t, 2);
    }

    /**
     * VAT the shop actually charged per rate (gross prices: rate gross minus rate net; net prices: the summed line taxes), sent as
     * vat_amounts so the e-invoice total equals the order total even where no net amount can reach it (e.g. 9.99 gross at 19 %).
     * Only groups whose amount differs from base x rate, and only within the API's per-line rounding bound (0.005 per line, at
     * least 0.01); otherwise nothing is sent and the API computes VAT as before. API versions without vat_amounts support ignore the field.
     *
     * @param list<array<string,mixed>> $raw
     *
     * @return list<array{vat_category:string,vat_rate:float,tax_amount:float}>
     */
    public static function vatAmounts(array $raw): array
    {
        $g = [];
        foreach ($raw as $l) {
            if ($l['cat'] !== 'S') {
                continue;
            }
            $k = (string) $l['rate'];
            $g[$k] ??= ['net' => 0.0, 'gross' => 0.0, 'tax' => 0.0, 'grossMode' => $l['gross'] !== null];
            $g[$k]['net'] += $l['net'];
            $g[$k]['gross'] += $l['gross'] ?? 0.0;
            $g[$k]['tax'] += $l['tax'] ?? 0.0;
        }
        $tol = min(0.99, max(0.01, round(0.005 * \count($raw), 2)));
        $out = [];
        foreach ($g as $rate => $v) {
            $net = round($v['net'], 2);
            $charged = round($v['grossMode'] ? round($v['gross'], 2) - $net : $v['tax'], 2);
            $calc = round($net * (float) $rate / 100, 2);
            if (abs($charged - $calc) > 0.001 && abs($charged - $calc) <= $tol + 0.0001) {
                $out[] = ['vat_category' => 'S', 'vat_rate' => (float) $rate, 'tax_amount' => $charged];
            }
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $l raw line
     *
     * @return array<string,mixed>
     */
    private static function finish(array $l): array
    {
        $desc = (string) $l['desc'];
        $qty = (float) $l['qty'];
        $net = (float) $l['net'];
        if ($qty <= 0) {
            $qty = 1;
        }
        if ($net < 0) {
            $qty = -$qty;
        }
        $unit = round($net / $qty, 6);
        if (abs(round($unit * $qty, 2) - $net) > 0.001) {
            $desc = rtrim(rtrim(number_format(abs($qty), 4, '.', ''), '0'), '.') . ' x ' . $desc;
            $qty = $net < 0 ? -1 : 1;
            $unit = abs($net);
        }
        $line = [
            'description' => trim(strip_tags(html_entity_decode($desc, \ENT_QUOTES, 'UTF-8'))) ?: '-',
            'quantity' => $qty,
            'unit' => 'C62',
            'unit_price' => $unit,
            'vat_rate' => $l['rate'],
            'vat_category' => $l['cat'],
        ];
        if ($l['sku'] !== '') {
            $line['seller_item_id'] = $l['sku'];
        }

        return $line;
    }

    /** VAT category used for zero-rated lines of this order (E small business, G export, AE EU reverse charge, else Z). */
    public static function zeroCategory(OrderEntity $order, Settings $s): string
    {
        $sellerCountry = strtoupper($s->str('sellerCountry') ?: 'DE');
        $buyerCountry = strtoupper((string) self::billingAddress($order)?->getCountry()?->getIso());
        $buyerVat = self::buyerVat($order);
        if ($s->bool('smallBusiness')) {
            return 'E';
        }
        if ($buyerCountry !== '' && $buyerCountry !== $sellerCountry && !\in_array($buyerCountry, self::EU, true)) {
            return 'G';
        }
        if ($buyerVat !== '' && $buyerCountry !== $sellerCountry && \in_array($buyerCountry, self::EU, true)) {
            return 'AE';
        }

        return 'Z';
    }

    /** API format for new documents: the configured one, or zugferd when the option is on (not for Peppol BIS). */
    public static function formatFor(Settings $s): string
    {
        $f = $s->str('format') ?: 'xrechnung';

        return $s->bool('zugferd') && $f !== 'peppol-bis3' ? 'zugferd' : $f;
    }

    /**
     * Credit note (type 381) for an order: all of it (when $refundLines is null) or only the given refunded lines.
     * Amounts stay positive. References the original invoice (BT-25 / BT-26).
     *
     * @param list<array{label:string,qty:float,price:CalculatedPrice,sku?:string}>|null $refundLines
     *
     * @return array<string,mixed>
     */
    public static function creditNote(OrderEntity $order, Settings $s, string $key, string $precedingNumber, string $precedingDate, ?array $refundLines = null, ?\DateTimeInterface $today = null): array
    {
        $today ??= new \DateTimeImmutable();
        $body = self::invoice($order, $s, null, $today);
        if ($refundLines !== null) {
            $taxStatus = $order->getPrice()->getTaxStatus();
            $zero = self::zeroCategory($order, $s);
            $raw = [];
            $target = 0.0;
            foreach ($refundLines as $r) {
                array_push($raw, ...self::lines($r['label'], $r['qty'], $r['price'], $taxStatus, $zero, $s->bool('smallBusiness'), $r['sku'] ?? ''));
                $target += $r['price']->getTotalPrice() + ($taxStatus === CartPrice::TAX_STATE_GROSS ? 0.0 : $r['price']->getCalculatedTaxes()->getAmount());
            }
            [$raw, $va] = self::reconcileChoose($raw, $target);
            $body['lines'] = array_map([self::class, 'finish'], $raw);
            unset($body['vat_amounts']);
            if ($va !== []) {
                $body['vat_amounts'] = $va;
            }
        }
        unset($body['prepaid_amount']);
        $base = (string) $order->getOrderNumber();
        $body['invoice_number'] = 'CN-' . $base . '-' . substr(preg_replace('/[^A-Za-z0-9]/', '', $key) ?: '1', -8);
        $body['type_code'] = '381';
        $body['preceding_invoice'] = $precedingNumber;
        $body['preceding_invoice_date'] = $precedingDate;
        $body['issue_date'] = $today->format('Y-m-d');
        $body['delivery_date'] = $today->format('Y-m-d');
        $iban = (string) preg_replace('/\s/', '', $s->str('sellerIban'));
        $terms = 'Refund to the original payment method';
        $body['payment'] = $iban !== ''
            ? ['means_code' => '58', 'iban' => $iban, 'account_name' => $s->str('sellerName'), 'reference' => $body['invoice_number'], 'terms' => $terms]
            : ['means_code' => '1', 'reference' => $body['invoice_number'], 'terms' => $terms];

        return $body;
    }

    /**
     * @return array<string,mixed>
     */
    public static function invoice(OrderEntity $order, Settings $s, ?string $format = null, ?\DateTimeInterface $today = null): array
    {
        $today ??= new \DateTimeImmutable();
        $sellerCountry = strtoupper($s->str('sellerCountry') ?: 'DE');
        $addr = self::billingAddress($order);
        $buyerCountry = strtoupper((string) $addr?->getCountry()?->getIso());
        $buyerVat = self::buyerVat($order);
        $small = $s->bool('smallBusiness');
        $taxStatus = $order->getPrice()->getTaxStatus();

        $zero = self::zeroCategory($order, $s);

        $raw = [];
        foreach ($order->getLineItems() ?? [] as $item) {
            if ($item->getParentId() !== null) {
                continue; // children are part of the parent's price
            }
            $payload = $item->getPayload() ?? [];
            $sku = (string) ($payload['productNumber'] ?? '');
            array_push($raw, ...self::lines((string) $item->getLabel(), (float) $item->getQuantity(), $item->getPrice() ?? new CalculatedPrice(0, 0, new CalculatedTaxCollection(), new TaxRuleCollection()), $taxStatus, $zero, $small, $sku));
        }
        array_push($raw, ...self::lines('Shipping', 1, $order->getShippingCosts(), $taxStatus, $zero, $small));
        [$raw, $vatAmounts] = self::reconcileChoose($raw, $order->getAmountTotal());
        $lines = array_map([self::class, 'finish'], $raw);

        $company = trim((string) $addr?->getCompany());
        $name = trim((string) $addr?->getFirstName() . ' ' . (string) $addr?->getLastName());
        $oc = $order->getOrderCustomer();
        $buyer = [
            'name' => $company !== '' ? $company : $name,
            'street' => trim((string) $addr?->getStreet() . ' ' . (string) $addr?->getAdditionalAddressLine1()),
            'city' => (string) $addr?->getCity(),
            'postal_code' => (string) $addr?->getZipcode(),
            'country' => $buyerCountry,
            'email' => (string) $oc?->getEmail(),
        ];
        if ($company !== '' && $name !== '') {
            $buyer['contact_name'] = $name;
        }
        if ($buyerVat !== '') {
            $buyer['vat_id'] = $buyerVat;
        }

        $seller = [
            'name' => $s->str('sellerName'),
            'street' => $s->str('sellerStreet'),
            'city' => $s->str('sellerCity'),
            'postal_code' => $s->str('sellerPostcode'),
            'country' => $sellerCountry,
            'email' => $s->str('sellerEmail'),
            'phone' => $s->str('sellerPhone'),
            'contact_name' => $s->str('sellerContact'),
        ];
        if ($s->str('sellerVatId') !== '') {
            $seller['vat_id'] = strtoupper((string) preg_replace('/\s/', '', $s->str('sellerVatId')));
        }
        if ($s->str('sellerTaxNo') !== '') {
            $seller['tax_number'] = $s->str('sellerTaxNo');
        }
        if ($s->str('sellerRegNo') !== '') {
            $seller['company_id'] = $s->str('sellerRegNo');
        }
        $seller = array_filter($seller, static fn ($v) => $v !== '');

        $paid = self::isPaid($order);
        $handler = '';
        $title = '';
        foreach ($order->getTransactions() ?? [] as $t) {
            $pm = $t->getPaymentMethod();
            if ($pm !== null) {
                $handler = (string) $pm->getHandlerIdentifier();
                $title = (string) $pm->getTranslation('name');
                break;
            }
        }
        $iban = (string) preg_replace('/\s/', '', $s->str('sellerIban'));
        $payment = [];
        if (str_contains($handler, 'PrePayment') || str_contains($handler, 'InvoicePayment') || (!$paid && $iban !== '')) {
            $payment['means_code'] = '58';
        } elseif (str_contains($handler, 'CashPayment')) {
            $payment['means_code'] = '10';
        } elseif ($paid) {
            $payment['means_code'] = '68';
        } else {
            $payment['means_code'] = '1';
        }
        if ($iban !== '' && $payment['means_code'] === '58') {
            $payment['iban'] = $iban;
            if ($s->str('sellerBic') !== '') {
                $payment['bic'] = $s->str('sellerBic');
            }
            $payment['account_name'] = $s->str('sellerName');
        }
        $number = self::invoiceNumber($order);
        $payment['reference'] = $number;
        $terms = $s->str('paymentTerms');
        if ($paid) {
            $terms = trim('Paid via ' . $title . ' ' . $order->getOrderDateTime()->format('Y-m-d'));
        }
        if ($terms !== '') {
            $payment['terms'] = $terms;
        }

        $body = [
            'format' => $format ?: self::formatFor($s),
            'invoice_number' => $number,
            'issue_date' => $today->format('Y-m-d'),
            'delivery_date' => $order->getOrderDateTime()->format('Y-m-d'),
            'currency' => (string) ($order->getCurrency()?->getIsoCode() ?? 'EUR'),
            'buyer_reference' => self::buyerReference($order),
            'order_reference' => (string) $order->getOrderNumber(),
            'seller' => $seller,
            'buyer' => array_filter($buyer, static fn ($v) => $v !== ''),
            'lines' => $lines,
            'payment' => $payment,
        ];
        if ($vatAmounts !== []) {
            $body['vat_amounts'] = $vatAmounts;
        }
        if ($paid) {
            $body['prepaid_amount'] = round($order->getAmountTotal(), 2);
        }
        if ($small) {
            $body['vat_exemption_reason'] = ['E' => 'No VAT charged under the small business scheme (section 19 UStG).'];
        }

        return $body;
    }
}
