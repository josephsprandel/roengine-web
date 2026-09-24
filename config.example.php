<?php
// config.example.php — copy to config.php ON THE HOST and fill in. Never commit config.php.
// Copy to config.php and fill in. config.php is blocked from the web by .htaccess.
//
// CHANGED from the previous version — the old values could never deliver:
//   mg_domain was 'roengine.com', which is NOT a sending domain on the Mailgun
//   account (only mail.roengine.com is). Every send returned 401 Forbidden.
//   'from' must also be on the sending domain or SPF/DKIM alignment fails —
//   roengine.com's SPF authorises HostGator, not Mailgun.
 
return [
    // --- Mailgun ------------------------------------------------------------
    'mg_domain' => '',   // MUST match the verified sending domain
    'mg_key'    => '',
    'mg_region' => '',   // EU accounts: https://api.eu.mailgun.net
 
    // --- Addresses ----------------------------------------------------------
    // 'from' must be @mail.roengine.com. The display name is what people see.
    'from'      => '',
    'to'        => '',   // where demo requests land
    'reply_to'  => '',            // Reply-To on the autoresponder
 
	// --- Leads form ------------------------------------
	'admin_token' => '',
];