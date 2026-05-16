# TYPO3 security report - before

Date: 2026-05-16 15:55:03 Europe/Vienna

Scope: TYPO3 v14 security hardening review for the extension.

Findings:

- AJAX table input is allow-listed.
- DataHandler operations are guarded by active workspace checks.
- Workspace discard validates `t3ver_wsid` before executing.
- Several endpoints return generic user-facing errors for expected failures.
- `historyRollbackAction()` returns raw exception messages, filenames and line numbers to the browser.
- Workspace discard uses an older `flush` command where TYPO3 v14 provides the clearer `discard` command.

Suggested changes:

- Sanitize rollback exception responses and avoid leaking internal paths/line numbers.
- Use the v14 DataHandler `discard` command.
- Preserve the server-side TSconfig feature checks.
