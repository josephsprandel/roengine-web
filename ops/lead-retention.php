<?php
/**
 * Demo-request retention — what roengine.com/privacy/ promises:
 *   - demo requests that don't become customers are deleted after 24 months;
 *   - the IP address, browser and referring page stored with them are removed after 90 days.
 *
 * Deployed by .cpanel.yml to /home/roengine/ops/ (outside public_html). Run daily by a cPanel cron:
 *   php /home/roengine/ops/lead-retention.php --apply
 * Without --apply it only reports what it would do.
 *
 * A lead that became a customer is kept: add "keep": true to its line in leads.jsonl (leads.php shows
 * the file). Reads the same config.php as form.php, so a custom lead_log path is honoured.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$apply = in_array('--apply', $argv, true);
$cfg = [];
foreach (['/home/roengine/public_html/config.php', '/home/roengine/config.php'] as $p) {
    if (is_readable($p)) { $cfg = require $p; break; }
}
$leadLog = (is_array($cfg) && !empty($cfg['lead_log'])) ? $cfg['lead_log'] : '/home/roengine/leads.jsonl';
foreach ($argv as $a) { if (strpos($a, '--file=') === 0) $leadLog = substr($a, 7); } // for testing

const KEEP_DAYS    = 730; // 24 months
const DETAILS_DAYS = 90;
const DETAIL_KEYS  = ['ip', 'ua', 'referer'];

if (!is_file($leadLog)) { echo "no lead log at $leadLog — nothing to do\n"; exit(0); }

$fh = fopen($leadLog, 'c+');
if (!$fh || !flock($fh, LOCK_EX)) { fwrite(STDERR, "cannot lock $leadLog\n"); exit(1); }

$now = time();
$kept = []; $deleted = 0; $stripped = 0; $unreadable = 0;
while (($line = fgets($fh)) !== false) {
    $line = rtrim($line, "\r\n");
    if ($line === '') continue;
    $lead = json_decode($line, true);
    if (!is_array($lead)) { $kept[] = $line; $unreadable++; continue; } // never drop what we can't read
    $at = isset($lead['received_at']) ? strtotime($lead['received_at']) : false;
    $ageDays = $at ? ($now - $at) / 86400 : 0;
    if ($ageDays > KEEP_DAYS && empty($lead['keep'])) { $deleted++; continue; }
    if ($ageDays > DETAILS_DAYS) {
        $had = false;
        foreach (DETAIL_KEYS as $k) { if (array_key_exists($k, $lead)) { unset($lead[$k]); $had = true; } }
        if ($had) { $stripped++; $line = json_encode($lead, JSON_UNESCAPED_SLASHES); }
    }
    $kept[] = $line;
}

printf("%s: %d kept, %d deleted (> 24 months), %d with IP/browser/referrer removed (> 90 days)%s%s\n",
    $leadLog, count($kept), $deleted, $stripped,
    $unreadable ? ", $unreadable unreadable line(s) left untouched" : '',
    $apply ? '' : ' — dry run, nothing written (use --apply)');

if ($apply && ($deleted || $stripped)) {
    ftruncate($fh, 0);
    rewind($fh);
    fwrite($fh, $kept ? implode("\n", $kept) . "\n" : '');
    fflush($fh);
}
flock($fh, LOCK_UN);
fclose($fh);
