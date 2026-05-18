# TYPO3 testing report - after

Date: 2026-05-16 16:05:06 Europe/Vienna

Changes made:

- Added `Build/Scripts/runTests.sh`.
- Added Composer scripts: `lint`, `phpstan`, and `test`.
- Added `Build/phpstan/phpstan.neon` with `level: max`.
- Added required dev dependencies for PHPStan and TYPO3-specific PHPStan rules.

Verification:

- `composer validate --strict`: passed.
- `composer dump-autoload -vvv`: passed.
- `composer test`: passed.

Residual notes:

- No PHPUnit tests were added in this pass because the repo had no existing test suite or fixtures. The immediate green gate is lint + PHPStan max.

## Current documentation note (2026-05-18)

The README, SECURITY notes and TYPO3 documentation now reflect the current Easy Workspace backend module UI: navigation uses TYPO3 native module selector/submodule routes, record-heavy views use Bootstrap 5 and TYPO3 styleguide cards, tables, list groups, badges and button groups, and the toolbar Easy Workspace element remains separate from the module layout. This note updates documentation context only; the original report findings above remain historical.
