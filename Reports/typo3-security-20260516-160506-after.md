# TYPO3 security report - after

Date: 2026-05-16 16:05:06 Europe/Vienna

Changes made:

- Replaced legacy workspace discard cmdmap with TYPO3 v14 `discard`.
- Kept active workspace ownership validation before publish/discard operations.
- Replaced rollback exception details with a localized generic error response.
- Updated `SECURITY.md` with the latest findings.

Verification:

- `composer test`: passed.
- PHPStan max verifies typed request payload handling and no mixed values reach critical cmdmap construction.
