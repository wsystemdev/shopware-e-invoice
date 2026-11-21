<?php declare(strict_types=1);

namespace WSystem\EInvoice\Service;

use Shopware\Core\System\SystemConfig\SystemConfigService;

final class Settings
{
    public const DOMAIN = 'WSystemEInvoice.config';

    private const DEFAULTS = [
        'apiKey' => '',
        'format' => 'xrechnung',
        'trigger' => 'completed',
        'onlyB2b' => false,
        'attachEmail' => true,
        'attachOrderConfirmation' => true,
        'attachCompleted' => true,
        'attachPaid' => true,
        'attachShipped' => false,
        'sellerName' => '',
        'sellerStreet' => '',
        'sellerPostcode' => '',
        'sellerCity' => '',
        'sellerCountry' => 'DE',
        'sellerVatId' => '',
        'sellerTaxNo' => '',
        'sellerRegNo' => '',
        'smallBusiness' => false,
        'sellerEmail' => '',
        'sellerPhone' => '',
        'sellerContact' => '',
        'sellerIban' => '',
        'sellerBic' => '',
        'paymentTerms' => '',
    ];

    /**
     * @param array<string,mixed> $values
     */
    public function __construct(private readonly array $values = [])
    {
    }

    public static function load(SystemConfigService $config, ?string $salesChannelId): self
    {
        $raw = $config->getDomain(self::DOMAIN, $salesChannelId, true);
        $values = [];
        foreach ($raw as $key => $value) {
            $values[str_starts_with($key, self::DOMAIN . '.') ? substr($key, \strlen(self::DOMAIN) + 1) : $key] = $value;
        }

        return new self($values);
    }

    public function str(string $key): string
    {
        $v = $this->values[$key] ?? self::DEFAULTS[$key] ?? '';

        return \is_scalar($v) ? trim((string) $v) : '';
    }

    public function bool(string $key): bool
    {
        $v = \array_key_exists($key, $this->values) && $this->values[$key] !== null ? $this->values[$key] : (self::DEFAULTS[$key] ?? false);

        return filter_var($v, \FILTER_VALIDATE_BOOLEAN);
    }
}
