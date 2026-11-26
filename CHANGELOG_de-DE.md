# 1.1.1
- Fehlerbehebung: Versandkosten mit Steuerart „Auto“ und prozentuale Rabatte in Warenkörben mit mehreren Steuersätzen werden jetzt je Steuersatz aufgeteilt (bisher landeten sie komplett bei einem Satz, sodass USt-Aufschlüsselung und Summe von der Bestellung abwichen).
- Bruttopreise: Die Summe der E-Rechnung entspricht jetzt der Bestellsumme. Wo die zeilenweise USt-Rundung von Shopware mit keinem Nettobetrag erreichbar ist (z. B. ein Artikel zu 9,99 € inkl. 19 % USt), wird die von Shopware berechnete USt je Satz als `vat_amounts` übergeben (berücksichtigt von der W-System-API ab Version 0.3.162; ältere API-Versionen ignorieren das Feld wie bisher).

# 1.1.0
- Neu (beide Optionen sind standardmäßig AUS, bestehende Shops verhalten sich unverändert): ZUGFeRD / Factur-X PDF (EN 16931, PDF/A-3b mit eingebetteter XML) statt nur XML sowie automatische Gutschriften (Typ 381, mit Verweis auf die ursprüngliche E-Rechnung) bei stornierten Bestellungen, erstatteten Zahlungen und Shopware-Erstattungen (erstattete Positionen).
- Bestell-E-Mails enthalten statt der XML das ZUGFeRD-PDF, sofern vorhanden; die Gutschrift wird (optional) an die Stornierungs- bzw. Erstattungs-E-Mail angehängt.
- Admin-API: Download von PDF und Gutschriften (`/api/_action/wsystem-einvoice/order/{id}/download-pdf`, `/credit-notes`).
- Die automatische Erstellung bei „bezahlt“ / „abgeschlossen“ läuft jetzt vor dem Bau der Bestell-E-Mail, sodass die E-Rechnung an genau diese E-Mail angehängt werden kann.
- Gutschriften nur für Bestellungen mit bereits gültiger E-Rechnung; stornierte Bestellung und erstattete Zahlung erzeugen eine Gutschrift, nicht zwei.

# 1.0.1
- Knopf „Gespeicherten API-Schlüssel testen“ in den Einstellungen, Fehler werden ins Shopware-Log geschrieben, API-Timeout 25 s.

# 1.0.0
- Erste Version: E-Rechnungen als XRechnung 3.0 (UBL), EN 16931 (UBL) und Peppol BIS Billing 3.0 für Shopware-6-Bestellungen, geprüft über die W System API.
- Automatische Erstellung bei Bestellung, Zahlung oder Abschluss (oder manuell), Download und Neuerstellung in der Bestelldetailseite, optionaler Anhang an Bestell-E-Mails.
- Zusatzfeld Käuferreferenz / Leitweg-ID an Bestellungen und Kunden.
