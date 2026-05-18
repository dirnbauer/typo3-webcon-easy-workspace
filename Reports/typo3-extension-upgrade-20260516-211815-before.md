# TYPO3 Extension Upgrade Report

**Generated:** 2026-05-16 21:18:15 Europe/Vienna
**Skill:** `/typo3-extension-upgrade`

## Findings

- Extension metadata still declared extension version `0.1.0`.
- Composer required PHP `^8.3`, although TYPO3 14 supports PHP 8.2-8.5.
- `ext_emconf.php` did not declare the PHP compatibility range.
- Backend AJAX routes did not declare explicit HTTP methods.
- PHPStan baseline file existed but contained no ignored errors.
- Documentation was Markdown-only and not ready for TYPO3 guides rendering.

## Suggested Changes

- Set extension version to `14.0.0` in Composer and `ext_emconf.php`.
- Keep TYPO3 constraints v14-only at `^14.3` / `14.3.0-14.99.99`.
- Add PHP `8.2.0-8.5.99` to `ext_emconf.php`.
- Update PHPStan config for max level without an empty baseline.
- Add TYPO3 docs `guides.xml` and RST entry points.

## Current documentation note (2026-05-18)

The README, SECURITY notes and TYPO3 documentation now reflect the current Easy Workspace backend module UI: navigation uses TYPO3 native module selector/submodule routes, record-heavy views use Bootstrap 5 and TYPO3 styleguide cards, tables, list groups, badges and button groups, and the toolbar Easy Workspace element remains separate from the module layout. This note updates documentation context only; the original report findings above remain historical.
