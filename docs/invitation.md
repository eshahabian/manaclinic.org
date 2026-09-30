# Invitation on Mana Clinic

The invitation lives at `cpanel/invite/index.php` and uses the existing
`cpanel/includes/mail.php` SMTP implementation. All four PNG portraits are preserved.

## Deploy

Deploy the new `cpanel/invite/` folder into `public_html/invite/` on the existing
Mana hosting account. Do not overwrite the live `config.php`.
Alternatively use the repository's existing cPanel Git deployment after reviewing
the other pending changes: update from remote, then Deploy HEAD.

The invitation requires the live Mana `config.php`, MySQL connection, and SMTP
settings already used by the clinic. The target page is
`https://manaclinic.org/invite/`. It cannot run through GitHub HTML Preview.

## Verify on the server

1. Run `php -l public_html/invite/index.php`.
2. Open the invitation on desktop and mobile. Confirm the four portraits, Yes/No,
   and growing pointing hand.
3. Click Yes, enter a test message, and submit. The page remains open.
4. Check receipt in `eshahabian@gmail.com`, including spam. SMTP acceptance alone
   does not guarantee inbox delivery.
5. If SMTP fails, use the existing clinic admin email settings/test and server logs.
   User message drafts remain available in the browser after a failed request.

The endpoint has a fixed recipient, session CSRF protection, duplicate submission
protection, and a per-IP rate limit (five attempts/hour). Credentials stay on the
server; no SMTP secrets are included in the invitation.
