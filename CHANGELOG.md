# 1.1.0
- New (both options are OFF by default, so existing shops behave exactly as before): ZUGFeRD / Factur-X PDF (EN 16931, PDF/A-3b with the XML embedded) instead of XML only, and automatic credit notes (type 381, referencing the original e-invoice) for cancelled orders, refunded payments and Shopware refunds (refunded positions).
- Order emails carry the ZUGFeRD PDF instead of the XML when it exists; the credit note is attached to the cancellation / refund email (option).
- Admin API: download of the PDF and of credit notes (`/api/_action/wsystem-einvoice/order/{id}/download-pdf`, `/credit-notes`).
- Credit notes only for orders that already have a valid e-invoice; a cancelled order and its refunded payment produce one credit note, not two.

# 1.0.1
- "Test saved API key" button in the settings, failures are written to the Shopware log, API timeout 25 s.

# 1.0.0
- First release: XRechnung 3.0 (UBL), EN 16931 (UBL) and Peppol BIS Billing 3.0 structure e-invoices for Shopware 6 orders, validated through the W System API.
- Automatic creation when an order is placed, paid or completed (or manual), download and re-create on the order detail page, optional attachment to order emails.
- Buyer reference / Leitweg-ID custom field on orders and customers.
