# TYPO3 Conformance Report

**Generated:** 2026-05-16 21:18:15 Europe/Vienna
**Skill:** `/typo3-conformance`

## Findings

- TYPO3 v14-only dependency constraints were mostly present, but package
  versioning and PHP metadata were incomplete.
- PHPStan was already at `level: max`, but used an empty baseline include.
- Query restrictions were instantiated through `GeneralUtility::makeInstance()`.
- Backend routes accepted implicit methods.
- Documentation lacked TYPO3 `Index.rst`, `guides.xml` and editor config.
- No GitHub Actions workflow existed for the local quality checks.

## Suggested Changes

- Tighten metadata and remove stale baseline.
- Use direct TYPO3 query restriction objects.
- Add explicit route methods.
- Add TYPO3 documentation structure.
- Add CI for Composer validation, linting and PHPStan.

## Current documentation note (2026-05-18)

The README, SECURITY notes and TYPO3 documentation now reflect the current Easy Workspace backend module UI: navigation uses TYPO3 native module selector/submodule routes, record-heavy views use Bootstrap 5 and TYPO3 styleguide cards, tables, list groups, badges and button groups, and the toolbar Easy Workspace element remains separate from the module layout. This note updates documentation context only; the original report findings above remain historical.
