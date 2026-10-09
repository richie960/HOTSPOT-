# Biz Mtandaoni Hotspot Portal

This directory contains the hotspot captive portal and its dashboard.

## Included

- `index.php`, `verify.php`, `verify1.php`: captive portal/payment verification flow
- `gatekeeper.php`, `check.php`, `cleanup.php`: supporting portal scripts
- `dashboard/index.php`: hotspot dashboard
- `M-PESA_LOGO-01.svg`: payment logo asset
- `*.example.json`: configuration/data shape examples only

## Setup

1. Upload this `hotspot/` directory to your PHP web host.
2. Copy `config.example.json` to `config.json` and fill in your actual MikroTik account settings on the server.
3. Copy `dashboard/auth.example.json` to `dashboard/auth.json` and set a strong dashboard password. Keep the live file out of Git.
4. Create the runtime JSON files expected by your scripts from the provided examples, and ensure PHP can write to them.
5. Set `HOTSPOT_ADMIN_SMS_PHONE` in the PHP hosting environment if the verification script should send administrative SMS alerts.
6. Review `.htaccess`, PHP file paths, HTTPS requirements, and MikroTik settings for your hosting layout before deploying.

## Security / omitted files

The original archive contained runtime user/payment/session data and authentication material. Those live files are intentionally not included. `Richcode.txt`, `api/` files that appear to belong to separate applications, runtime JSON/logs, and the video files are also omitted from this safe starter bundle. Review them separately before deciding whether they belong in this hotspot repository.

Phone-number literals in source have been replaced with `2547XXXXXXXX` placeholders. Replace them with a proper server-side configuration setting before production use; do not commit private phone numbers or credentials.

This bundle is a sanitized source package, not a verified production deployment. Test it on a staging host before replacing a live portal.
