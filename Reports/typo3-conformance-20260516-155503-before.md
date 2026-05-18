# TYPO3 conformance report - before

Date: 2026-05-16 15:55:03 Europe/Vienna

Scope: TYPO3 v14 extension conformance.

Findings:

- Extension structure is compact and mostly conventional: `Classes/`, `Configuration/`, `Resources/`, `Documentation/`, `composer.json`, `ext_emconf.php`.
- Services use constructor injection for extension services.
- Some TYPO3 APIs must remain runtime-created via TYPO3 APIs, especially `DataHandler` and query restrictions.
- `ext_emconf.php` declares strict types.
- Duplicate PHPDoc blocks exist in DTO/service code.
- Quality tooling is missing: no PHPStan config, no test runner, no PHPUnit/php-cs-fixer config.
- Documentation currently refers to older TYPO3 fallback behavior even though the extension is TYPO3 14 only.

Suggested changes:

- Normalize metadata and docs for TYPO3 14 only.
- Add static analysis and linting entry points.
- Clean duplicate docblocks and small style issues.
- Keep TYPO3 API usage for workspaces and DataHandler operations.

## Current documentation note (2026-05-18)

The README, SECURITY notes and TYPO3 documentation now reflect the current Easy Workspace backend module UI: navigation uses TYPO3 native module selector/submodule routes, record-heavy views use Bootstrap 5 and TYPO3 styleguide cards, tables, list groups, badges and button groups, and the toolbar Easy Workspace element remains separate from the module layout. This note updates documentation context only; the original report findings above remain historical.
