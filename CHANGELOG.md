# Changelog

## v1.1.5 (2026-02-11)
- Add: RFC 8058 one-click unsubscribe (List-Unsubscribe + List-Unsubscribe-Post headers)
- Add: one-click unsubscribe toggle in Settings → Tracking
- Add: ConfirmModal component — replaces all native confirm() dialogs
- Add: campaign stats modal with opens, clicks, bounces, top links, timeline
- Add: campaign send modal with list info, subscriber count, duplicate warning
- Add: campaign duplicate feature (Copy button)
- Add: bulk remove from list (separate from delete)
- Add: cancel all pending button in queue
- Add: custom admin favicon (P on black background)
- Add: TinyMCE source view button
- Fix: campaign editor auto-saves before sending (prevents wrong list)
- Fix: TinyMCE plugin load errors (use WP-bundled plugins only)
- Fix: sent campaigns now read-only

## v1.1.4 (2026-02-11)
- Fix: Mailgun test endpoint URL

## v1.1.3 (2026-02-11)
- Add: Mailgun API transport (faster than SMTP, auto-fallback)

## v1.1.2 (2026-02-11)
- Fix: always use configured from_email, ignore From header set by other plugins (e.g. Appointments+)
- Fix: strip misleading From/MIME-Version/Content-Type from stored headers in queue/log
- Add: async queue processing trigger for critical/high priority emails (no more 2-min wait)
- Add: appointment/booking/reservation keywords as critical priority
- Add: Appointments+ source detection in backtrace

## v1.1.1 (2026-02-06)
- Fix: use SMTP sender address for from_email to avoid relay rejections

## v1.1.0 (2026-02-04)
- Add: cron status display in queue settings

## v1.0.0 (2026-02-01)
- Initial release
- SMTP queue with priority system (critical/high/normal/bulk)
- wp_mail() interception with auto-priority detection
- Subscriber management with lists
- Campaign editor with scheduling
- REST API for all operations
- GetResponse sync support
