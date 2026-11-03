# Changelog

All notable changes to NC Care are documented in this file.
The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

## 1.0.1 - 2026-11-03

### Changed
- Contact address is now nccare@itservicenet.net.
- Updated front-end dependencies to fix known vulnerabilities (DOMPurify, follow-redirects and others).

## 1.0.0 - 2026-11-02

### Added
- First stable release. The app collects non-sensitive technical information about the Nextcloud
  instance (Nextcloud and PHP versions, database type, aggregate user counts, enabled apps, background
  job mode, results of automatic checks) and sends it to the NC Care portal run by ITServicenet, on a
  daily, weekly or monthly schedule.
- Registration without secrets: a new instance registers by itself, sends a first report and waits for
  ITServicenet to approve it. The admin settings page shows the approval status and refreshes it.
- Admin settings page with registration status, connection test, manual run and registration reset.
