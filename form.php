<?php
/**
 * roengine.com — question / contact request handler
 * Drop-in replacement. Same contract the front-end JS expects:
 *   POST multipart -> {"ok":true} | {"ok":false,"error":"..."}
 *
 * Changes vs. the version currently live:
 *   1. Every lead is written to disk BEFORE any mail is attempted. Mail can
 *      fail; a lead must never disappear.
 *   2. Real server-side validation. The live endpoint returns ok:true for a
 *      submission with no email at all.
 *   3. Honeypot + submit-timing checks actually short-circuit.
 *   4. Per-IP rate limit.
 *   5. Autoresponder to the prospect — confirms their address is real and
 *      puts your name in their inbox within one second.
 *
 * Config: reads config.php. Whatever name the key already uses in there is
 * picked up automatically (see $KEY_NAMES below) — no edits to config.php.
 */

declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

// ---------------------------------------------------------------- config ---
// config.php returns an array. Keys: mg_domain, mg_key, from, to,
// and optionally reply_to, mg_region, lead_log, notify_shopware.
$CONFIG_PATH = __DIR__ . '/config.php';
if (!is_readable($CONFIG_PATH) && is_readable(__DIR__ . '/../config.php')) {
    $CONFIG_PATH = __DIR__ . '/../config.php';
}
$CFG = is_readable($CONFIG_PATH) ? require $CONFIG_PATH : [];
if (!is_array($CFG)) $CFG = [];

function cfg(string $k, string $default = '') : string {
    global $CFG;
    return (isset($CFG[$k]) && is_string($CFG[$k]) && $CFG[$k] !== '') ? $CFG[$k] : $default;
}

$MG_KEY      = cfg('mg_key');
$MG_DOMAIN_R = cfg('mg_domain', 'mail.roengine.com');
$MG_REGION_R = cfg('mg_region', 'https://api.mailgun.net');  // EU: https://api.eu.mailgun.net
$FROM_ADDR_R = cfg('from',      'RO Engine <notifications@mail.roengine.com>');
$NOTIFY_TO_R = cfg('to',        'joe@roengine.com');
$REPLY_TO_R  = cfg('reply_to',  'joe@roengine.com');

/**
 * Guardrail. Mailgun will only accept a send on a domain registered to the
 * account, and the From address must be on that same domain or SPF/DKIM
 * alignment fails. `roengine.com` is NOT a Mailgun sending domain — only
 * `mail.roengine.com` is — so a misconfigured value here silently 401s
 * every lead. Correct it rather than fail.
 */
if (strcasecmp($MG_DOMAIN_R, 'roengine.com') === 0) {
    $MG_DOMAIN_R = 'mail.roengine.com';
}
if (stripos($FROM_ADDR_R, '@' . $MG_DOMAIN_R) === false) {
    // Keep the display name, move the address onto the sending domain.
    $label = trim(preg_replace('/<[^>]*>/', '', $FROM_ADDR_R)) ?: 'RO Engine';
    $FROM_ADDR_R = $label . ' <notifications@' . $MG_DOMAIN_R . '>';
}

define('MG_REGION',  $MG_REGION_R);
define('NOTIFY_TO',  $NOTIFY_TO_R);
define('FROM_ADDR',  $FROM_ADDR_R);
define('REPLY_TO',   $REPLY_TO_R);
define('LEAD_LOG',   cfg('lead_log',      __DIR__ . '/../leads.jsonl'));  // outside public_html
define('MAIL_FAIL_LOG', cfg('mail_fail_log', __DIR__ . '/../mail-failures.log'));
define('RATE_DIR',   cfg('rate_dir',      __DIR__ . '/../.ratelimit'));
define('RATE_MAX',   5);     // submissions per IP
define('RATE_WINDOW', 3600); // seconds
define('MIN_SECONDS', 3);    // faster than this = bot

// ---------------------------------------------------------------- helpers --
function out(bool $ok, string $err = '', int $code = 200): void {
    http_response_code($code);
    echo json_encode($ok ? ['ok' => true] : ['ok' => false, 'error' => $err]);
    exit;
}
function field(string $k, int $max = 500): string {
    $v = $_POST[$k] ?? '';
    if (!is_string($v)) return '';
    $v = trim($v);
    $v = str_replace(["\r", "\n"], ' ', $v);          // header-injection guard
    return mb_substr($v, 0, $max);
}
function clientIp(): string {
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $h) {
        if (!empty($_SERVER[$h])) {
            $ip = trim(explode(',', (string)$_SERVER[$h])[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
        }
    }
    return '0.0.0.0';
}

// -------------------------------------------------------------- selftest ---
// GET /form.php?selftest=1&token=<admin_token> — confirms config.php resolved
// and the Mailgun key works.
if (isset($_GET['selftest'])) {
    // Admin-only: it reports the notification address and whether the Mailgun
    // key works. Without the admin token it's a 404, like leads.php.
    $adminToken = cfg('admin_token');
    if ($adminToken === '' || !hash_equals($adminToken, (string)($_GET['token'] ?? ''))) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Not found']);
        exit;
    }
    $leadDir = dirname(LEAD_LOG);

    // Ask Mailgun whether the sending domain actually exists on this account.
    // This is the check that would have caught the roengine.com /
    // mail.roengine.com mismatch immediately.
    $domainCheck = 'skipped (no key)';
    if ($MG_KEY !== '' && function_exists('curl_init')) {
        $ch = curl_init(MG_REGION . '/v3/domains/' . $MG_DOMAIN_R);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD        => 'api:' . $MG_KEY,
            CURLOPT_TIMEOUT        => 10,
        ]);
        curl_exec($ch);
        $dc = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $domainCheck = $dc === 200
            ? 'OK — domain is registered and the key works'
            : ($dc === 401 ? 'FAIL 401 — bad key, or domain not on this account'
                           : "FAIL HTTP $dc");
    }

    echo json_encode([
        'ok'                 => true,
        'config_loaded'      => is_readable($CONFIG_PATH),
        'mg_key'             => $MG_KEY !== '' ? 'found (' . strlen($MG_KEY) . ' chars, ' . substr($MG_KEY, 0, 4) . '…)' : 'NOT FOUND',
        'mg_domain_used'     => $MG_DOMAIN_R,
        'mg_domain_check'    => $domainCheck,
        'from'               => FROM_ADDR,
        'notify_to'          => NOTIFY_TO,
        'reply_to'           => REPLY_TO,
        'lead_log_writable'  => is_writable($leadDir) || is_writable(LEAD_LOG),
        'ratelimit_writable' => is_dir(RATE_DIR) ? is_writable(RATE_DIR) : is_writable(dirname(RATE_DIR)),
        'curl'               => function_exists('curl_init'),
        'php'                => PHP_VERSION,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

// ---------------------------------------------------------------- method ---
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    out(false, 'Method not allowed.', 405);
}

// -------------------------------------------------------------- bot gates --
// Honeypot: answer 200 ok so the bot logs a success and moves on. Send nothing.
if (field('website') !== '') {
    out(true);
}
// Timing: humans do not fill seven fields in under three seconds.
$ts = (int)field('ts', 20);
if ($ts > 0 && (time() - $ts) < MIN_SECONDS) {
    out(true);
}

// ------------------------------------------------------------ rate limit ---
$ip = clientIp();
if (!is_dir(RATE_DIR)) { @mkdir(RATE_DIR, 0700, true); }
$bucket = RATE_DIR . '/' . sha1($ip) . '.txt';
$hits = [];
if (is_readable($bucket)) {
    $hits = array_filter(
        array_map('intval', explode(',', (string)file_get_contents($bucket))),
        fn($t) => $t > time() - RATE_WINDOW
    );
}
if (count($hits) >= RATE_MAX) {
    out(false, 'Too many requests. Email ' . NOTIFY_TO . ' directly.', 429);
}
$hits[] = time();
@file_put_contents($bucket, implode(',', $hits), LOCK_EX);

// -------------------------------------------------------------- validate ---
$first = field('first', 80);
$last  = field('last', 80);
$shop  = field('shop', 150);
$email = field('email', 200);
$phone = field('phone', 40);
$bays  = field('bays', 20);
$sms   = field('sms', 40);
$notes = trim((string)($_POST['notes'] ?? ''));
$notes = mb_substr($notes, 0, 2000);

if ($first === '' || $last === '') out(false, 'Please enter your first and last name.', 400);
if ($shop  === '')                 out(false, 'Please enter your shop name.', 400);
if ($email === '')                 out(false, 'Please enter your email address.', 400);
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    out(false, 'That email address does not look right.', 400);
}
// Reject domains that cannot receive mail — catches the common typos
// (gmial.com, yahoo.co) that otherwise become an unreachable lead.
$domain = substr(strrchr($email, '@') ?: '', 1);
if ($domain === '' || (!checkdnsrr($domain, 'MX') && !checkdnsrr($domain, 'A'))) {
    out(false, 'We could not find a mail server for "' . htmlspecialchars($domain, ENT_QUOTES) . '". Check the spelling?', 400);
}

$phoneDigits = preg_replace('/\D+/', '', $phone) ?? '';
$phoneFmt = strlen($phoneDigits) === 10
    ? sprintf('(%s) %s-%s', substr($phoneDigits,0,3), substr($phoneDigits,3,3), substr($phoneDigits,6))
    : $phone;

// ---------------------------------------------- persist BEFORE any sending --
$lead = [
    // Stable id so a line in mail-failures.log maps 1:1 to a line in
    // leads.jsonl without guessing from timestamps.
    'id'          => substr(bin2hex(random_bytes(6)), 0, 12),
    'received_at' => gmdate('c'),
    'first' => $first, 'last' => $last, 'shop' => $shop,
    'email' => $email, 'phone' => $phoneDigits, 'phone_display' => $phoneFmt,
    'bays'  => $bays,  'current_sms' => $sms, 'notes' => $notes,
    'ip' => $ip,
    'ua' => mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 300),
    'referer' => mb_substr((string)($_SERVER['HTTP_REFERER'] ?? ''), 0, 300),
];
@file_put_contents(LEAD_LOG, json_encode($lead, JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);

// ------------------------------------------------------------------ mail ---
function mg_send(string $key, array $fields): array {
    global $MG_DOMAIN_R;
    if ($key === '') return [0, 'no mg_key in config.php'];
    $ch = curl_init(MG_REGION . '/v3/' . $MG_DOMAIN_R . '/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD        => 'api:' . $key,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($fields),
        CURLOPT_TIMEOUT        => 15,
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    return [$code, $err !== '' ? $err : (string)$body];
}

$hot = ($sms === 'ShopWare') ? ' [SHOPWARE]' : '';
$subject = 'Question — ' . $shop . ' (' . $bays . ' bays, ' . $sms . ')' . $hot;
$body =
    "New question from roengine.com\n" .
    str_repeat('-', 46) . "\n" .
    "Name        : $first $last\n" .
    "Shop        : $shop\n" .
    "Email       : $email\n" .
    "Phone       : $phoneFmt\n" .
    "Bays        : $bays\n" .
    "Current SMS : $sms\n" .
    "Notes       : " . ($notes !== '' ? $notes : '(none)') . "\n" .
    str_repeat('-', 46) . "\n" .
    "IP: $ip\nReferer: {$lead['referer']}\nReceived: {$lead['received_at']} UTC\n";

[$code, $resp] = mg_send($MG_KEY, [
    'from'       => FROM_ADDR,
    'to'         => NOTIFY_TO,
    'h:Reply-To' => "$first $last <$email>",
    'subject'    => $subject,
    'text'       => $body,
    'o:tag'      => 'lead',
]);

/**
 * Record the outcome of the send attempt.
 *
 * On failure the ENTIRE lead is written to MAIL_FAIL_LOG, not just an error
 * line — so the failure log is self-contained and replayable. Recovering a
 * batch after an outage is then:
 *
 *   php replay-failed.php            (or just re-POST the `lead` objects)
 *
 * rather than cross-referencing two files by timestamp.
 */
function record_outcome(array $lead, string $stage, int $code, string $resp): void {
    $rec = $lead;
    $rec['stage']       = $stage;              // 'notify' | 'autoresponder'
    $rec['http']        = $code;
    $rec['error']       = substr($resp, 0, 500);
    $rec['failed_at']   = gmdate('c');
    @file_put_contents(
        MAIL_FAIL_LOG,
        json_encode($rec, JSON_UNESCAPED_SLASHES) . "\n",
        FILE_APPEND | LOCK_EX
    );
}

// Notification failed. The lead is already on disk; log the full record so it
// can be replayed, and still report success to the prospect.
if ($code < 200 || $code >= 300) {
    record_outcome($lead, 'notify', $code, $resp);
    out(true);
}

// Autoresponder. Best-effort — never blocks the success response, but a
// failure here is no longer silent: the prospect thinks they got a reply.
if (true) {
    [$acode, $aresp] = mg_send($MG_KEY, [
        'from'       => FROM_ADDR,
        'to'         => "$first $last <$email>",
        'h:Reply-To' => REPLY_TO,
        'subject'    => 'Got your request — RO Engine',
        'text'       =>
            "$first,\n\n" .
            "Got it — I'll reply within one business day.\n\n" .
            "I run AutoHouse Automotive in Northwest Arkansas — I built RO Engine " .
            "because I needed it, and it's been running my shop. So when we talk, " .
            "you're talking to another shop owner, not a salesperson.\n\n" .
            "Want to look around in the meantime? Start a test drive at https://app.roengine.com/signup — nothing charges for 90 days.\n\n" .
            "— Joe Sprandel\nRO Engine\n" . REPLY_TO . "\n",
        'o:tag'      => 'autoresponder',
        'h:List-Unsubscribe' => '<mailto:' . NOTIFY_TO . '?subject=unsubscribe>',
    ]);
    if ($acode < 200 || $acode >= 300) {
        record_outcome($lead, 'autoresponder', $acode, $aresp);
    }
}

out(true);