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
