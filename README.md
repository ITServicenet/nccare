# NC Care — Nextcloud app

[NC Care](https://nc-care.itservicenet.net/landing-page) by [ITServicenet](https://itservicenet.net)
keeps an eye on the technical health of your Nextcloud: it reviews the instance and reports
the result to the NC Care portal, so you can follow it over time and get an alert when
something needs attention.

| | |
|---|---|
| App ID | `nccare` |
| Version | see [`CHANGELOG.md`](CHANGELOG.md) |
| Nextcloud | 30 to 34 |
| PHP | 8.3 to 8.4 |
| License | AGPL-3.0-or-later |

## What the app sends

The app sends a report with non-sensitive technical information only: Nextcloud and PHP versions, database type,
aggregate user counts, the list of enabled apps, the background job mode, the results of the
automatic checks and, when available, hardware statistics.
**It never sends files, user names or the content of your instance.** The full list is shown
in the app settings, and you can disable the app at any time.

## How it works

1. Enable the app. It registers the instance with the NC Care portal and sends a first report.
2. A new instance is *pending approval* until ITServicenet approves it. Create an account on
   the portal with the instance address and the identifier shown in the app settings.
3. Once approved, reports arrive on schedule and you can configure alerts by email or
   Telegram, uptime monitoring and the weekly summary.

## Installation

From the Nextcloud App Store, or from the release package published on the
[NC Care page](https://nc-care.itservicenet.net/landing-page):

```bash
cd /var/www/html/custom_apps
tar xzf nc-care-nextcloud-app-X.Y.Z.tar.gz
chown -R www-data:www-data nccare
sudo -u www-data php occ app:enable nccare
```

## Building from source

The front end is built with Vite; PHP dependencies are managed with Composer.

```bash
npm ci
npm run build
composer install --no-dev
```

The sources are in `lib/` (PHP), `src/` (Vue/TypeScript), `templates/` and `l10n/`.
Release packages are built from these sources with the portal address of the production
environment.

## Contact

Support and bug reports: [nccare@itservicenet.net](mailto:nccare@itservicenet.net)

## License

AGPL-3.0-or-later. See [`LICENSE`](LICENSE).
