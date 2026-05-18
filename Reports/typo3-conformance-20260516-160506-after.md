# TYPO3 conformance report - after

Date: 2026-05-16 16:05:06 Europe/Vienna

Changes made:

- Added missing development quality tooling.
- Added typed value/TCA helpers to make TYPO3 globals and QueryBuilder rows explicit for PHPStan.
- Removed duplicate PHPDoc blocks.
- Normalized docs and comments for TYPO3 14-only behavior.
- Updated extension metadata description for TYPO3 v14.

Residual notes:

- No PHPUnit suite exists yet; current green checks cover Composer validation, PHP lint, and PHPStan max.
- The extension intentionally uses TYPO3 APIs such as `DataHandler`, `WorkspaceRestriction`, `BackendUtility::workspaceOL()`, `PreviewUriBuilder`, and `TcaSchemaFactory`.

Verification:

- `composer test`: passed.

## Current documentation note (2026-05-18)

The README, SECURITY notes and TYPO3 documentation now reflect the current Easy Workspace backend module UI: navigation uses TYPO3 native module selector/submodule routes, record-heavy views use Bootstrap 5 and TYPO3 styleguide cards, tables, list groups, badges and button groups, and the toolbar Easy Workspace element remains separate from the module layout. This note updates documentation context only; the original report findings above remain historical.
