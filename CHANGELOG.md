# Changelog

All notable changes to NC Care are documented in this file.
The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

## 1.0.7 - 2026-11-16

### Added
- A registration that waited for approval for too long without an account on the NC Care portal expires. The app
  then stops reporting and shows "Registration expired" with a "Register again" button, which registers the
  instance again with a new identifier. If ITServicenet approves it after all, the reports resume on their own.
- The registration notice on the admin settings page says by when the instance has to be registered, and turns red
  when three days or fewer are left.

## 1.0.6 - 2026-11-16

### Added
- The admin settings page shows a notice inviting to register on the NC Care portal while the instance is not linked to
  an account there. It goes away on its own once the account exists. A portal that does not report it, or does not
  answer, shows nothing.

### Changed
- Store description in Italian and English: what you get by installing the app (5 free support tickets, a basic
  evaluation of the instance and of its governance) and the optional services, with links to the NC Care page and to the
  source code on GitHub. The manifest now declares the repository.

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
