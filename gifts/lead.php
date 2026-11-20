<?php
// "Remind me" sign-up on the gifts page: saves the email to a private list and tells Dean.
declare(strict_types=1);
const TO   = 'dean@diysignguy.com';
const FROM = 'leads@diysignguy.com';

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

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { header('Allow: POST'); out(405, ['ok' => false, 'error' => 'Sign-ups only.']); }
if (!empty($_POST['_honey'])) out(200, ['ok' => true]);

$email = clean((string)($_POST['email'] ?? ''), 150);
$name  = clean((string)($_POST['name'] ?? ''), 80);
$gift  = clean((string)($_POST['gift'] ?? ''), 60);
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) out(422, ['ok' => false, 'error' => 'Please enter a valid email address.']);

$saved = false;
$dir = dirname(__DIR__, 2) . '/private/gift-orders';
if (@mkdir($dir, 0700, true) || is_dir($dir)) {
    $list = $dir . '/leads.csv';
    $isNew = !is_file($list);
    if ($fh = @fopen($list, 'ab')) {
        if (flock($fh, LOCK_EX)) {
            if ($isNew) fputcsv($fh, ['date', 'email', 'name', 'looking_at', 'source']);
            $saved = fputcsv($fh, [date('Y-m-d H:i'), $email, $name, $gift, 'gifts-page-reminder']) !== false;
            flock($fh, LOCK_UN);
        }
        fclose($fh);
    }
}

$body = "Someone on the gifts page asked for a reminder before the Christmas cutoff.\n\nEmail: $email\nName: " . ($name !== '' ? $name : '(not given)') . "\nLooking at: " . ($gift !== '' ? $gift : '(nothing picked yet)') . "\nWhen: " . date('D M j, g:i a') . "\n\nThey agreed to get emails about this offer. Saved to the leads list: " . ($saved ? 'yes' : 'NO - keep this email as the record') . "\n";
$sent = @mail(TO, 'GIFT LEAD - reminder sign-up', $body, 'From: DIY Sign Guy Gifts <' . FROM . ">\r\nReply-To: " . $email . "\r\nContent-Type: text/plain; charset=UTF-8", '-f' . FROM);

if (!$saved && !$sent) out(500, ['ok' => false, 'error' => 'That did not go through. Please email dean@diysignguy.com.']);
out(200, ['ok' => true]);
