# 1.1.2
- Fix: `vat_amounts` after a second-pass net shift no longer double-corrects the total by a cent on APIs that honour the field. A net set that still matches without `vat_amounts` is preferred.

# 1.1.1
- Fix: shipping costs with tax type "auto" and percentage promotions in mixed-rate carts are now split per VAT rate (they were put entirely at one rate, so the VAT breakdown and the total differed from the order).
- Gross prices: the e-invoice total now equals the order total. Where Shopware's per-line VAT rounding cannot be reached by any net amount (e.g. one item at 9.99 incl. 19 % VAT), the VAT Shopware charged per rate is sent as `vat_amounts` (used by the W System API once its vat_amounts support is live; until then the API ignores the field and behaves as before).

# 1.1.0
- New (both options are OFF by default, so existing shops behave exactly as before): ZUGFeRD / Factur-X PDF (EN 16931, PDF/A-3b with the XML embedded) instead of XML only, and automatic credit notes (type 381, referencing the original e-invoice) for cancelled orders, refunded payments and Shopware refunds (refunded positions).
- Order emails carry the ZUGFeRD PDF instead of the XML when it exists; the credit note is attached to the cancellation / refund email (option).
- Admin API: download of the PDF and of credit notes (`/api/_action/wsystem-einvoice/order/{id}/download-pdf`, `/credit-notes`).
- The automatic creation on "paid" / "completed" now runs before the order email is built, so the e-invoice can be attached to that very email.
- Credit notes only for orders that already have a valid e-invoice; a cancelled order and its refunded payment produce one credit note, not two.

# 1.0.1
- "Test saved API key" button in the settings, failures are written to the Shopware log, API timeout 25 s.

# 1.0.0
- First release: XRechnung 3.0 (UBL), EN 16931 (UBL) and Peppol BIS Billing 3.0 structure e-invoices for Shopware 6 orders, validated through the W System API.
- Automatic creation when an order is placed, paid or completed (or manual), download and re-create on the order detail page, optional attachment to order emails.
- Buyer reference / Leitweg-ID custom field on orders and customers.
