# Shopware Store listing (prepared, NOT submitted)

Status: texts, screenshots and icon are ready. No Shopware Account / Extension Partner contract exists yet: both need Zifeng's OK (see "Needs approval").

## Master data
- Extension name (technical): WSystemEInvoice (composer `wsystem/shopware-e-invoice`), version 1.0.1
- Store name EN: W System E-Invoice (XRechnung / EN 16931)
- Store name DE: W System E-Rechnung (XRechnung / EN 16931)
- Manufacturer: W System (https://wsystem.dev), support: hello@wsystem.dev, issues: https://github.com/wsystemdev/shopware-e-invoice/issues
- Price: free (no locked features, no licence check), licence GPL-3.0-or-later
- Compatibility: Shopware 6.5, 6.6, 6.7 (PHP 8.1+). Tested end to end on 6.6.10. Not for Shopware Cloud (plugins cannot be installed there)
- Categories: Accounting / Invoicing (Buchhaltung / Rechnungen); tags: xrechnung, e-rechnung, en16931, peppol, leitweg-id, e-invoice
- Extension prefix (Extension Partner profile): WSystem
- Upload: https://github.com/wsystemdev/shopware-e-invoice/releases/latest/download/WSystemEInvoice.zip (`shopware-cli extension validate`: 0 errors, 2 deprecation warnings about services.xml / routes.xml)
- Icon: src/Resources/config/plugin.png (256x256). Screenshots: store/screenshots/01-order-detail.png, 02-settings.png, 03-settings-seller.png

## Short description EN (max ~200 chars)
Create XRechnung 3.0 and EN 16931 e-invoices from Shopware orders, validated against the official rules. Download on the order, attach to order emails. Free, no locked features.

## Short description DE
E-Rechnungen (XRechnung 3.0, EN 16931) aus Shopware-Bestellungen erstellen, gegen die offiziellen Regeln geprüft. Download an der Bestellung, Anhang an Bestell-E-Mails. Kostenlos, keine gesperrten Funktionen.

## Long description EN
**E-invoices for your Shopware orders, ready for the German e-invoicing duty.**
Since 1 January 2025 every business in Germany must be able to receive e-invoices. From 1 January 2027 businesses with more than EUR 800,000 turnover in the previous year must issue domestic B2B invoices as e-invoices, and from 1 January 2028 all businesses must. A PDF is no longer enough: the invoice has to be a structured file such as XRechnung.

W System E-Invoice builds the structured file from each Shopware order and checks it before it leaves your shop.

- XRechnung 3.0 (UBL) for Germany, EN 16931 (UBL) for the EU, or Peppol BIS Billing 3.0 structure
- Checked on every invoice against the XML Schema, the CEN EN 16931 Schematron rules and the KoSIT XRechnung rules; errors appear on the order with what to fix
- Created automatically when the order is placed, paid or completed, or by button on the order
- Download the XML on the order detail page, create it again at any time
- Optional attachment to the order confirmation, payment, completed and shipped emails (only valid invoices)
- 19 % and 7 % VAT, shipping, discounts and vouchers, prepaid orders, bank transfer with your IBAN, intra-EU reverse charge with a VAT ID, exports, small business scheme (section 19 UStG)
- Leitweg-ID / buyer reference field on orders and customers for public-sector buyers
- Uses the number of Shopware's invoice document if present; totals match the order to the cent
- Settings per sales channel, English and German, API key test button
- No locked features, no licence check, open source (GPL)

**External service and data:** the plugin needs the W System API (https://api.wsystem.dev, operated by W System). When an e-invoice is created, the invoice data of the order is sent there: your seller details from the settings, the customer's billing name, company, address, email and VAT ID, order and invoice number, dates, currency, line items, shipping, payment status and the buyer reference. Nothing is sent on installation, in the storefront or at checkout. Terms: https://wsystem.dev/terms, privacy: https://wsystem.dev/privacy. Without an API key the free tier allows 20 invoices per day per server IP; a free key (email only, no card) has 500 units a month (8 units per invoice, about 60 invoices); larger quotas are paid, see https://wsystem.dev/pricing. The plugin itself is free.

Not included yet: CII files, ZUGFeRD/Factur-X PDF, credit notes for cancellations, Peppol sending from the shop. Not tax or legal advice.

## Long description DE
**E-Rechnungen für Ihre Shopware-Bestellungen, bereit für die E-Rechnungspflicht.**
Seit 1. Januar 2025 müssen alle Unternehmen in Deutschland E-Rechnungen empfangen können. Ab 1. Januar 2027 müssen Unternehmen mit mehr als 800.000 EUR Vorjahresumsatz inländische B2B-Rechnungen als E-Rechnung ausstellen, ab 1. Januar 2028 alle. Ein PDF reicht dann nicht mehr: Die Rechnung muss eine strukturierte Datei sein, etwa eine XRechnung.

W System E-Rechnung erzeugt die strukturierte Datei aus jeder Shopware-Bestellung und prüft sie, bevor sie Ihren Shop verlässt.

- XRechnung 3.0 (UBL) für Deutschland, EN 16931 (UBL) für die EU oder Peppol-BIS-Billing-3.0-Struktur
- Bei jeder Rechnung geprüft gegen XML-Schema, CEN-EN-16931-Schematron und KoSIT-XRechnung-Regeln; Fehler stehen mit Korrekturhinweis an der Bestellung
- Automatisch bei Bestellung, Zahlung oder Abschluss, oder per Knopf an der Bestellung
- XML-Download in der Bestelldetailseite, jederzeit neu erstellbar
- Optionaler Anhang an Bestellbestätigung, Zahlungs-, Abschluss- und Versand-E-Mail (nur gültige Rechnungen)
- 19 % und 7 % USt., Versand, Rabatte und Gutscheine, bezahlte Bestellungen, Überweisung mit IBAN, innergemeinschaftliche Lieferung mit USt-IdNr. (Reverse Charge), Export, Kleinunternehmerregelung (§ 19 UStG)
- Leitweg-ID / Käuferreferenz an Bestellung und Kunde für öffentliche Auftraggeber
- Nutzt die Nummer des Shopware-Rechnungsdokuments, falls vorhanden; Summen stimmen auf den Cent mit der Bestellung überein
- Einstellungen je Verkaufskanal, Deutsch und Englisch, Knopf zum Testen des API-Schlüssels
- Keine gesperrten Funktionen, keine Lizenzprüfung, Open Source (GPL)

**Externer Dienst und Daten:** Das Plugin benötigt die W System API (https://api.wsystem.dev, betrieben von W System). Beim Erstellen einer E-Rechnung werden die Rechnungsdaten der Bestellung dorthin gesendet: Ihre Verkäuferdaten aus den Einstellungen, Name, Firma, Adresse, E-Mail und USt-IdNr. des Kunden, Bestell- und Rechnungsnummer, Daten, Währung, Positionen, Versand, Zahlungsstatus und die Käuferreferenz. Bei Installation, im Storefront und im Checkout wird nichts gesendet. Bedingungen: https://wsystem.dev/terms, Datenschutz: https://wsystem.dev/privacy. Ohne API-Schlüssel erlaubt die kostenlose Stufe 20 Rechnungen pro Tag und Server-IP; ein kostenloser Schlüssel (nur E-Mail, keine Karte) enthält 500 Einheiten im Monat (8 Einheiten pro Rechnung, etwa 60 Rechnungen); größere Kontingente sind kostenpflichtig, siehe https://wsystem.dev/de/preise. Das Plugin selbst ist kostenlos.

Noch nicht enthalten: CII-Dateien, ZUGFeRD-/Factur-X-PDF, Gutschriften für Stornierungen, Peppol-Versand aus dem Shop. Keine Steuer- oder Rechtsberatung.

## Needs approval from Zifeng before submission (not done)
1. Shopware Account sign-up and acceptance of the Extension Partner contract (account terms + Extension Partner contract; free to sign, no listing fee for free extensions per Shopware docs, but it is a contract).
2. Likely Shopware Technology Partner (STP) agreement: Shopware requires it when an extension connects to an external service with downstream costs for the merchant. Our API is free for the first 20 calls/day or 500 units/month and paid above that. STP means a contract with revenue share and an annual fee (amounts only from Shopware sales: alliances@shopware.com) and commission tracking in the plugin (POST /shopwarepartners/reports/technology). Option to avoid: keep the plugin as a GitHub/Composer release only, or ask Shopware in advance whether the free-tier model needs STP.
3. The Store uses the Shopware Store review (automated code review + manual QA, mails to qa@shopware.com); the differentiator-cluster check may reject if similar e-invoice extensions exist ("If an extension with the same function exists ... it can be rejected").
