# TYPO3 Extension Upgrade Report

**Generated:** 2026-05-16 21:18:15 Europe/Vienna
**Skill:** `/typo3-extension-upgrade`

## Changes Applied

- `composer.json` now declares version `14.0.0`, PHP `^8.2` and
  TYPO3 `^14.3` packages only.
- `ext_emconf.php` now declares version `14.0.0`, state `stable`,
  TYPO3 `14.3.0-14.99.99` and PHP `8.2.0-8.5.99`.
- Backend AJAX routes now declare GET/POST methods explicitly.
- Empty PHPStan baseline was removed.
- Documentation was migrated to TYPO3 RST structure with `guides.xml`.

## Verification

- `composer test`: passed.
- `Build/Scripts/runTests.sh -s ci`: passed.
- Local TYPO3 API reported active TYPO3 core `14.3.0`.

## Current documentation note (2026-05-18)

The README, SECURITY notes and TYPO3 documentation now reflect the current Easy Workspace backend module UI: navigation uses TYPO3 native module selector/submodule routes, record-heavy views use Bootstrap 5 and TYPO3 styleguide cards, tables, list groups, badges and button groups, and the toolbar Easy Workspace element remains separate from the module layout. This note updates documentation context only; the original report findings above remain historical.
