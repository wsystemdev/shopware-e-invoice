<?php declare(strict_types=1);

namespace WSystem\EInvoice\Service;

use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Cart\Price\Struct\CartPrice;
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
            if (\is_string($id) && trim($id) !== '') {
                return strtoupper((string) preg_replace('/[\s.\-]/', '', $id));
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
            $type = $doc->getDocumentType();
            if ($type !== null && $type->getTechnicalName() === 'invoice') {
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

        return ['desc' => $desc, 'qty' => $qty, 'net' => $net, 'gross' => $gross ? round($total, 2) : null, 'cat' => $cat, 'rate' => $rate, 'sku' => $sku];
    }

    /**
     * Shopware rounds VAT on the summed gross of each tax rate, the e-invoice computes it on the summed net.
     * Shift the net of the last line of a rate by a cent where needed so the e-invoice total equals the order total.
     *
     * @param list<array<string,mixed>> $raw
     *
     * @return list<array<string,mixed>>
     */
    private static function reconcile(array $raw, float $target): array
    {
        $groups = [];
        foreach ($raw as $i => $l) {
            if ($l['gross'] !== null && $l['cat'] === 'S') {
                $groups[(string) $l['rate']][] = $i;
            }
        }
        foreach ($groups as $rate => $idx) {
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

        // Second pass: some gross amounts cannot be reached by one rate alone; make the total match by shifting
        // the last line of one rate by 1 or 2 cents.
        if (abs(self::total($raw) - round($target, 2)) > 0.001) {
            foreach ([1, -1, 2, -2] as $d) {
                foreach ($groups as $idx) {
                    $last = end($idx);
                    $try = $raw;
                    $try[$last]['net'] = round($try[$last]['net'] + $d / 100, 2);
                    if (abs(self::total($try) - round($target, 2)) < 0.001) {
                        return $try;
                    }
                }
            }
        }

        return $raw;
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

        $zero = 'Z';
        if ($small) {
            $zero = 'E';
        } elseif ($buyerCountry !== '' && $buyerCountry !== $sellerCountry && !\in_array($buyerCountry, self::EU, true)) {
            $zero = 'G';
        } elseif ($buyerVat !== '' && $buyerCountry !== $sellerCountry && \in_array($buyerCountry, self::EU, true)) {
            $zero = 'AE';
        }

        $raw = [];
        foreach ($order->getLineItems() ?? [] as $item) {
            if ($item->getParentId() !== null) {
                continue; // children are part of the parent's price
            }
            $payload = $item->getPayload() ?? [];
            $sku = (string) ($payload['productNumber'] ?? '');
            $l = self::line((string) $item->getLabel(), (float) $item->getQuantity(), $item->getPrice() ?? new CalculatedPrice(0, 0, new \Shopware\Core\Checkout\Cart\Price\Struct\CalculatedTaxCollection(), new \Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection()), $taxStatus, $zero, $small, $sku);
            if ($l !== null) {
                $raw[] = $l;
            }
        }
        $ship = $order->getShippingCosts();
        $l = self::line('Shipping', 1, $ship, $taxStatus, $zero, $small);
        if ($l !== null) {
            $raw[] = $l;
        }
        $lines = array_map([self::class, 'finish'], self::reconcile($raw, $order->getAmountTotal()));

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
            'format' => $format ?: ($s->str('format') ?: 'xrechnung'),
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
        if ($paid) {
            $body['prepaid_amount'] = round($order->getAmountTotal(), 2);
        }
        if ($small) {
            $body['vat_exemption_reason'] = ['E' => 'No VAT charged under the small business scheme (section 19 UStG).'];
        }

        return $body;
    }
}
