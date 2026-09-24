# RO Engine — marketing site deploy (HostGator)

Static site + one PHP endpoint. Nothing here talks to the RO Engine server or database.

```
index.html          the page
form.php            demo request handler (Mailgun API)
config.example.php  copy to config.php, fill in
.htaccess           HTTPS redirect, security headers, caching, blocks config.php
robots.txt
sitemap.xml
assets/             mark, curve, favicon
```

## 1. Add the domain in cPanel

cPanel → **Domains** → Create A New Domain → `roengine.com`.
Set the document root to something like `/home/<user>/roengine.com`. Do **not**
nest it inside `public_html/` — an addon domain inside public_html is reachable
from the primary domain too, which you don't want.

## 2. Upload

Upload the contents of this folder into that document root. File Manager or SFTP,
either is fine. Confirm `.htaccess` came across — File Manager hides dotfiles by
default (Settings → Show Hidden Files).

## 3. Configure the form

```bash
cp config.example.php config.php
```

Edit `config.php`:

| key | value |
|---|---|
| `mg_domain` | `roengine.com` |
| `mg_key` | Mailgun → Settings → API Keys → **private** key |
| `from` | `RO Engine Site <noreply@roengine.com>` |
| `to` | where demo requests should land |

Then `chmod 600 config.php`. `.htaccess` already blocks it from the web, but
belt and suspenders.

**This must go through Mailgun, not PHP `mail()`.** roengine.com publishes
`DMARC p=reject` with strict alignment (`adkim=s; aspf=s`). Mail sent from
HostGator's servers as `@roengine.com` fails alignment and gets **rejected** —
not junked, rejected. Mailgun is the only aligned sender for this domain.

## 4. SSL

cPanel → **SSL/TLS Status** → run AutoSSL for roengine.com. Wait for the cert
before flipping DNS, or the `.htaccess` HTTPS redirect will produce a cert
warning on first visit.

## 5. DNS cutover

`roengine.com` currently points at **98.163.168.50** — the RO Engine server.
Change the A record (and the `www` CNAME) to your HostGator IP. `autohousenwa.com`
resolves to `192.185.149.9`; use whatever cPanel shows as this account's shared IP.

Leave `arologik.com` alone. That stays on your server pointing at the app.

TTL permitting, propagation is usually minutes.

## 6. Decommission the old copy

Once DNS has moved and the site is confirmed live, remove the marketing site
from the RO Engine box and drop its Caddy vhost. Leaving it running means a
stale copy still answers on the old IP, and the public form endpoint stays
reachable next to the database — which is the thing this move was meant to fix.

## Test checklist

- [ ] `https://roengine.com` loads, `http://` redirects to it
- [ ] `https://www.roengine.com` redirects to the bare domain
- [ ] `https://roengine.com/config.php` returns **403**, not the file
- [ ] Logo, curve, and favicon all load (no 404s in devtools Network tab)
- [ ] Submit the demo form — confirmation appears, email arrives
- [ ] Submit twice more quickly — third should still work, sixth in an hour returns 429
- [ ] Reply to the notification email — it should go to the submitter (Reply-To is set)
- [ ] Mobile: check the nav lockup and the pricing cards at 390px

## Spam handling

Three layers, no captcha:

1. **Honeypot** — hidden `website` field. Filled = silently dropped, returns success so bots don't learn.
2. **Time trap** — submissions under 3 seconds are dropped.
3. **Rate limit** — 5 per IP per hour, returns 429.

If spam still gets through, the next step is Cloudflare Turnstile, which is
invisible to real users. Don't add a visible captcha — shop owners abandon them.

## Notes

- Fonts load from Google Fonts. To go fully self-hosted, download the Plus Jakarta
  Sans woff2 files into `assets/fonts/` and swap the `<link>` for `@font-face`.
- `form.php` logs Mailgun failures via `error_log()` — cPanel → Errors.
- The mark and curve are single SVG assets; swap the files in `assets/` and the
  whole page updates.
