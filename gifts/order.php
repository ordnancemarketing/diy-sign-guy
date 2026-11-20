<?php
// Christmas gift order handler (gifts page). Mail helpers mirror send-lead.php.
declare(strict_types=1);

const TO       = 'dean@diysignguy.com';
const FROM     = 'leads@diysignguy.com';
const MAX_FILE = 12582912; // 12 MB total uploads
const HELP     = '(314) 540-9472';
// Gift list. Prices live here on the server; the page cannot change them.
const GIFTS = [
    'lobby-sign'               => ['The Lobby Sign', 179],
    'shop-wall-sign'           => ['The Shop Wall Sign', 149],
    'business-starter-bundle'  => ['The Business Starter Bundle', 299],
    'pop-up-banner-stand'      => ['The Pop-Up Banner Stand', 219],
    'yard-sign-10-pack'        => ['Yard Sign 10-Pack', 149],
    'banner'                   => ['The Banner', 99],
    'truck-door-magnets'       => ['Truck Door Magnets', 89],
    'large-truck-door-magnets' => ['Large Truck Door Magnets', 119],
    'contractor-pack'          => ['The Contractor Pack', 239],
    'office-pack'              => ['The Office Pack', 289],
];
const DECAL_ADDON = 49;
// Buyers pay through this PayPal.Me link (amount is added on the end) unless the page sends a completed card checkout.
const PAYPAL_ME = 'https://www.paypal.com/paypalme/fortune5sign/';

function smtp_config(): ?array {
    $path = dirname(__DIR__, 2) . '/private/smtp-config.php';
    if (!is_file($path) || !is_readable($path)) return null;
    $c = @include $path;
    if (!is_array($c) || empty($c['host']) || empty($c['user']) || empty($c['pass'])) return null;
    $c['port'] = (int)($c['port'] ?? 465);
    return $c;
}
function smtp_send(array $c, string $to, string $subject, string $body, string $headers): bool {
    $fp = @stream_socket_client('ssl://' . $c['host'] . ':' . $c['port'], $en, $es, 15);
    if (!$fp) return false;
    stream_set_timeout($fp, 20);
    $read = function () use ($fp): string {
        $out = '';
        while (($line = fgets($fp, 1024)) !== false) { $out .= $line; if (isset($line[3]) && $line[3] === ' ') break; }
        return $out;
    };
    $cmd = function (string $line, string $expect) use ($fp, $read): bool {
        fwrite($fp, $line . "\r\n");
        return strncmp($read(), $expect, strlen($expect)) === 0;
    };
    $ok = strncmp($read(), '220', 3) === 0
        && $cmd('EHLO diysignguy.com', '250')
        && $cmd('AUTH LOGIN', '334')
        && $cmd(base64_encode($c['user']), '334')
        && $cmd(base64_encode($c['pass']), '235')
        && $cmd('MAIL FROM:<' . $c['user'] . '>', '250')
        && $cmd('RCPT TO:<' . $to . '>', '250')
        && $cmd('DATA', '354');
    if ($ok) {
        $domain = substr((string)strrchr($c['user'], '@'), 1);
        $msg = 'Date: ' . date('r') . "\r\n"
             . 'Message-ID: <' . bin2hex(random_bytes(16)) . '@' . $domain . ">\r\n"
             . 'To: ' . $to . "\r\n"
             . 'Subject: ' . $subject . "\r\n"
             . $headers . "\r\n\r\n" . $body;
        $msg = preg_replace('/\r\n|\r|\n/', "\r\n", $msg);
        $msg = preg_replace('/^\./m', '..', $msg);
        fwrite($fp, $msg . "\r\n");
        $ok = $cmd('.', '250');
    }
    @fwrite($fp, "QUIT\r\n");
    @fclose($fp);
    return $ok;
}
// $fromName is the display name; the address is the SMTP mailbox when SMTP is used, else FROM.
function send_mail(string $to, string $subject, string $body, string $fromName, string $extraHeaders): bool {
    $c = smtp_config();
    if ($c !== null) {
        $h = 'From: ' . $fromName . ' <' . $c['user'] . ">\r\n" . $extraHeaders;
        if (smtp_send($c, $to, $subject, $body, $h)) return true;
    }
    return mail($to, $subject, $body, 'From: ' . $fromName . ' <' . FROM . ">\r\n" . $extraHeaders, '-f' . FROM);
}

function out(int $code, array $data): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data);
    exit;
}
function clean(string $s, int $max = 200): string {
    return mb_substr(trim(str_replace(["\r", "\n", "\0"], ' ', $s)), 0, $max);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { header('Allow: POST'); out(405, ['ok' => false, 'error' => 'Orders only.']); }
if (!empty($_POST['_honey'])) out(200, ['ok' => true, 'order' => 'G000000-0000', 'coupon' => '']);

$key = (string)($_POST['gift'] ?? '');
if (!isset(GIFTS[$key])) out(400, ['ok' => false, 'error' => 'Please choose a gift.']);
[$giftName, $price] = GIFTS[$key];
$decal = !empty($_POST['decal_addon']);
$total = $price + ($decal ? DECAL_ADDON : 0);

$f = [];
foreach (['business_name' => 120, 'sign_text' => 200, 'buyer_name' => 100, 'email' => 150, 'phone' => 40,
          'ship_name' => 100, 'ship_address' => 150, 'ship_address2' => 100, 'ship_city' => 80, 'ship_state' => 30, 'ship_zip' => 15,
          'notes' => 1000, 'paypal_order_id' => 60, 'paypal_payer_email' => 150, 'paypal_amount' => 20, 'paypal_status' => 30] as $k => $max) {
    $f[$k] = clean((string)($_POST[$k] ?? ''), $max);
}
$needHelp = !empty($_POST['need_help']);
$lowRes   = !empty($_POST['low_res']);
$approved = !empty($_POST['approved']);

if ($f['business_name'] === '') out(400, ['ok' => false, 'error' => 'Please enter the business name.']);
if ($f['buyer_name'] === '')    out(400, ['ok' => false, 'error' => 'Please enter your name.']);
if (!filter_var($f['email'], FILTER_VALIDATE_EMAIL)) out(400, ['ok' => false, 'error' => 'Please enter a working email address.']);
foreach (['ship_name', 'ship_address', 'ship_city', 'ship_state', 'ship_zip'] as $k) {
    if ($f[$k] === '') out(400, ['ok' => false, 'error' => 'Please fill in the full shipping address.']);
}
if (!$approved && !$needHelp) out(400, ['ok' => false, 'error' => 'Please approve the layout or ask for design help.']);

// Uploads: the logo (optional) and the on-screen proof picture.
$files = [];
$sizeTotal = 0;
foreach (['logo' => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'heic', 'pdf', 'svg', 'ai', 'eps'], 'proof' => ['png']] as $field => $okExt) {
    if (!isset($_FILES[$field]) || $_FILES[$field]['error'] === UPLOAD_ERR_NO_FILE) continue;
    $u = $_FILES[$field];
    if ($u['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($u['tmp_name'])) out(400, ['ok' => false, 'error' => 'A file could not be uploaded. Please keep it under 10 MB.']);
    $sizeTotal += (int)$u['size'];
    if ($sizeTotal > MAX_FILE) out(413, ['ok' => false, 'error' => 'That file is too large. Please keep it under 10 MB.']);
    $ext = strtolower(pathinfo((string)$u['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $okExt, true)) out(400, ['ok' => false, 'error' => 'Please upload a picture file (JPG or PNG works best).']);
    $files[$field] = [$field . '.' . $ext, file_get_contents($u['tmp_name'])];
}

$orderId = 'G' . date('ymd') . '-' . strtoupper(bin2hex(random_bytes(2)));
$coupon  = 'KIT100-' . strtoupper(bin2hex(random_bytes(3)));
$couponExpires = date('F j, Y', strtotime('+1 year'));   // coupon is good for one year, LED sign kits only
$viaLink = $f['paypal_order_id'] === '';   // no on-page checkout: buyer pays through the PayPal.Me link
$payUrl  = PAYPAL_ME . $total;
$paidOk  = !$viaLink && abs((float)$f['paypal_amount'] - (float)$total) < 0.01 && strtoupper($f['paypal_status']) === 'COMPLETED';

$flags = [];
if ($needHelp) $flags[] = 'CUSTOMER ASKED FOR DESIGN HELP';
if ($lowRes)   $flags[] = 'LOGO IS LOW RESOLUTION';
if (!isset($files['logo'])) $flags[] = 'NO LOGO UPLOADED (name only)';
if (!$viaLink && !$paidOk) $flags[] = 'PAYMENT AMOUNT/STATUS DOES NOT MATCH - CHECK PAYPAL BEFORE PRINTING';

$record = ['order' => $orderId, 'coupon' => $coupon, 'coupon_expires' => $couponExpires, 'time' => date('c'), 'gift' => $key, 'gift_name' => $giftName,
           'price' => $price, 'decal_addon' => $decal, 'total' => $total, 'approved' => $approved, 'flags' => $flags,
           'ip' => clean((string)($_SERVER['REMOTE_ADDR'] ?? ''))] + $f;

// Keep a copy outside the public site (best effort; the email to Dean is the record of last resort).
$dir = dirname(__DIR__, 2) . '/private/gift-orders/' . $orderId;
if (@mkdir($dir, 0700, true) || is_dir($dir)) {
    @file_put_contents($dir . '/order.json', json_encode($record, JSON_PRETTY_PRINT));
    foreach ($files as [$nm, $data]) @file_put_contents($dir . '/' . $nm, $data);
    @file_put_contents(dirname($dir) . '/coupons.csv', $coupon . ',' . $orderId . ',' . date('Y-m-d') . ',' . date('Y-m-d', strtotime('+1 year')) . ',' . str_replace(',', ' ', $f['email']) . "\n", FILE_APPEND | LOCK_EX);
}

$rows = '';
$show = ['Order' => $orderId, 'Gift' => $giftName . ' ($' . $price . ')', 'Decal pack add-on' => $decal ? 'YES (+$' . DECAL_ADDON . ')' : 'no',
         'Total' => '$' . $total, 'Payment' => $viaLink ? 'NOT PAID YET. Buyer was sent to PayPal to pay $' . $total . '. Look for $' . $total . ' from ' . $f['buyer_name'] . ' in PayPal before this prints.'
                                                    : $f['paypal_order_id'] . ' / ' . $f['paypal_status'] . ' / $' . $f['paypal_amount'] . ' / ' . $f['paypal_payer_email'],
         'Coupon code sent' => $coupon . ' (expires ' . $couponExpires . ', LED sign kits only)', 'Business name' => $f['business_name'], 'Extra text for the sign' => $f['sign_text'],
         'Layout approved on page' => $approved ? 'YES' : 'no', 'Buyer' => $f['buyer_name'] . ' / ' . $f['email'] . ' / ' . $f['phone'],
         'Ship to' => $f['ship_name'] . ', ' . $f['ship_address'] . ' ' . $f['ship_address2'] . ', ' . $f['ship_city'] . ', ' . $f['ship_state'] . ' ' . $f['ship_zip'],
         'Notes' => $f['notes']];
foreach ($show as $label => $v) {
    $rows .= '<tr><th align="left" valign="top" style="padding:6px 10px;background:#f3f3f3;border:1px solid #ddd">' . htmlspecialchars($label)
           . '</th><td style="padding:6px 10px;border:1px solid #ddd">' . nl2br(htmlspecialchars((string)$v)) . '</td></tr>';
}
$flagHtml = $flags ? '<p style="background:#fde8e8;border:1px solid #c00;padding:10px;font-weight:bold">NEEDS DEAN: ' . htmlspecialchars(implode(' | ', $flags)) . '</p>'
                   : '<p style="background:#e8f7e8;border:1px solid #2a2;padding:10px">No design issues flagged.' . ($viaLink ? ' Prints once the PayPal payment shows up.' : ' Ready for the 6 p.m. Signs365 run.') . '</p>';
$subject = ($flags ? 'NEEDS DEAN - ' : '') . 'GIFT ORDER ' . $orderId . ' - ' . $giftName . ' - $' . $total . ($viaLink ? ' - AWAITING PAYPAL PAYMENT' : '');
$html = '<html><body style="font-family:sans-serif"><h2>' . htmlspecialchars($subject) . '</h2>' . $flagHtml
      . '<table cellspacing="0" style="border-collapse:collapse">' . $rows . '</table></body></html>';

$b = 'b' . bin2hex(random_bytes(12));
$headers = 'Reply-To: ' . $f['email'] . "\r\nMIME-Version: 1.0\r\nContent-Type: multipart/mixed; boundary=\"$b\"";
$body = "--$b\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($html)) . "\r\n";
foreach ($files as [$nm, $data]) {
    $nm = $orderId . '-' . $nm;
    $body .= "--$b\r\nContent-Type: application/octet-stream; name=\"$nm\"\r\nContent-Transfer-Encoding: base64\r\n"
           . "Content-Disposition: attachment; filename=\"$nm\"\r\n\r\n" . chunk_split(base64_encode($data)) . "\r\n";
}
$body .= "--$b--";
$sent = send_mail(TO, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, 'DIY Sign Guy Gifts', $headers);
if (!$sent && !is_file($dir . '/order.json')) out(500, ['ok' => false, 'error' => 'Our system had a problem saving the order. Please call Dean at ' . HELP . '.']);

// Confirmation to the buyer: fixed wording plus the order number, gift name and coupon code.
$next = ($needHelp || $lowRes || !isset($files['logo']))
    ? "Dean will email you a proof to approve before anything prints, usually within one business day."
    : "Your approved layout goes to print as soon as your payment arrives. You will get tracking by email when it ships.";
$payLine = $viaLink ? "TO FINISH YOUR ORDER, PAY HERE: $payUrl\r\n(If you already paid, thank you. You can ignore this line.)\r\n\r\n" : '';
$txt = "Thank you for your order with DIY Sign Guy.\r\n\r\nOrder: $orderId\r\nGift: $giftName" . ($decal ? " + Logo Decal Pack" : "") . "\r\nTotal: \$$total\r\n\r\n$payLine$next\r\n\r\n"
     . "YOUR FREE GIFT: \$100 off any DIY LED sign kit at diysignguy.com.\r\nCoupon code: $coupon\r\nGood on LED sign kits only. Expires $couponExpires.\r\nPrint this email or forward it and tuck it in with the gift.\r\n\r\n"
     . "Questions? Call or text Dean at " . HELP . " or reply to this email.\r\n\r\nDIY Sign Guy\r\nhttps://diysignguy.com";
@send_mail($f['email'], '=?UTF-8?B?' . base64_encode('Your DIY Sign Guy gift order ' . $orderId) . '?=', $txt, 'DIY Sign Guy', 'Reply-To: ' . TO . "\r\nContent-Type: text/plain; charset=UTF-8");

out(200, ['ok' => true, 'order' => $orderId, 'coupon' => $coupon, 'coupon_expires' => $couponExpires, 'total' => $total, 'pay_url' => $viaLink ? $payUrl : null, 'proof_first' => ($needHelp || $lowRes || !isset($files['logo']))]);
