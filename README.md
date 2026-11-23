# W System E-Invoice for Shopware

Shopware 6 plugin that creates **XRechnung 3.0** and **EN 16931** e-invoices (UBL XML) from orders, checks them against the official XML Schema, CEN EN 16931 Schematron and KoSIT XRechnung rules, lets you download them from the order and can attach them to order emails. Powered by the [W System API](https://wsystem.dev).

* Landing page and guide: https://wsystem.dev/shopware-xrechnung (German: https://wsystem.dev/de/shopware-xrechnung)
* Works with Shopware 6.5, 6.6 and 6.7 (self-hosted and PaaS shops that can install plugins). Tested end to end on 6.6.
* Free for 50 invoices a month, then from €12 (shop plans: https://wsystem.dev/online-shops).
* Free tier: 20 API calls per day per server IP, no key. Free API key: https://api.wsystem.dev/account/free-key?ref=shopware-einvoice
* All features are free in the plugin. There are no locked features and no licence check.

## Features

- XRechnung 3.0 (UBL), EN 16931 (UBL) or Peppol BIS Billing 3.0 structure, chosen in the settings
- Validation on every invoice; errors and fix hints are shown on the order
- Created automatically when the order is placed, paid or completed, or by button on the order
- Download the XML on the order detail page (Orders > order > General > E-invoice) and create it again at any time
- Optional attachment to the order confirmation, payment, completed and shipped emails (only when the invoice passed validation)
- Several VAT rates (19 % and 7 %), shipping, discounts and vouchers, prepaid orders, bank transfer with your IBAN, intra-EU reverse charge for business customers with a VAT ID, exports outside the EU, small business scheme (section 19 UStG)
- Buyer reference / Leitweg-ID as a custom field on the order and on the customer (the order value wins, the order number is the fallback)
- Uses the number of Shopware's own invoice document if one exists, so XML and PDF carry the same number
- Totals are matched to the Shopware order total to the cent
- English and German

Since 1.1.0 (settings card "ZUGFeRD and credit notes", both options OFF by default):
* **ZUGFeRD / Factur-X PDF** (EN 16931, PDF/A-3b with `factur-x.xml` embedded) instead of XML only; the PDF is attached to order emails instead of the XML. Not used with the Peppol BIS format.
* **Credit notes** (type 381, referencing the original e-invoice number and date) created automatically when an order is cancelled, a payment is refunded, or a Shopware refund is completed (then only the refunded positions). Needs a valid e-invoice on the order. A cancelled order and its refunded payment give one credit note. The credit note is attached to the cancellation / refund email (option). Download: `GET /api/_action/wsystem-einvoice/order/{id}/credit-notes`.

Not yet: Peppol sending from the shop, partial refunds that Shopware records without positions and without tax data. Not tax or legal advice.

## Data sent to W System

When an e-invoice is created the plugin sends the invoice data of the order to **https://api.wsystem.dev/einvoice/create**: your seller details from the settings (company, address, VAT/tax number, contact, IBAN/BIC), the customer's billing name, company, address, email and VAT ID, order and invoice number, dates, currency, line items (name, SKU, quantity, net price, VAT rate), shipping, payment status and the buyer reference. The API key is sent as `X-API-Key` only if you entered one. Nothing is sent on installation, in the storefront or at checkout. Terms: https://wsystem.dev/terms, privacy: https://wsystem.dev/privacy.

## Install

Zip (any shop): download `WSystemEInvoice.zip` from the [latest release](https://github.com/wsystemdev/shopware-e-invoice/releases/latest), then *Extensions > My extensions > Upload extension*, install and activate. Or with Composer:

```
composer config repositories.wsystem-e-invoice vcs https://github.com/wsystemdev/shopware-e-invoice
composer require wsystem/shopware-e-invoice
bin/console plugin:refresh
bin/console plugin:install --activate WSystemEInvoice
bin/console cache:clear
```

Then open *Extensions > My extensions > E-Invoice > Configure*, fill in your company details (XRechnung requires contact name, phone and email) and place a test order.

## Development and tests

```
composer install   # in a Shopware project with the plugin in custom/plugins/WSystemEInvoice
SHOPWARE_AUTOLOAD=$PWD/vendor/autoload.php vendor/bin/phpunit -c custom/plugins/WSystemEInvoice/phpunit.xml.dist
APP_URL=http://127.0.0.1:8000 php custom/plugins/WSystemEInvoice/tests/e2e/e2e.php   # real orders vs the live API
./build-zip.sh   # dist/WSystemEInvoice.zip
```

CLI: `bin/console wsystem:einvoice:generate <orderNumber>`.

License: GPL-3.0-or-later.
