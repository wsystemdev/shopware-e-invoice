<?php declare(strict_types=1);

namespace WSystem\EInvoice\Service;

use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
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

    public function __construct(
        private readonly EntityRepository $orderRepository,
        private readonly SystemConfigService $config,
        private readonly ApiClient $api
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
        $order = $this->orderRepository->search($criteria, $context)->first();

        return $order;
    }

    public function loadByNumber(string $number, Context $context): ?OrderEntity
    {
        $criteria = new Criteria();
        $criteria->addFilter(new \Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter('orderNumber', $number));
        $id = $this->orderRepository->searchIds($criteria, $context)->firstId();

        return $id ? $this->loadOrder($id, $context) : null;
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
            self::F_LAST_ERROR => null,
        ]);

        return ['valid' => $valid, 'error' => '', 'errors' => $errors];
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
