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
