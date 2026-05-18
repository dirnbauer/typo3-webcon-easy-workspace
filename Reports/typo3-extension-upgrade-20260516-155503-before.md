# TYPO3 extension upgrade report - before

Date: 2026-05-16 15:55:03 Europe/Vienna

Scope: Upgrade `webconsulting/webcon-easy-workspace` to TYPO3 14 only.

Findings:

- `composer.json` already requires TYPO3 `^14.3` packages and `typo3/cms-workspaces`.
- `ext_emconf.php` already limits TYPO3 and Workspaces to `14.3.0-14.99.99`.
- No Composer lock file or test/build tool configuration exists yet.
- No PHPStan configuration exists yet; official TYPO3 14.3 docs use `Build/phpstan/phpstan.neon`.
- `ext_emconf.php` contains `declare(strict_types=1)`, which TYPO3 extension metadata convention avoids.
- `PublishSelectedService::discard()` still uses the legacy `version => flush` command instead of the TYPO3 v14 DataHandler `discard` command.

Suggested changes:

- Add current TYPO3/PHPStan tooling at PHPStan `level: max`.
- Add Composer dev dependencies for PHPStan and the TYPO3 PHPStan extension.
- Add a repository test runner for `phpstan` and PHP linting.
- Replace legacy workspace discard command with the v14 public DataHandler `discard` command.
- Remove TYPO3 13-era fallback wording from documentation.

## Current documentation note (2026-05-18)

The README, SECURITY notes and TYPO3 documentation now reflect the current Easy Workspace backend module UI: navigation uses TYPO3 native module selector/submodule routes, record-heavy views use Bootstrap 5 and TYPO3 styleguide cards, tables, list groups, badges and button groups, and the toolbar Easy Workspace element remains separate from the module layout. This note updates documentation context only; the original report findings above remain historical.
