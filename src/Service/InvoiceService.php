<?php declare(strict_types=1);

namespace WSystem\EInvoice\Service;

use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Psr\Log\LoggerInterface;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * Creates, stores and reads the e-invoice of an order. Data lives in the order's custom fields
 * (wsystem_einvoice_*), so there is no extra table and nothing to migrate.
 */
class InvoiceService
{
    public const F_XML = 'wsystem_einvoice_xml';
    public const F_VALID = 'wsystem_einvoice_valid';
    public const F_ERRORS = 'wsystem_einvoice_errors';
    public const F_FORMAT = 'wsystem_einvoice_format';
    public const F_NUMBER = 'wsystem_einvoice_number';
    public const F_TOTALS = 'wsystem_einvoice_totals';
    public const F_MISMATCH = 'wsystem_einvoice_mismatch';
    public const F_CREATED = 'wsystem_einvoice_created';
    public const F_LAST_ERROR = 'wsystem_einvoice_last_error';
    public const F_PDF = 'wsystem_einvoice_pdf';
    public const F_CREDIT_NOTES = 'wsystem_einvoice_credit_notes';

    public function __construct(
        private readonly EntityRepository $orderRepository,
        private readonly SystemConfigService $config,
        private readonly ApiClient $api,
        private readonly ?LoggerInterface $logger = null
    ) {
    }

    public function loadOrder(string $orderId, Context $context): ?OrderEntity
    {
        $criteria = new Criteria([$orderId]);
        $criteria->addAssociations([
            'lineItems', 'currency', 'orderCustomer.customer', 'addresses.country',
            'transactions.stateMachineState', 'transactions.paymentMethod', 'documents.documentType',
        ]);
        /** @var OrderEntity|null $order */
        $order = $this->orderRepository->search($criteria, $context)->getEntities()->first();

        return $order;
    }

    public function loadByNumber(string $number, Context $context): ?OrderEntity
    {
        $criteria = new Criteria();
        $criteria->addFilter(new \Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter('orderNumber', $number));
        $id = $this->orderRepository->searchIds($criteria, $context)->firstId();

        return $id ? $this->loadOrder($id, $context) : null;
    }

    /**
     * Tests the saved API key (used by the "Test API key" button in the plugin settings).
     *
     * @return array{ok:bool,message:string}
     */
    public function testApiKey(?string $salesChannelId): array
    {
        return $this->api->testKey(Settings::load($this->config, $salesChannelId)->str('apiKey'));
    }

    public function log(string $level, string $message): void
    {
        $this->logger?->log($level, '[WSystemEInvoice] ' . $message);
    }

    public function settingsFor(OrderEntity $order): Settings
    {
        return Settings::load($this->config, $order->getSalesChannelId());
    }

    public function shouldCreateAutomatically(OrderEntity $order, string $event): bool
    {
        $s = $this->settingsFor($order);
        if ($s->str('trigger') !== $event) {
            return false;
        }
        if (!empty(($order->getCustomFields() ?? [])[self::F_XML])) {
            return false;
        }
        if ($s->bool('onlyB2b') && !OrderMapper::isB2b($order)) {
            return false;
        }

        return true;
    }

    /**
     * @return array{valid:bool,error:string,errors:list<array{rule_id:string,message:string,fix:string}>}
     */
    public function generate(OrderEntity $order, Context $context): array
    {
        $s = $this->settingsFor($order);
        $body = OrderMapper::invoice($order, $s);
        $res = $this->api->create($body, $s->str('apiKey'));
        if (!$res['ok']) {
            $this->store($order, $context, [self::F_LAST_ERROR => $res['error']]);
            $this->log('warning', sprintf('E-invoice for order %s could not be created: %s', (string) $order->getOrderNumber(), $res['error']));

            return ['valid' => false, 'error' => $res['error'], 'errors' => []];
        }
        $d = $res['data'];
        $valid = !empty($d['valid']);
        $errors = [];
        foreach (\is_array($d['errors'] ?? null) ? $d['errors'] : [] as $e) {
            $errors[] = [
                'rule_id' => (string) ($e['rule_id'] ?? ''),
                'message' => (string) ($e['explain']['what'] ?? $e['message'] ?? ''),
                'fix' => (string) ($e['explain']['fix'] ?? $e['fix'] ?? ''),
            ];
        }
        $totals = \is_array($d['totals'] ?? null) ? $d['totals'] : [];
        $mismatch = '';
        if (isset($totals['total_with_vat']) && abs((float) $totals['total_with_vat'] - $order->getAmountTotal()) > 0.004) {
            $mismatch = sprintf('The e-invoice total (%s) differs from the order total (%s), usually by rounding. Check the tax rounding settings.', $totals['total_with_vat'], number_format($order->getAmountTotal(), 2, '.', ''));
        }
        $this->store($order, $context, [
            self::F_XML => (string) ($d['xml'] ?? ''),
            self::F_VALID => $valid ? 'yes' : 'no',
            self::F_ERRORS => $errors,
            self::F_FORMAT => $body['format'],
            self::F_NUMBER => $body['invoice_number'],
            self::F_TOTALS => $totals,
            self::F_MISMATCH => $mismatch,
            self::F_CREATED => gmdate('c'),
            self::F_PDF => $valid && $body['format'] === 'zugferd' && !empty($d['pdf_base64']) ? (string) $d['pdf_base64'] : null,
            self::F_LAST_ERROR => null,
        ]);

        return ['valid' => $valid, 'error' => '', 'errors' => $errors];
    }

    /**
     * Credit notes already stored on the order (newest last).
     *
     * @return list<array<string,mixed>>
     */
    public static function creditNotes(OrderEntity $order): array
    {
        $v = ($order->getCustomFields() ?? [])[self::F_CREDIT_NOTES] ?? [];

        return \is_array($v) ? array_values($v) : [];
    }

    /**
     * Whether a credit note with this key should be created now: option on, a valid e-invoice exists, the key is new,
     * and for a full credit note ($full) none exists yet (cancelled order and refunded payment must not both credit it).
     */
    public function shouldCreateCreditNote(OrderEntity $order, string $key, bool $full): bool
    {
        if (!$this->settingsFor($order)->bool('creditNotes')) {
            return false;
        }
        $st = self::status($order);
        if (!$st['exists'] || !$st['valid']) {
            return false;
        }
        foreach (self::creditNotes($order) as $cn) {
            if (($cn['key'] ?? '') === $key || ($full && !empty($cn['full']) && !empty($cn['valid']))) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<array{label:string,qty:float,price:\Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice,sku?:string}>|null $refundLines null = credit the whole order
     *
     * @return array{valid:bool,error:string,errors:list<array{rule_id:string,message:string,fix:string}>,skipped:bool}
     */
    public function createCreditNote(OrderEntity $order, Context $context, string $key, ?array $refundLines = null): array
    {
        $full = $refundLines === null;
        if (!$this->shouldCreateCreditNote($order, $key, $full)) {
            return ['valid' => false, 'error' => '', 'errors' => [], 'skipped' => true];
        }
        $s = $this->settingsFor($order);
        $cf = $order->getCustomFields() ?? [];
        $origNumber = (string) ($cf[self::F_NUMBER] ?? OrderMapper::invoiceNumber($order));
        $origDate = substr((string) ($cf[self::F_CREATED] ?? ''), 0, 10) ?: $order->getOrderDateTime()->format('Y-m-d');
        $body = OrderMapper::creditNote($order, $s, $key, $origNumber, $origDate, $refundLines);
        if ($body['lines'] === []) {
            return ['valid' => false, 'error' => '', 'errors' => [], 'skipped' => true];
        }
        $res = $this->api->create($body, $s->str('apiKey'));
        $entry = ['key' => $key, 'full' => $full, 'number' => $body['invoice_number'], 'original' => $origNumber, 'created' => gmdate('c'), 'valid' => false, 'xml' => '', 'pdf' => null, 'errors' => [], 'lastError' => ''];
        if (!$res['ok']) {
            $entry['lastError'] = $res['error'];
            $this->log('warning', sprintf('Credit note for order %s could not be created: %s', (string) $order->getOrderNumber(), $res['error']));
        } else {
            $d = $res['data'];
            $entry['valid'] = !empty($d['valid']);
            foreach (\is_array($d['errors'] ?? null) ? $d['errors'] : [] as $e) {
                $entry['errors'][] = ['rule_id' => (string) ($e['rule_id'] ?? ''), 'message' => (string) ($e['explain']['what'] ?? $e['message'] ?? ''), 'fix' => (string) ($e['explain']['fix'] ?? $e['fix'] ?? '')];
            }
            if ($entry['valid']) {
                $entry['xml'] = (string) ($d['xml'] ?? '');
                $entry['pdf'] = $body['format'] === 'zugferd' && !empty($d['pdf_base64']) ? (string) $d['pdf_base64'] : null;
            }
        }
        // keep failed attempts too (visible to the merchant), but replace an earlier failed one with the same key
        $list = array_values(array_filter(self::creditNotes($order), static fn ($c) => ($c['key'] ?? '') !== $key));
        $list[] = $entry;
        $this->store($order, $context, [self::F_CREDIT_NOTES => $list]);

        return ['valid' => $entry['valid'], 'error' => $entry['lastError'], 'errors' => $entry['errors'], 'skipped' => false];
    }

    public static function pdf(OrderEntity $order): string
    {
        return (string) (($order->getCustomFields() ?? [])[self::F_PDF] ?? '');
    }

    public static function pdfFileName(OrderEntity $order): string
    {
        return preg_replace('/\.xml$/', '.pdf', self::fileName($order)) ?? 'e-invoice.pdf';
    }

    /**
     * @param array<string,mixed> $cn
     */
    public static function creditNoteFileName(array $cn, string $ext): string
    {
        return 'credit-note-' . preg_replace('/[^A-Za-z0-9._-]+/', '_', (string) ($cn['number'] ?? 'cn')) . '.' . $ext;
    }

    /**
     * @param array<string,mixed> $fields
     */
    private function store(OrderEntity $order, Context $context, array $fields): void
    {
        $this->orderRepository->update([[
            'id' => $order->getId(),
            'customFields' => $fields,
        ]], $context);
    }

    /**
     * @return array{exists:bool,valid:bool,format:string,number:string,errors:array<mixed>,totals:array<mixed>,mismatch:string,created:string,lastError:string}
     */
    public static function status(OrderEntity $order): array
    {
        $cf = $order->getCustomFields() ?? [];

        return [
            'exists' => !empty($cf[self::F_XML]),
            'valid' => ($cf[self::F_VALID] ?? '') === 'yes',
            'format' => (string) ($cf[self::F_FORMAT] ?? ''),
            'number' => (string) ($cf[self::F_NUMBER] ?? ''),
            'errors' => (array) ($cf[self::F_ERRORS] ?? []),
            'totals' => (array) ($cf[self::F_TOTALS] ?? []),
            'mismatch' => (string) ($cf[self::F_MISMATCH] ?? ''),
            'created' => (string) ($cf[self::F_CREATED] ?? ''),
            'lastError' => (string) ($cf[self::F_LAST_ERROR] ?? ''),
        ];
    }

    public static function xml(OrderEntity $order): string
    {
        return (string) (($order->getCustomFields() ?? [])[self::F_XML] ?? '');
    }

    public static function fileName(OrderEntity $order): string
    {
        $n = (string) (($order->getCustomFields() ?? [])[self::F_NUMBER] ?? $order->getOrderNumber());

        return 'e-invoice-' . preg_replace('/[^A-Za-z0-9._-]+/', '_', $n) . '.xml';
    }
}
