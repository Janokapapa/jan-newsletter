# Changelog

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
