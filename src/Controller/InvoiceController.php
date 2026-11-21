<?php declare(strict_types=1);

namespace WSystem\EInvoice\Controller;

use Shopware\Core\Framework\Context;
use Shopware\Core\PlatformRequest;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use WSystem\EInvoice\Service\InvoiceService;

#[Route(defaults: [PlatformRequest::ATTRIBUTE_ROUTE_SCOPE => ['api']])]
class InvoiceController
{
    public function __construct(private readonly InvoiceService $invoices)
    {
    }

    #[Route(path: '/api/_action/wsystem-einvoice/order/{orderId}/status', name: 'api.action.wsystem_einvoice.status', defaults: ['_acl' => ['order:read']], methods: ['GET'])]
    public function status(string $orderId, Context $context): Response
    {
        $order = $this->invoices->loadOrder($orderId, $context);
        if ($order === null) {
            return new JsonResponse(['error' => 'order not found'], 404);
        }

        return new JsonResponse(InvoiceService::status($order));
    }

    #[Route(path: '/api/_action/wsystem-einvoice/order/{orderId}/generate', name: 'api.action.wsystem_einvoice.generate', defaults: ['_acl' => ['order:update']], methods: ['POST'])]
    public function generate(string $orderId, Context $context): Response
    {
        $order = $this->invoices->loadOrder($orderId, $context);
        if ($order === null) {
            return new JsonResponse(['error' => 'order not found'], 404);
        }
        $result = $this->invoices->generate($order, $context);
        $order = $this->invoices->loadOrder($orderId, $context) ?? $order;

        return new JsonResponse(['result' => $result] + InvoiceService::status($order));
    }

    #[Route(path: '/api/_action/wsystem-einvoice/order/{orderId}/download', name: 'api.action.wsystem_einvoice.download', defaults: ['_acl' => ['order:read']], methods: ['GET'])]
    public function download(string $orderId, Context $context): Response
    {
        $order = $this->invoices->loadOrder($orderId, $context);
        if ($order === null || InvoiceService::xml($order) === '') {
            return new JsonResponse(['error' => 'no e-invoice for this order'], 404);
        }

        return new Response(InvoiceService::xml($order), 200, [
            'Content-Type' => 'application/xml; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="' . InvoiceService::fileName($order) . '"',
        ]);
    }
}
