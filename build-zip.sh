#!/usr/bin/env bash
# Builds dist/WSystemEInvoice.zip: the installable Shopware plugin (top-level folder WSystemEInvoice/, no dev files).
set -euo pipefail
cd "$(dirname "$0")"
rm -rf dist/build && mkdir -p dist/build/WSystemEInvoice
cp -r composer.json LICENSE README.md CHANGELOG.md CHANGELOG_de-DE.md src dist/build/WSystemEInvoice/
rm -f dist/WSystemEInvoice.zip
(cd dist/build && zip -qr ../WSystemEInvoice.zip WSystemEInvoice)
rm -rf dist/build
sha256sum dist/WSystemEInvoice.zip
