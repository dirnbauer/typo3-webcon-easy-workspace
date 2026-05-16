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
