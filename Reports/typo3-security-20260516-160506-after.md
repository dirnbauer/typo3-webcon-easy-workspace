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

## Current documentation note (2026-05-18)

The README, SECURITY notes and TYPO3 documentation now reflect the current Easy Workspace backend module UI: navigation uses TYPO3 native module selector/submodule routes, record-heavy views use Bootstrap 5 and TYPO3 styleguide cards, tables, list groups, badges and button groups, and the toolbar Easy Workspace element remains separate from the module layout. This note updates documentation context only; the original report findings above remain historical.
