# TYPO3 Conformance Report

**Generated:** 2026-05-16 21:18:15 Europe/Vienna
**Skill:** `/typo3-conformance`

## Changes Applied

- Composer and `ext_emconf.php` now agree on v14-only release metadata.
- PHPStan uses `level: max`, PHP 8.2 floor analysis and no baseline.
- `PendingItemsService` uses `new DeletedRestriction()` and
  `new WorkspaceRestriction(...)`.
- `Configuration/Backend/AjaxRoutes.php` has explicit HTTP methods.
- TYPO3 docs now include `Documentation/Index.rst`,
  `Documentation/Configuration.rst`, `Documentation/guides.xml` and
  `Documentation/.editorconfig`.
- `.github/workflows/ci.yml` runs quality checks across PHP 8.2-8.5.

## Verification

- `composer test`: passed.
- `Build/Scripts/runTests.sh -s ci`: passed.
- TYPO3 docs validation: passed.

## Current documentation note (2026-05-18)

The README, SECURITY notes and TYPO3 documentation now reflect the current Easy Workspace backend module UI: navigation uses TYPO3 native module selector/submodule routes, record-heavy views use Bootstrap 5 and TYPO3 styleguide cards, tables, list groups, badges and button groups, and the toolbar Easy Workspace element remains separate from the module layout. This note updates documentation context only; the original report findings above remain historical.
