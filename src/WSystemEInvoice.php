<?php declare(strict_types=1);

namespace WSystem\EInvoice;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\InstallContext;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;
use Shopware\Core\System\CustomField\CustomFieldTypes;

class WSystemEInvoice extends Plugin
{
    public const FIELD_SET = 'wsystem_einvoice';
    public const BUYER_REFERENCE = 'wsystem_einvoice_buyer_reference';

    public function install(InstallContext $installContext): void
    {
        $this->upsertCustomFields($installContext->getContext());
    }

    public function update(Plugin\Context\UpdateContext $updateContext): void
    {
        $this->upsertCustomFields($updateContext->getContext());
    }

    public function uninstall(UninstallContext $uninstallContext): void
    {
        if ($uninstallContext->keepUserData()) {
            return;
        }
        /** @var EntityRepository $repo */
        $repo = $this->container->get('custom_field_set.repository');
        $ids = $repo->searchIds((new Criteria())->addFilter(new EqualsFilter('name', self::FIELD_SET)), $uninstallContext->getContext())->getIds();
        if ($ids !== []) {
            $repo->delete(array_map(static fn ($id) => ['id' => $id], $ids), $uninstallContext->getContext());
        }
    }

    /**
     * @return array<string,mixed>
     */
    public static function customFieldSet(): array
    {
        return [
            'name' => self::FIELD_SET,
            'config' => ['label' => ['en-GB' => 'E-invoice (W System)', 'de-DE' => 'E-Rechnung (W System)']],
            'relations' => [['entityName' => 'order'], ['entityName' => 'customer']],
            'customFields' => [[
                'name' => self::BUYER_REFERENCE,
                'type' => CustomFieldTypes::TEXT,
                'config' => [
                    'label' => ['en-GB' => 'Buyer reference / Leitweg-ID', 'de-DE' => 'Käuferreferenz / Leitweg-ID'],
                    'helpText' => ['en-GB' => 'Needed for public-sector customers (BT-10). Empty: the order number is used.', 'de-DE' => 'Für öffentliche Auftraggeber (BT-10) nötig. Leer: die Bestellnummer wird verwendet.'],
                    'customFieldPosition' => 1,
                ],
            ]],
        ];
    }

    private function upsertCustomFields(Context $context): void
    {
        /** @var EntityRepository $repo */
        $repo = $this->container->get('custom_field_set.repository');
        $set = self::customFieldSet();
        $existing = $repo->searchIds((new Criteria())->addFilter(new EqualsFilter('name', self::FIELD_SET)), $context)->firstId();
        if ($existing !== null) {
            return;
        }
        $repo->create([$set], $context);
    }
}
