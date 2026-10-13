<?php
/**
 * POST /try/send.php — "Try it on your VIN" (try/index.html): email a visitor their result link, or note that they want
 * to hear when their car is covered. Same config, bot gates and per-IP limit as form.php; the lead is logged to
 * leads.jsonl (type try-vin) and Joe gets a one-line note.
 *
 * Fields: email, kind ('result' | 'notify'), car, url (must be https://roengine.com/try/…), ts, hk, website (honeypot).
 */
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$CONFIG_PATH = __DIR__ . '/../config.php';
if (!is_readable($CONFIG_PATH) && is_readable(__DIR__ . '/../../config.php')) $CONFIG_PATH = __DIR__ . '/../../config.php';
$CFG = is_readable($CONFIG_PATH) ? require $CONFIG_PATH : [];
function cfg(string $k, string $d = ''): string { global $CFG; return (isset($CFG[$k]) && is_string($CFG[$k]) && $CFG[$k] !== '') ? $CFG[$k] : $d; }

$MG_KEY = cfg('mg_key');
$MG_DOMAIN = cfg('mg_domain', 'mail.roengine.com');
if (strcasecmp($MG_DOMAIN, 'roengine.com') === 0) $MG_DOMAIN = 'mail.roengine.com';
$MG_REGION = cfg('mg_region', 'https://api.mailgun.net');
$FROM = cfg('from', 'RO Engine <notifications@mail.roengine.com>');
if (stripos($FROM, '@' . $MG_DOMAIN) === false) $FROM = 'RO Engine <notifications@' . $MG_DOMAIN . '>';
$NOTIFY_TO = cfg('to', 'joe@roengine.com');
$REPLY_TO = cfg('reply_to', 'joe@roengine.com');
$LEAD_LOG = cfg('lead_log', __DIR__ . '/../../leads.jsonl');
$RATE_DIR = cfg('rate_dir', __DIR__ . '/../../.ratelimit');

function out(bool $ok, string $err = '', int $code = 200): void { http_response_code($code); echo json_encode($ok ? ['ok' => true] : ['ok' => false, 'error' => $err]); exit; }
function field(string $k, int $max = 300): string { $v = $_POST[$k] ?? ''; if (!is_string($v)) return ''; return mb_substr(str_replace(["\r", "\n"], ' ', trim($v)), 0, $max); }
function clientIp(): string {
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $h) {
        if (!empty($_SERVER[$h])) { $ip = trim(explode(',', (string)$_SERVER[$h])[0]); if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip; }
    }
    return '0.0.0.0';
}
function mg_send(array $fields): array {
    global $MG_KEY, $MG_DOMAIN, $MG_REGION;
    if ($MG_KEY === '') return [0, 'no mg_key'];
    $ch = curl_init($MG_REGION . '/v3/' . $MG_DOMAIN . '/messages');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_USERPWD => 'api:' . $MG_KEY, CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($fields), CURLOPT_TIMEOUT => 15]);
    $body = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return [$code, (string)$body];
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') out(false, 'Method not allowed.', 405);
// Bot gates (as form.php): honeypot, page-load timestamp, typed-in token. Bots get a silent ok.
if (field('website') !== '') out(true);
$ts = (int)field('ts', 20); $age = time() - $ts;
if ($ts <= 0 || $age < 2 || $age > 86400) out(true);
if (field('hk', 20) !== base_convert((string)$ts, 10, 36)) out(true);

$ip = clientIp();
if (!is_dir($RATE_DIR)) @mkdir($RATE_DIR, 0700, true);
$bucket = $RATE_DIR . '/try-' . sha1($ip) . '.txt';
$hits = is_readable($bucket) ? array_filter(array_map('intval', explode(',', (string)file_get_contents($bucket))), fn($t) => $t > time() - 3600) : [];
if (count($hits) >= 5) out(false, 'That’s a few emails already. Try again in an hour.', 429);
$hits[] = time(); @file_put_contents($bucket, implode(',', $hits), LOCK_EX);

$email = field('email', 200);
$kind = field('kind', 10) === 'notify' ? 'notify' : 'result';
$car = field('car', 120);
$url = field('url', 300);
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) out(false, 'That email address does not look right.', 400);
$domain = substr(strrchr($email, '@') ?: '', 1);
if ($domain === '' || (!checkdnsrr($domain, 'MX') && !checkdnsrr($domain, 'A'))) out(false, 'We could not find a mail server for "' . htmlspecialchars($domain, ENT_QUOTES) . '".', 400);
if (!preg_match('#^https://roengine\.com/try/\?vin=[A-HJ-NPR-Z0-9]{17}&mi=\d{1,6}(&s=severe)?$#', $url)) out(false, 'Bad link.', 400);

@file_put_contents($LEAD_LOG, json_encode(['type' => 'try-vin', 'kind' => $kind, 'received_at' => gmdate('c'), 'email' => $email, 'car' => $car, 'url' => $url, 'ip' => $ip], JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);

if ($kind === 'result') {
    $subject = 'Your ' . ($car ?: 'car') . ', explained';
    $text = "Here's the link to the result you looked up on roengine.com:\n\n$url\n\n" .
        "It's what's due from the factory schedule, written the way your customer would read it. In RO Engine every repair order " .
        "gets this, with your prices, your tech's photos and a button to approve.\n\nTry it in your own shop, free for 90 days: " .
        "https://app.roengine.com/signup\n\nQuestions? Just reply. I read these myself.\nJoe Sprandel, RO Engine\n";
} else {
    $subject = 'We\'ll let you know when the ' . ($car ?: 'car') . ' is covered';
    $text = "Thanks for trying RO Engine. We don't have the factory schedule for the " . ($car ?: 'car') . " yet. We only show what the " .
        "manufacturer publishes, and we're adding cars every week. We'll email you when it's in.\n\nMeanwhile, here's how it looks on a covered car: " .
        "https://roengine.com/try/\n\nJoe Sprandel, RO Engine\n";
}
[$code] = mg_send(['from' => $FROM, 'to' => $email, 'h:Reply-To' => $REPLY_TO, 'subject' => $subject, 'text' => $text, 'o:tag' => 'try-vin']);
mg_send(['from' => $FROM, 'to' => $NOTIFY_TO, 'h:Reply-To' => $email, 'subject' => "Try-it lead ($kind): $car", 'text' => "$email\n$car\n$url\n", 'o:tag' => 'try-vin-lead']);
if ($code < 200 || $code >= 300) out(false, 'We couldn\'t send that just now. Try again in a minute.', 502);
out(true);
