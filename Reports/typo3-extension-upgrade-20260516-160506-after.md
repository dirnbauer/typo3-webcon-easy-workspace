# TYPO3 extension upgrade report - after

Date: 2026-05-16 16:05:06 Europe/Vienna

Scope: Upgrade `webconsulting/webcon-easy-workspace` to TYPO3 14 only.

Changes made:

- Kept TYPO3 constraints at `^14.3` and added explicit v14 dependencies required by this extension: `typo3/cms-fluid` and `typo3/cms-frontend`.
- Kept `typo3/cms-workspaces` as a hard requirement.
- Removed `declare(strict_types=1)` from `ext_emconf.php`.
- Switched workspace discard to TYPO3 v14 DataHandler `discard`.
- Added PHPStan 2 tooling, TYPO3 PHPStan extension, and TYPO3-style `Build/phpstan/phpstan.neon`.
- Added `Build/Scripts/runTests.sh` with `lint`, `phpstan`, and `ci` suites.

Verification:

- `composer validate --strict`: passed.
- `composer dump-autoload -vvv`: passed; TYPO3 `asset:publish` completed.
- `composer test`: passed.
- PHPStan: passed at `level: max`.
