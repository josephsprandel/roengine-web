<?php
/**
 * leads.php — browser-based lead viewer + failed-send replay.
 *
 * Replaces the CLI-only replay-failed.php for hosts without terminal access.
 * Put this next to form.php.
 *
 * REQUIRES a token in config.php:
 *     'admin_token' => 'a-long-random-string',
 *
 * Usage:
 *     https://roengine.com/leads.php?token=YOUR_TOKEN
 *
 * Without a valid token it returns 404 — indistinguishable from a page that
 * isn't there, so a scanner learns nothing.
 */

declare(strict_types=1);

// Must be sent explicitly. An HTTP Content-Type header overrides <meta charset>,
// and shared hosts commonly default to ISO-8859-1 — which turns curly quotes,
// en-dashes and anything a prospect typed from a phone into black diamonds.
header('Content-Type: text/html; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow', true);
header('Cache-Control: no-store, max-age=0');
header('Referrer-Policy: no-referrer');

// ---------------------------------------------------------------- config ---
$CONFIG_PATH = __DIR__ . '/config.php';
if (!is_readable($CONFIG_PATH) && is_readable(__DIR__ . '/../config.php')) {
    $CONFIG_PATH = __DIR__ . '/../config.php';
}
$CFG = is_readable($CONFIG_PATH) ? require $CONFIG_PATH : [];
if (!is_array($CFG)) $CFG = [];

function c(string $k, string $d = ''): string {
    global $CFG;
    return (isset($CFG[$k]) && is_string($CFG[$k]) && $CFG[$k] !== '') ? $CFG[$k] : $d;
}

// ------------------------------------------------------------------ auth ---
$token    = c('admin_token');
$supplied = isset($_GET['token']) && is_string($_GET['token']) ? $_GET['token'] : '';

// Fail closed: no token configured means the page is unusable, not wide open.
if ($token === '' || strlen($token) < 16 || !hash_equals($token, $supplied)) {
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    exit("<!doctype html><title>404 Not Found</title><h1>Not Found</h1>");
}

$KEY     = c('mg_key');
$DOMAIN  = c('mg_domain', 'mail.roengine.com');
if (strcasecmp($DOMAIN, 'roengine.com') === 0) $DOMAIN = 'mail.roengine.com';
$REGION  = c('mg_region', 'https://api.mailgun.net');
$FROM    = c('from', 'RO Engine <notifications@mail.roengine.com>');
$TO      = c('to',   'joseph.sprandel@gmail.com');

$LEAD_LOG     = c('lead_log',      __DIR__ . '/../leads.jsonl');
$FAIL_LOG     = c('mail_fail_log', __DIR__ . '/../mail-failures.log');
$REPLAYED_LOG = preg_replace('/\.log$/', '', $FAIL_LOG) . '.replayed.log';

// --------------------------------------------------------------- helpers ---
function readJsonl(string $path): array {
    if (!is_readable($path)) return [];
    $out = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $d = json_decode($line, true);
        if (is_array($d)) $out[] = $d;
    }
    return $out;
}
function e($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function fmtTime(string $iso): string {
    if ($iso === '') return '';
    try {
        $dt = new DateTime($iso, new DateTimeZone('UTC'));
        $dt->setTimezone(new DateTimeZone('America/Chicago'));
        return $dt->format('M j, g:ia');
    } catch (Throwable $e) { return $iso; }
}

// ---------------------------------------------------------------- replay ---
$replayReport = [];
$didReplay = false;

if (($_GET['action'] ?? '') === 'replay' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $didReplay = true;
    $rows = readJsonl($FAIL_LOG);
    $remaining = [];

    foreach ($rows as $r) {
        if (($r['stage'] ?? '') !== 'notify') {
            $remaining[] = $r;                       // autoresponder failures: never resend
            $replayReport[] = ['status' => 'skipped', 'lead' => $r, 'note' => 'autoresponder — not resent'];
            continue;
        }
        if ($KEY === '' || strpos($KEY, 'PUT-') === 0) {
            $remaining[] = $r;
            $replayReport[] = ['status' => 'failed', 'lead' => $r, 'note' => 'mg_key missing or placeholder'];
            continue;
        }

        $hot  = (($r['current_sms'] ?? '') === 'ShopWare') ? ' [SHOPWARE]' : '';
        $body =
            "New beta / demo request from roengine.com  (REPLAYED — original send failed)\n" .
            str_repeat('-', 46) . "\n" .
            'Name        : ' . ($r['first'] ?? '') . ' ' . ($r['last'] ?? '') . "\n" .
            'Shop        : ' . ($r['shop'] ?? '') . "\n" .
            'Email       : ' . ($r['email'] ?? '') . "\n" .
            'Phone       : ' . ($r['phone_display'] ?? $r['phone'] ?? '') . "\n" .
            'Bays        : ' . ($r['bays'] ?? '') . "\n" .
            'Current SMS : ' . ($r['current_sms'] ?? '') . "\n" .
            'Notes       : ' . ((($r['notes'] ?? '') !== '') ? $r['notes'] : '(none)') . "\n" .
            str_repeat('-', 46) . "\n" .
            'Originally received: ' . ($r['received_at'] ?? '?') . " UTC\n" .
            'Original failure   : HTTP ' . ($r['http'] ?? '?') . ' ' . ($r['error'] ?? '') . "\n";

        $ch = curl_init($REGION . '/v3/' . $DOMAIN . '/messages');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD        => 'api:' . $KEY,
            CURLOPT_POST           => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_POSTFIELDS     => http_build_query([
                'from'       => $FROM,
                'to'         => $TO,
                'h:Reply-To' => trim(($r['first'] ?? '') . ' ' . ($r['last'] ?? '')) . ' <' . ($r['email'] ?? '') . '>',
                'subject'    => 'Beta request — ' . ($r['shop'] ?? '') . ' (' . ($r['bays'] ?? '') . ' bays, ' . ($r['current_sms'] ?? '') . ')' . $hot . ' [REPLAY]',
                'text'       => $body,
                'o:tag'      => 'lead-replay',
            ]),
        ]);
        $resp = (string)curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code >= 200 && $code < 300) {
            $r['replayed_at'] = gmdate('c');
            @file_put_contents($REPLAYED_LOG, json_encode($r, JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);
            $replayReport[] = ['status' => 'sent', 'lead' => $r, 'note' => ''];
        } else {
            $remaining[] = $r;
            $replayReport[] = ['status' => 'failed', 'lead' => $r, 'note' => "HTTP $code " . substr($resp, 0, 120)];
        }
    }

    // Rewrite the failure log with whatever did not go out.
    $buf = '';
    foreach ($remaining as $r) $buf .= json_encode($r, JSON_UNESCAPED_SLASHES) . "\n";
    @file_put_contents($FAIL_LOG, $buf, LOCK_EX);
}

// ------------------------------------------------------------------ data ---
$leads    = array_reverse(readJsonl($LEAD_LOG));       // newest first
$failures = readJsonl($FAIL_LOG);
$replayed = readJsonl($REPLAYED_LOG);
$failIds  = [];
foreach ($failures as $f) if (isset($f['id'])) $failIds[$f['id']] = $f['stage'] ?? 'notify';

$pendingNotify = 0;
foreach ($failures as $f) if (($f['stage'] ?? '') === 'notify') $pendingNotify++;

$shopware = 0;
foreach ($leads as $l) if (($l['current_sms'] ?? '') === 'ShopWare') $shopware++;

$qs = '?token=' . rawurlencode($supplied);
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>RO Engine — Leads</title>
<style>
  :root{--bg:#0e1414;--panel:#151d1d;--line:#233030;--txt:#e6efee;--dim:#8ba3a1;--teal:#2f8480;--tealx:#3fb0aa;--warn:#e0a44a;--bad:#e06a5a;}
  *{box-sizing:border-box}
  body{margin:0;background:var(--bg);color:var(--txt);font:15px/1.5 ui-sans-serif,system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;padding:24px 16px 64px}
  .wrap{max-width:1040px;margin:0 auto}
  h1{font-size:22px;margin:0 0 4px;letter-spacing:-.01em}
  h1 sup{color:var(--tealx);font-size:.62em}
  .sub{color:var(--dim);font-size:13px;margin-bottom:24px}
  .stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;margin-bottom:24px}
  .stat{background:var(--panel);border:1px solid var(--line);border-radius:10px;padding:14px 16px}
  .stat .n{font-size:26px;font-weight:700;letter-spacing:-.02em}
  .stat .l{color:var(--dim);font-size:12px;text-transform:uppercase;letter-spacing:.06em;margin-top:2px}
  .stat.alert .n{color:var(--warn)}
  h2{font-size:14px;text-transform:uppercase;letter-spacing:.08em;color:var(--dim);margin:28px 0 10px;font-weight:600}
  table{width:100%;border-collapse:collapse;background:var(--panel);border:1px solid var(--line);border-radius:10px;overflow:hidden}
  th{text-align:left;font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:var(--dim);padding:10px 12px;border-bottom:1px solid var(--line);font-weight:600;white-space:nowrap}
  td{padding:11px 12px;border-bottom:1px solid var(--line);vertical-align:top;font-size:14px}
  tr:last-child td{border-bottom:0}
  .shop{font-weight:600}
  .meta{color:var(--dim);font-size:12px}
  a{color:var(--tealx);text-decoration:none}
  a:hover{text-decoration:underline}
  .tag{display:inline-block;font-size:10px;font-weight:700;letter-spacing:.05em;padding:2px 6px;border-radius:4px;vertical-align:1px}
  .tag.sw{background:rgba(63,176,170,.16);color:var(--tealx)}
  .tag.fail{background:rgba(224,106,90,.16);color:var(--bad)}
  .notes{color:var(--dim);font-size:13px;margin-top:3px;font-style:italic}
  .bar{background:var(--panel);border:1px solid var(--line);border-left:3px solid var(--warn);border-radius:8px;padding:14px 16px;margin-bottom:20px;display:flex;gap:16px;align-items:center;flex-wrap:wrap}
  .bar p{margin:0;flex:1;min-width:240px;font-size:14px}
  button{background:var(--teal);color:#fff;border:0;border-radius:7px;padding:9px 18px;font-size:14px;font-weight:600;cursor:pointer;font-family:inherit}
  button:hover{background:var(--tealx)}
  .empty{color:var(--dim);padding:20px 12px;background:var(--panel);border:1px solid var(--line);border-radius:10px;font-size:14px}
  .rep{background:var(--panel);border:1px solid var(--line);border-radius:10px;padding:6px 16px;margin-bottom:20px}
  .rep div{padding:7px 0;border-bottom:1px solid var(--line);font-size:14px}
  .rep div:last-child{border-bottom:0}
  .ok{color:var(--tealx)} .bad{color:var(--bad)} .dim{color:var(--dim)}
  code{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:12px;color:var(--dim)}
</style>
</head>
<body>
<div class="wrap">

  <h1>RO<sup>e</sup> &nbsp;Leads</h1>
  <div class="sub">roengine.com demo requests · times in Central</div>

<?php if ($didReplay): ?>
  <h2>Replay result</h2>
  <div class="rep">
  <?php if (!$replayReport): ?>
    <div class="dim">Nothing to replay.</div>
  <?php else: foreach ($replayReport as $r):
      $cls = $r['status'] === 'sent' ? 'ok' : ($r['status'] === 'skipped' ? 'dim' : 'bad');
      $lbl = strtoupper($r['status']); ?>
    <div><span class="<?= $cls ?>"><strong><?= e($lbl) ?></strong></span>
      &nbsp; <?= e(($r['lead']['first'] ?? '') . ' ' . ($r['lead']['last'] ?? '')) ?>
      — <?= e($r['lead']['shop'] ?? '') ?>
      <?php if ($r['note'] !== ''): ?><span class="dim"> · <?= e($r['note']) ?></span><?php endif; ?>
    </div>
  <?php endforeach; endif; ?>
  </div>
<?php endif; ?>

  <div class="stats">
    <div class="stat"><div class="n"><?= count($leads) ?></div><div class="l">Total leads</div></div>
    <div class="stat"><div class="n"><?= $shopware ?></div><div class="l">On ShopWare</div></div>
    <div class="stat<?= $pendingNotify ? ' alert' : '' ?>"><div class="n"><?= $pendingNotify ?></div><div class="l">Unsent</div></div>
    <div class="stat"><div class="n"><?= count($replayed) ?></div><div class="l">Recovered</div></div>
  </div>

<?php if ($pendingNotify > 0): ?>
  <div class="bar">
    <p><strong><?= $pendingNotify ?></strong> lead<?= $pendingNotify === 1 ? '' : 's' ?> never made it to your inbox — the send failed. They're safe on disk. Resend them now.</p>
    <form method="post" action="<?= e('leads.php' . $qs . '&action=replay') ?>">
      <button type="submit">Resend <?= $pendingNotify ?> lead<?= $pendingNotify === 1 ? '' : 's' ?></button>
    </form>
  </div>
<?php endif; ?>

  <h2>All leads (<?= count($leads) ?>)</h2>
<?php if (!$leads): ?>
  <div class="empty">No leads yet. Once someone submits the form on roengine.com they'll appear here — even if the email fails to send.</div>
<?php else: ?>
  <table>
    <tr><th>Received</th><th>Shop</th><th>Contact</th><th>Bays</th><th>Current SMS</th></tr>
    <?php foreach ($leads as $l):
        $id = $l['id'] ?? '';
        $bad = $id !== '' && isset($failIds[$id]) && $failIds[$id] === 'notify'; ?>
    <tr>
      <td class="meta" style="white-space:nowrap"><?= e(fmtTime($l['received_at'] ?? '')) ?></td>
      <td>
        <span class="shop"><?= e($l['shop'] ?? '') ?></span>
        <?php if (($l['current_sms'] ?? '') === 'ShopWare'): ?> <span class="tag sw">SHOPWARE</span><?php endif; ?>
        <?php if ($bad): ?> <span class="tag fail">NOT SENT</span><?php endif; ?>
        <?php if (($l['notes'] ?? '') !== ''): ?><div class="notes">“<?= e($l['notes']) ?>”</div><?php endif; ?>
      </td>
      <td>
        <?= e(trim(($l['first'] ?? '') . ' ' . ($l['last'] ?? ''))) ?><br>
        <a href="mailto:<?= e($l['email'] ?? '') ?>"><?= e($l['email'] ?? '') ?></a>
        <?php if (($l['phone_display'] ?? '') !== ''): ?><br><a href="tel:<?= e($l['phone'] ?? '') ?>" class="meta"><?= e($l['phone_display']) ?></a><?php endif; ?>
      </td>
      <td class="meta"><?= e($l['bays'] ?? '') ?></td>
      <td class="meta"><?= e($l['current_sms'] ?? '') ?></td>
    </tr>
    <?php endforeach; ?>
  </table>
<?php endif; ?>

  <h2>Storage</h2>
  <div class="empty">
    <code><?= e(basename($LEAD_LOG)) ?></code> — every submission, written before any email is attempted.<br>
    <code><?= e(basename($FAIL_LOG)) ?></code> — full records of sends that failed, resendable above.<br>
    <code><?= e(basename($REPLAYED_LOG)) ?></code> — archive of recovered leads.
  </div>

</div>
</body>
</html>