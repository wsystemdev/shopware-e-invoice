<?php declare(strict_types=1);

namespace WSystem\EInvoice\Controller;

use Shopware\Core\Framework\Context;
use Shopware\Core\PlatformRequest;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use WSystem\EInvoice\Service\InvoiceService;

#[Route(defaults: [PlatformRequest::ATTRIBUTE_ROUTE_SCOPE => ['api']])]
class InvoiceController
{
    public function __construct(private readonly InvoiceService $invoices)
    {
    }

    #[Route(path: '/api/_action/wsystem-einvoice/test-api', name: 'api.action.wsystem_einvoice.test_api', defaults: ['_acl' => ['system_config:read']], methods: ['POST'])]
    public function testApi(Request $request): Response
    {
        $r = $this->invoices->testApiKey((string) $request->request->get('salesChannelId', '') ?: null);
        $this->invoices->log($r['ok'] ? 'info' : 'warning', 'API test: ' . $r['message']);

        return new JsonResponse($r);
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

    #[Route(path: '/api/_action/wsystem-einvoice/order/{orderId}/download-pdf', name: 'api.action.wsystem_einvoice.download_pdf', defaults: ['_acl' => ['order:read']], methods: ['GET'])]
    public function downloadPdf(string $orderId, Context $context): Response
    {
        $order = $this->invoices->loadOrder($orderId, $context);
        $pdf = $order !== null ? base64_decode(InvoiceService::pdf($order), true) : false;
        if ($order === null || $pdf === false || $pdf === '') {
            return new JsonResponse(['error' => 'no ZUGFeRD PDF for this order'], 404);
        }

        return new Response($pdf, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="' . InvoiceService::pdfFileName($order) . '"']);
    }

    #[Route(path: '/api/_action/wsystem-einvoice/order/{orderId}/credit-notes', name: 'api.action.wsystem_einvoice.credit_notes', defaults: ['_acl' => ['order:read']], methods: ['GET'])]
    public function creditNotes(string $orderId, Context $context): Response
    {
        $order = $this->invoices->loadOrder($orderId, $context);
        if ($order === null) {
            return new JsonResponse(['error' => 'order not found'], 404);
        }

        // metadata only; files via /credit-notes/{index}/download?format=xml|pdf
        return new JsonResponse(array_map(static fn (array $c) => ['key' => $c['key'] ?? '', 'number' => $c['number'] ?? '', 'original' => $c['original'] ?? '', 'valid' => !empty($c['valid']), 'created' => $c['created'] ?? '', 'errors' => $c['errors'] ?? [], 'lastError' => $c['lastError'] ?? '', 'hasPdf' => !empty($c['pdf'])], InvoiceService::creditNotes($order)));
    }

    #[Route(path: '/api/_action/wsystem-einvoice/order/{orderId}/credit-notes/{index}/download', name: 'api.action.wsystem_einvoice.credit_note_download', defaults: ['_acl' => ['order:read']], methods: ['GET'])]
    public function downloadCreditNote(string $orderId, int $index, Request $request, Context $context): Response
    {
        $order = $this->invoices->loadOrder($orderId, $context);
        $cn = $order !== null ? (InvoiceService::creditNotes($order)[$index] ?? null) : null;
        if ($cn === null || empty($cn['valid'])) {
            return new JsonResponse(['error' => 'no such credit note'], 404);
        }
        if ($request->query->get('format') === 'pdf' && !empty($cn['pdf'])) {
            return new Response((string) base64_decode((string) $cn['pdf'], true), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="' . InvoiceService::creditNoteFileName($cn, 'pdf') . '"']);
        }

        return new Response((string) $cn['xml'], 200, ['Content-Type' => 'application/xml; charset=utf-8', 'Content-Disposition' => 'attachment; filename="' . InvoiceService::creditNoteFileName($cn, 'xml') . '"']);
    }
}
