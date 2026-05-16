# TYPO3 Testing Report

**Generated:** 2026-05-16 21:18:15 Europe/Vienna
**Skill:** `/typo3-testing`

## Changes Applied

- Added `.github/workflows/ci.yml`.
- CI validates Composer metadata, installs dependencies and runs
  `composer test`.
- CI matrix covers PHP 8.2, 8.3, 8.4 and 8.5 for TYPO3 14.

## Verification

- `composer validate --no-check-publish --no-check-lock`: passed with the
  expected Composer warning about the explicit version field.
- `composer test`: passed.
- `Build/Scripts/runTests.sh -s ci`: passed.
