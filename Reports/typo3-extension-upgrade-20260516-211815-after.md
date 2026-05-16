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
