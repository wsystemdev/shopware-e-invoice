<?php
/**
 * End-to-end test on a REAL Shopware 6 instance: creates products and customers, places real orders
 * through the Store API, completes them through the Admin API and checks that the plugin created
 * valid e-invoices through the live api.wsystem.dev. Run from the Shopware project root:
 *   APP_URL=http://127.0.0.1:8000 php custom/plugins/WSystemEInvoice/tests/e2e/e2e.php
 * Needs: plugin installed + active, admin user (admin/shopware), DB access via mysql CLI env (see below).
 * Makes live calls to api.wsystem.dev (free tier, shared 20/day/IP unless WSYSTEM_API_KEY is in env).
 */
$base = rtrim(getenv('APP_URL') ?: 'http://127.0.0.1:8000', '/');
$pass = 0; $fail = 0;
function ok(bool $c, string $m): void { global $pass, $fail; if ($c) { ++$pass; echo "PASS  $m\n"; } else { ++$fail; echo "FAIL  $m\n"; } }
function http(string $method, string $url, $body = null, array $headers = [], bool $raw = false): array {
    for ($try = 0; $try < 3; ++$try) {
        $ch = curl_init($url);
        $h = array_merge(['Accept: application/json', 'Content-Type: application/json'], $headers);
        curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $h, CURLOPT_TIMEOUT => 120, CURLOPT_HEADER => true]);
        if ($body !== null) { curl_setopt($ch, CURLOPT_POSTFIELDS, is_string($body) ? $body : json_encode($body)); }
        $out = curl_exec($ch);
        if ($out === false) { sleep(2); continue; }
        $hs = curl_getinfo($ch, CURLINFO_HEADER_SIZE); $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $hdr = substr($out, 0, $hs); $b = substr($out, $hs);
        return [$code, $raw ? $b : (json_decode($b, true) ?? []), $hdr, $b];
    }
    throw new RuntimeException("network error $url");
}
function db(string $sql): string { return trim((string) shell_exec('mysql -usw -psw -h127.0.0.1 shopware -N -e ' . escapeshellarg($sql) . ' 2>/dev/null')); }
function sw(string $cmd): void { shell_exec('bin/console ' . $cmd . ' 2>&1'); }

// --- admin token
[$c, $tok] = http('POST', "$base/api/oauth/token", ['grant_type' => 'password', 'client_id' => 'administration', 'scope' => 'write', 'username' => 'admin', 'password' => 'shopware']);
$auth = ['Authorization: Bearer ' . $tok['access_token']];
$admin = fn (string $m, string $p, $b = null) => http($m, "$base/api$p", $b, $auth);

// --- plugin config
$cfg = ['sellerName' => 'W System Test Shop', 'sellerStreet' => 'Hauptstr. 1', 'sellerPostcode' => '10115', 'sellerCity' => 'Berlin', 'sellerCountry' => 'DE',
    'sellerVatId' => 'DE123456789', 'sellerEmail' => 'shop@example.com', 'sellerPhone' => '+49 30 1234567', 'sellerContact' => 'Erika Mustermann',
    'sellerIban' => 'DE89370400440532013000', 'sellerBic' => 'COBADEFFXXX', 'paymentTerms' => 'Payable within 14 days without deduction.', 'trigger' => 'completed',
    'format' => 'xrechnung', 'apiKey' => getenv('WSYSTEM_API_KEY') ?: ''];
foreach ($cfg as $k => $v) { shell_exec('bin/console system:config:set ' . escapeshellarg('WSystemEInvoice.config.' . $k) . ' ' . escapeshellarg((string) $v) . ' 2>&1'); }
sw('cache:pool:clear cache.object');

// --- products
$sc = db("SELECT LOWER(HEX(id)) FROM sales_channel WHERE LOWER(HEX(type_id))='8a243080f92e4c719546314b577cf82b' LIMIT 1");
$key = db("SELECT access_key FROM sales_channel WHERE LOWER(HEX(id))='$sc'");
$cur = db("SELECT LOWER(HEX(id)) FROM currency WHERE iso_code='EUR'");
$tax19 = db("SELECT LOWER(HEX(id)) FROM tax WHERE tax_rate=19"); $tax7 = db("SELECT LOWER(HEX(id)) FROM tax WHERE tax_rate=7");
$suffix = (string) random_int(1000, 9999);
$prod = function (string $name, string $tax, float $gross) use ($admin, $cur, $sc, $suffix) {
    $id = bin2hex(random_bytes(16));
    [$c, $r] = $admin('POST', '/product', ['id' => $id, 'name' => $name, 'productNumber' => 'E2E-' . $suffix . '-' . substr($id, 0, 4), 'stock' => 100, 'taxId' => $tax, 'active' => true,
        'price' => [['currencyId' => $cur, 'gross' => $gross, 'net' => round($gross / (1 + ($tax ? 0 : 0)), 2), 'linked' => true]],
        'visibilities' => [['salesChannelId' => $sc, 'visibility' => 30]]]);
    if ($c >= 300) { echo "product create failed $c " . json_encode($r) . "\n"; exit(2); }
    return $id;
};
$p19 = $prod('Test Widget 19', $tax19, 29.99); $p7 = $prod('Test Book 7', $tax7, 19.90);
sw('dal:refresh:index >/dev/null');

// --- helpers on Store API
$de = db("SELECT LOWER(HEX(id)) FROM country WHERE iso='DE'"); $nl = db("SELECT LOWER(HEX(id)) FROM country WHERE iso='NL'");
$sal = db('SELECT LOWER(HEX(id)) FROM salutation LIMIT 1');
$prepay = db("SELECT LOWER(HEX(id)) FROM payment_method WHERE technical_name='payment_prepayment'");
$invoice = db("SELECT LOWER(HEX(id)) FROM payment_method WHERE technical_name='payment_invoicepayment'");
$store = function (string $m, string $p, $b = null, ?string $ctx = null) use ($base, $key) {
    $h = ["sw-access-key: $key"]; if ($ctx) { $h[] = "sw-context-token: $ctx"; }
    $r = http($m, "$base/store-api$p", $b, $h);
    return $r;
};
$order = function (array $customer, array $items, string $payment) use ($store, $de, $sal, $key, $base): array {
    [$c, $r, $hdr] = $store('POST', '/account/register', $customer + ['salutationId' => $sal, 'password' => 'E2e-Passw0rd!x', 'acceptedDataProtection' => true, 'storefrontUrl' => $base]);
    if ($c >= 300) { echo "register failed $c " . json_encode($r) . "\n"; exit(2); }
    preg_match('/sw-context-token:\s*(\S+)/i', $hdr, $m); $ctx = $m[1];
    [$c, $r, $hdr] = $store('POST', '/account/login', ['username' => $customer['email'], 'password' => 'E2e-Passw0rd!x'], $ctx);
    preg_match('/sw-context-token:\s*(\S+)/i', $hdr, $m); $ctx = $m[1] ?? $ctx;
    $store('PATCH', '/context', ['paymentMethodId' => $payment], $ctx);
    $li = array_map(fn ($i) => ['type' => 'product', 'referencedId' => $i[0], 'quantity' => $i[1]], $items);
    $store('POST', '/checkout/cart/line-item', ['items' => $li], $ctx);
    [$c, $r] = $store('POST', '/checkout/order', ['customerComment' => 'e2e'], $ctx);
    if ($c >= 300) { echo "order failed $c " . json_encode($r) . "\n"; exit(2); }
    return [$r['id'], $r['orderNumber']];
};
$addr = fn (string $country, ?string $company = null) => array_filter(['street' => 'Teststr. 1', 'zipcode' => '10115', 'city' => $country === $GLOBALS['de'] ? 'Berlin' : 'Amsterdam', 'countryId' => $country, 'company' => $company]);

// NL tax-free for business with VAT ID
$admin('PATCH', "/country/$nl", ['companyTax' => ['enabled' => true, 'currencyId' => $cur, 'amount' => 0], 'vatIdPattern' => '(NL)?[0-9]{9}B[0-9]{2}', 'checkVatIdPattern' => false, 'vatIdRequired' => false]);
$admin('POST', '/_action/sales-channel-assign-country', []); // no-op safety
db("INSERT IGNORE INTO sales_channel_country (sales_channel_id, country_id) VALUES (UNHEX('$sc'), UNHEX('$nl'))");

$stamp = (string) random_int(10000, 99999);
$scn = [];
// A: German consumer, 19% + 7%, prepayment (unpaid)
$scn['A'] = $order(['accountType' => 'private', 'firstName' => 'Anna', 'lastName' => 'Kunde', 'email' => "a$stamp@example.com", 'billingAddress' => $addr($de)], [[$p19, 3], [$p7, 1]], $prepay);
// B: German business with VAT ID, invoice payment, Leitweg-ID on customer custom field set after registration
$scn['B'] = $order(['accountType' => 'business', 'company' => 'Stadtwerke Beispiel AöR', 'vatIds' => ['DE811569869'], 'firstName' => 'Bernd', 'lastName' => 'Einkauf', 'email' => "b$stamp@example.com", 'billingAddress' => $addr($de, 'Stadtwerke Beispiel AöR')], [[$p19, 2]], $invoice);
db("UPDATE customer SET custom_fields=JSON_OBJECT('wsystem_einvoice_buyer_reference','04011000-1234512345-35') WHERE email='b$stamp@example.com'");
// C: NL business, tax free (reverse charge)
$scn['C'] = $order(['accountType' => 'business', 'company' => 'Voorbeeld B.V.', 'vatIds' => ['NL123456782B01'], 'firstName' => 'Jan', 'lastName' => 'Jansen', 'email' => "c$stamp@example.com", 'billingAddress' => $addr($nl, 'Voorbeeld B.V.')], [[$p19, 4]], $invoice);

// --- complete each order through the Admin API (this fires the auto-create subscriber)
$transition = function (string $oid, string $action) use ($admin) { return $admin('POST', "/_action/order/$oid/state/$action", []); };
foreach ($scn as $k => [$oid, $num]) {
    [$c] = $transition($oid, 'process'); ok($c < 300, "$k order $num: process transition");
    $txid = db("SELECT LOWER(HEX(id)) FROM order_transaction WHERE order_id=UNHEX('$oid') LIMIT 1");
    if ($k !== 'A') { [$c] = $admin('POST', "/_action/order_transaction/$txid/state/paid", []); }
    [$c] = $transition($oid, 'complete'); ok($c < 300, "$k order $num: complete transition");
}

foreach ($scn as $k => [$oid, $num]) {
    [$c, $st] = $admin('GET', "/_action/wsystem-einvoice/order/$oid/status");
    if (!($st['exists'] ?? false)) { // quota or transient error on box: retry once manually
        [$c, $gen] = $admin('POST', "/_action/wsystem-einvoice/order/$oid/generate"); [$c, $st] = $admin('GET', "/_action/wsystem-einvoice/order/$oid/status");
    }
    ok(($st['exists'] ?? false) === true, "$k order $num: e-invoice stored" . (($st['lastError'] ?? '') ? ' (' . $st['lastError'] . ')' : ''));
    ok(($st['valid'] ?? false) === true, "$k order $num: e-invoice valid per API" . (!empty($st['errors']) ? ' ' . json_encode($st['errors']) : ''));
    ok(($st['mismatch'] ?? 'x') === '', "$k order $num: totals match the order total" . (($st['mismatch'] ?? '') ? ' - ' . $st['mismatch'] : ''));
    [$c, , , $xml] = http('GET', "$base/api/_action/wsystem-einvoice/order/$oid/download", null, $auth);
    ok($c === 200 && str_contains($xml, 'urn:xeinkauf.de:kosit:xrechnung_3.0') && str_contains($xml, '<Invoice'), "$k order $num: XML download is XRechnung 3.0 UBL");
    file_put_contents("/tmp/e2e-$k.xml", $xml);
    // independent re-validation with the validate endpoint
    [$c, $v] = http('POST', 'https://api.wsystem.dev/einvoice/validate', json_encode(['xml' => $xml]), $cfg['apiKey'] ? ['X-API-Key: ' . $cfg['apiKey']] : []);
    ok($c === 200 && !empty($v['valid']), "$k order $num: independent POST /einvoice/validate says valid" . ($c !== 200 ? " (HTTP $c)" : ''));
    if ($k === 'A') { ok(str_contains($xml, '>19.00<') || str_contains($xml, '>19<'), 'A: 19% VAT present'); ok(str_contains($xml, '>7.00<') || str_contains($xml, '>7<'), 'A: 7% VAT present'); ok(str_contains($xml, 'DE89370400440532013000'), 'A: seller IBAN present'); }
    if ($k === 'B') { ok(str_contains($xml, '04011000-1234512345-35'), 'B: Leitweg-ID used as buyer reference'); ok(str_contains($xml, 'DE811569869'), 'B: buyer VAT ID present'); }
    if ($k === 'C') { ok(str_contains($xml, '>AE<'), 'C: intra-EU reverse charge category AE'); }
}

// --- D: trigger "placed" + attach to the order confirmation mail (needs an SMTP sink: MAIL_CAPTURE_GLOB=/tmp/mail-*.eml, MAILER_DSN pointing to it)
if (getenv('MAIL_CAPTURE_GLOB')) {
    foreach (glob(getenv('MAIL_CAPTURE_GLOB')) as $f) { unlink($f); }
    shell_exec('bin/console system:config:set WSystemEInvoice.config.trigger placed 2>&1');
    sw('cache:pool:clear cache.object');
    [$oidD, $numD] = $order(['accountType' => 'private', 'firstName' => 'Dora', 'lastName' => 'Mail', 'email' => "d$stamp@example.com", 'billingAddress' => $addr($de)], [[$p19, 1]], $prepay);
    $st = [];
    for ($i = 0; $i < 6; ++$i) {
        shell_exec('bin/console messenger:consume async low_priority --limit=30 --time-limit=10 2>&1');
        [$c, $st] = $admin('GET', "/_action/wsystem-einvoice/order/$oidD/status");
        if (glob(getenv('MAIL_CAPTURE_GLOB'))) { break; }
    }
    ok(($st['exists'] ?? false) && ($st['valid'] ?? false), "D order $numD: e-invoice created on order placed");
    $found = false;
    foreach (glob(getenv('MAIL_CAPTURE_GLOB')) as $f) {
        $eml = file_get_contents($f);
        if (stripos($eml, "d$stamp@example.com") !== false && preg_match('/filename="?e-invoice-[^"\s;]+\.xml/i', $eml)) { $found = true; }
    }
    ok($found, "D order $numD: order confirmation email carries the e-invoice XML attachment");
}

// --- E: ZUGFeRD PDF + credit notes (1.1.0). Both options are OFF by default; this section turns them on.
// E1: order paid -> ZUGFeRD PDF; order cancelled -> ONE credit note; the payment refunded afterwards must not create a second one.
// E2: paid order, payment refunded (no Shopify refund record) -> one credit note. Mail parts need MAIL_CAPTURE_GLOB (see D).
foreach (['trigger' => 'paid', 'zugferd' => 'true', 'creditNotes' => 'true', 'attachCreditNote' => 'true', 'attachEmail' => 'true', 'attachPaid' => 'true'] as $k => $v) {
    shell_exec('bin/console system:config:set ' . escapeshellarg('WSystemEInvoice.config.' . $k) . ' ' . escapeshellarg($v) . ' 2>&1');
}
sw('cache:pool:clear cache.object');
$mails = fn () => getenv('MAIL_CAPTURE_GLOB') ? (glob(getenv('MAIL_CAPTURE_GLOB')) ?: []) : [];
$mailWith = function (string $to, string $fileRe) use ($mails) {
    foreach ($mails() as $f) { $eml = file_get_contents($f); if (stripos($eml, $to) !== false && preg_match($fileRe, $eml)) { return true; } }
    return false;
};
$drain = function () { for ($i = 0; $i < 2; ++$i) { shell_exec('bin/console messenger:consume async low_priority --limit=30 --time-limit=8 2>&1'); } };
$cnState = function (string $oid) use ($admin) { [$c, $l] = $admin('GET', "/_action/wsystem-einvoice/order/$oid/credit-notes"); return is_array($l) ? $l : []; };
$paidOrder = function (string $tag) use ($order, $addr, $de, $p19, $p7, $prepay, $stamp, $transition, $admin) {
    [$oid, $num] = $order(['accountType' => 'private', 'firstName' => 'Erika', 'lastName' => "Gut$tag", 'email' => strtolower($tag) . "$stamp@example.com", 'billingAddress' => $addr($de)], [[$p19, 2], [$p7, 1]], $prepay);
    $transition($oid, 'process');
    $txid = db("SELECT LOWER(HEX(id)) FROM order_transaction WHERE order_id=UNHEX('$oid') LIMIT 1");
    $admin('POST', "/_action/order_transaction/$txid/state/paid", []);
    return [$oid, $num, $txid, strtolower($tag) . "$stamp@example.com"];
};
// state machine names the plugin listens to (checked on the live DB)
ok(db("SELECT COUNT(*) FROM state_machine_transition t JOIN state_machine m ON m.id=t.state_machine_id JOIN state_machine_state s ON s.id=t.to_state_id WHERE m.technical_name='order.state' AND s.technical_name='cancelled'") > 0, 'E: order.state has a transition into "cancelled"');
ok(db("SELECT COUNT(*) FROM state_machine_transition t JOIN state_machine m ON m.id=t.state_machine_id JOIN state_machine_state s ON s.id=t.to_state_id WHERE m.technical_name='order_transaction.state' AND s.technical_name='refunded'") > 0, 'E: order_transaction.state has a transition into "refunded"');

// E1
foreach ($mails() as $f) { @unlink($f); }
[$oid1, $num1, $tx1, $mail1] = $paidOrder('E1');
$drain();
[$c, $st] = $admin('GET', "/_action/wsystem-einvoice/order/$oid1/status");
ok(($st['exists'] ?? false) && ($st['valid'] ?? false) && ($st['format'] ?? '') === 'zugferd', "E1 order $num1: valid ZUGFeRD e-invoice created on paid" . (($st['lastError'] ?? '') ? ' (' . $st['lastError'] . ')' : ''));
[$c, , , $pdf] = http('GET', "$base/api/_action/wsystem-einvoice/order/$oid1/download-pdf", null, $auth);
ok($c === 200 && str_starts_with($pdf, '%PDF') && str_contains($pdf, 'factur-x.xml'), "E1 order $num1: PDF download is a PDF with embedded factur-x.xml");
file_put_contents('/tmp/e2e-E1.pdf', $pdf);
if (getenv('MAIL_CAPTURE_GLOB')) { ok($mailWith($mail1, '/filename="?e-invoice-[^"\s;]+\.pdf/i'), "E1 order $num1: payment mail carries the ZUGFeRD PDF attachment"); }
ok($cnState($oid1) === [], 'E1: no credit note before the cancellation');
foreach ($mails() as $f) { @unlink($f); }
[$c] = $transition($oid1, 'cancel'); ok($c < 300, "E1 order $num1: cancel transition");
$drain();
$cn = $cnState($oid1);
ok(count($cn) === 1 && ($cn[0]['valid'] ?? false) === true, "E1 order $num1: exactly one valid credit note after cancel" . (isset($cn[0]['lastError']) && $cn[0]['lastError'] !== '' ? ' (' . $cn[0]['lastError'] . ')' : ''));
ok(str_starts_with((string) ($cn[0]['number'] ?? ''), 'CN-') && ($cn[0]['original'] ?? '') === ($st['number'] ?? 'x'), 'E1: credit note references the original invoice number');
[$c, , , $x] = http('GET', "$base/api/_action/wsystem-einvoice/order/$oid1/credit-notes/0/download", null, $auth);
ok($c === 200 && str_contains($x, 'CrossIndustryInvoice') && str_contains($x, '>381<') && str_contains($x, (string) ($st['number'] ?? '?')), 'E1: credit note XML is CII type 381 with the original invoice referenced');
if (getenv('MAIL_CAPTURE_GLOB')) { ok($mailWith($mail1, '/filename="?credit-note-CN-[^"\s;]+\.pdf/i'), 'E1: cancellation mail carries the credit note PDF (credit note existed before the mail was built)'); }
[$c] = $admin('POST', "/_action/order_transaction/$tx1/state/refund", []); ok($c < 300, 'E1: payment refunded after the cancellation');
$drain();
ok(count($cnState($oid1)) === 1, 'E1: still exactly one credit note after cancel + refund');

// E2
foreach ($mails() as $f) { @unlink($f); }
[$oid2, $num2, $tx2, $mail2] = $paidOrder('E2');
$drain();
[$c, $st2] = $admin('GET', "/_action/wsystem-einvoice/order/$oid2/status");
ok(($st2['exists'] ?? false) && ($st2['valid'] ?? false), "E2 order $num2: e-invoice created on paid");
foreach ($mails() as $f) { @unlink($f); }
[$c] = $admin('POST', "/_action/order_transaction/$tx2/state/refund", []); ok($c < 300, "E2 order $num2: refund transition (payment refunded)");
$drain();
$cn2 = $cnState($oid2);
ok(count($cn2) === 1 && ($cn2[0]['valid'] ?? false) === true, "E2 order $num2: one valid credit note after the refund" . (isset($cn2[0]['lastError']) && $cn2[0]['lastError'] !== '' ? ' (' . $cn2[0]['lastError'] . ')' : ''));
if (getenv('MAIL_CAPTURE_GLOB')) { ok($mailWith($mail2, '/filename="?credit-note-CN-[^"\s;]+\.pdf/i'), 'E2: refund mail carries the credit note PDF'); }
// replay: the same transition again must not add a note
$admin('POST', "/_action/order_transaction/$tx2/state/refund", []); $drain();
ok(count($cnState($oid2)) === 1, 'E2: no second credit note on replay');
// E3: partial Shopware refund (order_transaction_capture_refund with a position): credit note for the refunded position only.
foreach ($mails() as $f) { @unlink($f); }
[$oid3, $num3, $tx3, $mail3] = $paidOrder('E3');
$drain();
$li = db("SELECT LOWER(HEX(id)) FROM order_line_item WHERE order_id=UNHEX('$oid3') AND label='Test Widget 19' LIMIT 1");
$calc = fn (float $gross, float $tax, int $qty) => ['unitPrice' => round($gross / $qty, 2), 'quantity' => $qty, 'totalPrice' => $gross, 'calculatedTaxes' => [['tax' => $tax, 'taxRate' => 19, 'price' => $gross]], 'taxRules' => [['taxRate' => 19, 'percentage' => 100]]];
$cap = bin2hex(random_bytes(16)); $ref = bin2hex(random_bytes(16));
$openState = fn (string $machine, string $name = 'open') => db("SELECT LOWER(HEX(s.id)) FROM state_machine_state s JOIN state_machine m ON m.id=s.state_machine_id WHERE m.technical_name='$machine' AND s.technical_name='$name'");
[$c1, $r1] = $admin('POST', '/order-transaction-capture', ['id' => $cap, 'orderTransactionId' => $tx3, 'stateId' => $openState('order_transaction_capture.state', 'pending'), 'externalReference' => 'e2e-cap', 'amount' => $calc(59.98, 9.58, 2)]);
[$c2, $r2] = $admin('POST', '/order-transaction-capture-refund', ['id' => $ref, 'captureId' => $cap, 'stateId' => $openState('order_transaction_capture_refund.state'), 'externalReference' => 'e2e-ref', 'amount' => $calc(29.99, 4.79, 1),
    'positions' => [['orderLineItemId' => $li, 'quantity' => 1, 'amount' => $calc(29.99, 4.79, 1), 'externalReference' => 'e2e-pos']]]);
ok($c1 < 300 && $c2 < 300, "E3 order $num3: capture + refund records created" . ($c1 >= 300 ? ' ' . json_encode($r1) : '') . ($c2 >= 300 ? ' ' . json_encode($r2) : ''));
foreach ($mails() as $f) { @unlink($f); }
foreach (['process', 'complete'] as $act) { [$c] = $admin('POST', "/_action/state-machine/order_transaction_capture_refund/$ref/state/$act", []); }
$drain();
$cn3 = $cnState($oid3);
ok(count($cn3) === 1 && ($cn3[0]['valid'] ?? false) === true && ($cn3[0]['key'] ?? '') === "refund-$ref", "E3 order $num3: one valid credit note for the refund record" . (isset($cn3[0]['lastError']) && $cn3[0]['lastError'] !== '' ? ' (' . $cn3[0]['lastError'] . ')' : ''));
[$c, , , $x3] = http('GET', "$base/api/_action/wsystem-einvoice/order/$oid3/credit-notes/0/download", null, $auth);
ok($c === 200 && str_contains($x3, '>381<') && str_contains($x3, '>25.20<'), 'E3: credit note covers only the refunded position (net 25.20)');
// the payment state turning "refunded" afterwards must not add a whole-order credit note (refund records exist)
$admin('POST', "/_action/order_transaction/$tx3/state/refund", []); $drain();
ok(count($cnState($oid3)) === 1, 'E3: still one credit note after the payment state became refunded');

// leave the shop config as the other sections expect it
foreach (['zugferd' => 'false', 'creditNotes' => 'false', 'trigger' => 'completed'] as $k => $v) { shell_exec('bin/console system:config:set ' . escapeshellarg('WSystemEInvoice.config.' . $k) . ' ' . escapeshellarg($v) . ' 2>&1'); }
sw('cache:pool:clear cache.object');

echo "\nRESULT: $pass passed, $fail failed\n";
exit($fail ? 1 : 0);
