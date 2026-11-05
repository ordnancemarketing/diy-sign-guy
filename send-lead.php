<?php
// Website lead form handler. Recipients are fixed here; form fields cannot change them.
declare(strict_types=1);

const TO       = 'dean@diysignguy.com';
const CC       = 'meadow-diy@mail.grokbot.com';
const FROM     = 'leads@diysignguy.com';
const MAX_FILE = 10485760; // 10 MB total attachments
const THANKS   = '/thank-you.html';

function fail(int $code, string $msg): void {
    http_response_code($code);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><meta name="robots" content="noindex"><title>Message not sent</title>'
       . '<body style="font-family:sans-serif;max-width:560px;margin:60px auto;padding:0 20px">'
       . '<h1>Sorry, your message was not sent.</h1><p>' . htmlspecialchars($msg) . '</p>'
       . '<p>Please email us directly at <a href="mailto:dean@diysignguy.com">dean@diysignguy.com</a>.</p>'
       . '<p><a href="javascript:history.back()">Go back</a></p></body>';
    exit;
}
function clean(string $s): string {
    return trim(str_replace(["\r", "\n", "\0"], ' ', $s));
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { header('Allow: POST'); fail(405, 'This address only accepts form submissions.'); }
// Honeypot filled = bot. Pretend success so bots learn nothing.
if (!empty($_POST['_honey'])) { header('Location: ' . THANKS, true, 303); exit; }

$rows = '';
$fields = 0;
foreach ($_POST as $k => $v) {
    if (str_starts_with((string)$k, '_')) continue;
    $v = is_array($v) ? implode(', ', array_map('strval', $v)) : (string)$v;
    $v = trim(str_replace("\0", '', $v));
    if ($v === '') continue;
    $fields++;
    $label = ucwords(str_replace(['_', '-'], ' ', clean((string)$k)));
    $rows .= '<tr><th align="left" valign="top" style="padding:6px 10px;background:#f3f3f3;border:1px solid #ddd">'
           . htmlspecialchars(substr($label, 0, 100)) . '</th><td style="padding:6px 10px;border:1px solid #ddd">'
           . nl2br(htmlspecialchars(substr($v, 0, 5000))) . '</td></tr>';
}
if ($fields === 0) fail(400, 'The form was empty.');

$email   = clean((string)($_POST['email'] ?? ''));
$replyTo = filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
$name    = clean((string)($_POST['full_name'] ?? $_POST['name'] ?? ''));
$page    = clean((string)($_SERVER['HTTP_REFERER'] ?? ''));
$subject = 'New DIY Sign Guy project inquiry' . ($name !== '' ? ' - ' . substr($name, 0, 80) : '');

$html = '<html><body style="font-family:sans-serif"><h2>' . htmlspecialchars($subject) . '</h2>'
      . '<table cellspacing="0" style="border-collapse:collapse">' . $rows . '</table>'
      . '<p style="color:#777;font-size:12px">Page: ' . htmlspecialchars($page) . '<br>Sent: ' . date('Y-m-d H:i T') . '</p></body></html>';

// Attachments
$files = [];
$total = 0;
foreach ($_FILES as $f) {
    $list = is_array($f['name']) ? array_keys($f['name']) : [null];
    foreach ($list as $i) {
        $err  = $i === null ? $f['error'] : $f['error'][$i];
        if ($err === UPLOAD_ERR_NO_FILE) continue;
        if ($err !== UPLOAD_ERR_OK) fail(400, 'A file could not be uploaded. Please keep files under 10 MB total.');
        $tmp  = $i === null ? $f['tmp_name'] : $f['tmp_name'][$i];
        $size = (int)($i === null ? $f['size'] : $f['size'][$i]);
        $nm   = $i === null ? $f['name'] : $f['name'][$i];
        $total += $size;
        if ($total > MAX_FILE) fail(413, 'Your files are too large. Please keep the total under 10 MB.');
        if (!is_uploaded_file($tmp)) continue;
        $nm = preg_replace('/[^A-Za-z0-9._-]/', '_', basename((string)$nm)) ?: 'file';
        $files[] = [$nm, file_get_contents($tmp)];
    }
}

$b = 'b' . bin2hex(random_bytes(12));
$headers  = 'From: DIY Sign Guy Website <' . FROM . ">\r\n";
if ($replyTo !== '') $headers .= 'Reply-To: ' . $replyTo . "\r\n";
$headers .= "MIME-Version: 1.0\r\nContent-Type: multipart/mixed; boundary=\"$b\"";

$body  = "--$b\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
       . chunk_split(base64_encode($html)) . "\r\n";
foreach ($files as [$nm, $data]) {
    $body .= "--$b\r\nContent-Type: application/octet-stream; name=\"$nm\"\r\n"
           . "Content-Transfer-Encoding: base64\r\nContent-Disposition: attachment; filename=\"$nm\"\r\n\r\n"
           . chunk_split(base64_encode($data)) . "\r\n";
}
$body .= "--$b--";

$encSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
@mail(CC, $encSubject, $body, $headers, '-f' . FROM); // separate copy to bot inbox
if (!mail(TO, $encSubject, $body, $headers, '-f' . FROM)) {
    fail(500, 'Our mail server had a problem sending your message.');
}
header('Location: ' . THANKS, true, 303);
exit;
