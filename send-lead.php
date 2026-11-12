<?php
// Website lead form handler. Recipients are fixed here; form fields cannot change them.
declare(strict_types=1);

const TO       = 'dean@diysignguy.com';
const FROM     = 'leads@diysignguy.com';
const MAX_FILE = 10485760; // 10 MB total attachments
const THANKS   = '/thank-you.html';
// Optional modes chosen by the hidden "_form" field. Unknown values fall back to a normal lead.
const MODES = [
    'order' => ['subject' => 'NEW ORDER - DIY Sign Guy', 'next' => '/office/thanks.html',
        'auto' => 'Thank you - your DIY Sign Guy order details were received. Dean will review and email your invoice. Your build begins once the invoice is paid.'],
    'agreement' => ['subject' => 'SIGNED AGREEMENT - DIY Sign Guy', 'next' => '/office/thanks.html',
        'auto' => 'Thank you - your signed DIY Sign Guy project agreement was received. Dean will confirm and send your invoice by email. Keep this message for your records.'],
];
$mode = MODES[(string)($_POST['_form'] ?? '')] ?? null;
$next = $mode['next'] ?? THANKS;

// ---- Mail sending -------------------------------------------------------------------
// If a private SMTP settings file exists OUTSIDE the public site (../private/smtp-config.php,
// never in this repo), mail is sent through that signed-in mailbox so it passes sender checks.
// If the file is missing, or SMTP fails for any reason, it falls back to PHP mail() so a lead is never lost.
// The settings file returns: ['host' => 'smtp.hostinger.com', 'port' => 465, 'user' => 'mailbox@domain', 'pass' => '...'].
function smtp_config(): ?array {
    $path = dirname(__DIR__) . '/private/smtp-config.php';
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
if (!empty($_POST['_honey'])) { header('Location: ' . $next, true, 303); exit; }

// General question form (contact page, right side): its own subject and thank-you page, no files.
$isGeneral = !$mode && (string)($_POST['_form'] ?? '') === 'general';
if ($isGeneral) { $next = '/thank-you-question.html'; $_FILES = []; }

// Junk screening for website leads and general questions (not for orders or signed agreements).
// Obvious junk is dropped quietly (they see the normal thank-you page); honest mistakes get a clear message.
function junk(string $next): void { header('Location: ' . $next, true, 303); exit; }
if (!$mode) {
    $vals = [];
    foreach ($_POST as $k => $v) {
        if (str_starts_with((string)$k, '_') || is_array($v)) continue;
        $v = trim((string)$v);
        if ($v !== '' && $k !== 'mailing_same_as_install') $vals[(string)$k] = $v;
    }
    $text = implode("\n", array_diff_key($vals, ['email' => 1]));
    // Filled out faster than a person can type (bots). _t = milliseconds from page load to submit, set by the form script.
    $t = (int)($_POST['_t'] ?? 0);
    if ($t > 0 && $t < 4000) junk($next);
    // Links, foreign alphabets, or the same long text pasted into several boxes = spam.
    if (preg_match('~https?://|www\.|\[url|<a\s~i', $text)) junk($next);
    if (preg_match('/[\p{Cyrillic}\p{Han}\p{Arabic}\p{Hangul}\p{Thai}]/u', $text)) junk($next);
    $long = array_filter(array_diff_key($vals, ['mailing_address' => 1]), fn($v) => mb_strlen($v) >= 12);
    if (count($long) !== count(array_unique(array_map('mb_strtolower', $long)))) junk($next);
    // Salesmen pitching SEO, websites or marketing.
    $pitch = '/\b(seo\b|search engine optimi[sz]|backlinks?|first page of google|google ranking|rank(ing)? higher|website (analysis|audit|report|redesign|design|development|traffic)|web (design|development|developer)|digital marketing|marketing (services|agency)|lead generation services|free of cost|guest post|virtual assistant|outsourc)/i';
    if (preg_match($pitch, $text)) {
        if ($isGeneral) junk($next);
        fail(400, 'This form is for sign quotes only. If you have a general question, please use the General Questions form on our contact page.');
    }
    // Name: letters only, first and last name for quotes.
    $nm = trim((string)($_POST['full_name'] ?? $_POST['name'] ?? ''));
    if ($nm === '' || preg_match('/\d/', $nm)) fail(400, 'Please enter your name.');
    if (!$isGeneral && !preg_match('/\pL+\S*\s+\pL/u', $nm)) fail(400, 'Please enter your first and last name.');
    if (!filter_var(trim((string)($_POST['email'] ?? '')), FILTER_VALIDATE_EMAIL)) fail(400, 'Please enter a working email address.');
    if (!$isGeneral) {
        // Phone: required on the contact page; when given anywhere it must be a real US number.
        $ph = preg_replace('/\D/', '', (string)($_POST['phone'] ?? ''));
        if (strlen($ph) === 11 && $ph[0] === '1') $ph = substr($ph, 1);
        $fromContact = (string)($_POST['_src'] ?? '') === 'contact';
        if ($ph === '' && $fromContact) fail(400, 'Please enter your phone number so Dean can reach you about your quote.');
        if ($ph !== '') {
            $fake = strlen($ph) !== 10 || !preg_match('/^[2-9]\d{2}[2-9]\d{6}$/', $ph)
                 || preg_match('/^(\d)\1+$/', $ph) || str_contains('01234567890 9876543210', $ph)
                 || substr($ph, 0, 3) === substr($ph, 3, 3)
                 || preg_match('/^\d{3}555/', $ph);
            if ($fake) fail(400, 'Please enter a real 10-digit US phone number.');
        }
        // ZIP: required on the contact page (its own box); must be a real-format US ZIP.
        if ($fromContact) {
            if (!preg_match('/^\d{5}(-\d{4})?$/', trim((string)($_POST['zip'] ?? ''))) || preg_match('/^0{5}|^(\d)\1{4}$/', trim((string)($_POST['zip'] ?? ''))))
                fail(400, 'Please enter the 5-digit ZIP code where your sign will go.');
        }
    }
}

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
$name    = clean((string)($_POST['full_name'] ?? $_POST['signature_full_name'] ?? $_POST['name'] ?? ''));
$page    = clean((string)($_SERVER['HTTP_REFERER'] ?? ''));
$subject = ($mode['subject'] ?? ($isGeneral ? 'General Inquiry - DIY Sign Guy' : 'New DIY Sign Guy project inquiry')) . ($name !== '' ? ' - ' . substr($name, 0, 80) : '');

$html = '<html><body style="font-family:sans-serif"><h2>' . htmlspecialchars($subject) . '</h2>'
      . '<table cellspacing="0" style="border-collapse:collapse">' . $rows . '</table>'
      . '<p style="color:#777;font-size:12px">Page: ' . htmlspecialchars($page) . '<br>Sent: ' . date('Y-m-d H:i:s T') . '<br>IP: ' . htmlspecialchars(clean((string)($_SERVER['REMOTE_ADDR'] ?? ''))) . '</p></body></html>';

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
$headers  = '';
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
if (!send_mail(TO, $encSubject, $body, 'DIY Sign Guy Website', $headers)) {
    fail(500, 'Our mail server had a problem sending your message.');
}
// Plain-text confirmation to the submitter (fixed text only; nothing from the form is echoed back).
if ($mode && $replyTo !== '') {
    @send_mail($replyTo, '=?UTF-8?B?' . base64_encode('We received your ' . ($_POST['_form'] === 'order' ? 'order details' : 'signed agreement') . ' - DIY Sign Guy') . '?=',
        $mode['auto'] . "\r\n\r\nDIY Sign Guy\r\ndean@diysignguy.com",
        'DIY Sign Guy', 'Reply-To: ' . TO . "\r\nContent-Type: text/plain; charset=UTF-8");
}
header('Location: ' . $next, true, 303);
exit;
