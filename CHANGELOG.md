# Changelog

All notable changes to NC Care are documented in this file.
The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

## 1.0.5 - 2026-11-06

### Changed
- Store description: removed the statement that the schedule of the reports can be chosen. The app does not offer that choice;
  the report is sent regularly by the Nextcloud background job. The same correction applies to the public README and to the
  first release notes.

## 1.0.4 - 2026-11-06

### Changed
- Compatible with Nextcloud 35 (the manifest now declares 30 to 35) and with PHP 8.5, which Nextcloud 35 supports.
- Where Nextcloud deprecated an API (`OCP\Util::getVersion()` and `getChannel()`, `IAppManager::isInstalled()` and
  `getInstalledApps()`), the app uses the new one when it exists and keeps the old one only for older servers.
- The admin settings page uses the `TemplateResponse::RENDER_AS_BLANK` constant instead of a literal string.
- Store description reworded in Italian and English: schedule wording, a separate note on what is never sent, the
  registration and approval steps, and a line saying ITServicenet is a Nextcloud Platinum partner that supports the app.

## 1.0.3 - 2026-11-06

### Changed
- Store listing: description and summary in Italian (shown to browsers set to Italian, English for everyone else) and four screenshots
  of the app settings page.

## 1.0.2 - 2026-11-05

### Changed
- Store listing: banner and screenshots of the NC Care portal.
- First version published to the Nextcloud App Store automatically from the release pipeline.

## 1.0.1 - 2026-11-03

### Changed
- Contact address is now nccare@itservicenet.net.
- Updated front-end dependencies to fix known vulnerabilities (DOMPurify, follow-redirects and others).

## 1.0.0 - 2026-11-02

### Added
- First stable release. The app collects non-sensitive technical information about the Nextcloud
  instance (Nextcloud and PHP versions, database type, aggregate user counts, enabled apps, background
  job mode, results of automatic checks) and sends it regularly to the NC Care portal run by
  ITServicenet.
- Registration without secrets: a new instance registers by itself, sends a first report and waits for
  ITServicenet to approve it. The admin settings page shows the approval status and refreshes it.
- Admin settings page with registration status, connection test, manual run and registration reset.
