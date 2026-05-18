# TYPO3 testing report - before

Date: 2026-05-16 15:55:03 Europe/Vienna

Scope: TYPO3 extension test and verification readiness.

Findings:

- No `Build/Scripts/runTests.sh` exists.
- No PHPUnit tests exist.
- No PHPStan config exists.
- Composer validates strictly.
- PHP 8.3.30 and Composer 2.9.5 are available locally.

Suggested changes:

- Add `Build/Scripts/runTests.sh` with `lint` and `phpstan` suites.
- Add `Build/phpstan/phpstan.neon` based on the current TYPO3 docs layout and run at `level: max`.
- Add Composer scripts for the same checks.
- Run Composer install/update, PHP linting, and PHPStan.

## Current documentation note (2026-05-18)

The README, SECURITY notes and TYPO3 documentation now reflect the current Easy Workspace backend module UI: navigation uses TYPO3 native module selector/submodule routes, record-heavy views use Bootstrap 5 and TYPO3 styleguide cards, tables, list groups, badges and button groups, and the toolbar Easy Workspace element remains separate from the module layout. This note updates documentation context only; the original report findings above remain historical.
