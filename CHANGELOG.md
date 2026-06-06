# Changelog

All notable changes to Easy Workspace are documented in this file.

## [Unreleased]

### Changed

- News is scoped to a single article on its **detail view** (Visual Editor / preview page, or FormEngine edit form): the news record plus linked content elements, instead of scanning every news record on the selected page or folder. Driven by `tx_news_pi1[news]` in the preview iframe and the news edit-form URL; gated by `enableNewsBundles`.
- **Documentation:** Rewrote `README.md` and the TYPO3 manual under `Documentation/` for the Fluid toolbar + glue JS architecture; removed screenshot assets and `Documentation/Screenshots.rst`.
- **Documentation:** Thermo-nuclear code-quality review (2026-06-06) — added `Documentation/Contributing.rst`, maintainability sections in `README.md` and `Documentation/Index.rst` (layer model, file-size inventory, decomposition targets).
- **Refactor:** Extracted `PublishSelectionNormalizer`, `ModuleSectionViewDataFactory`, and `EasyWorkspaceModuleDocHeaderBuilder`; added `PendingItemsService` context dispatchers (`toolbarCollectionForContext`, `hasChangesForContext`, `listForContext`). `EasyWorkspaceModuleController` reduced from ~736 to ~456 lines.
- **Documentation:** Post-refactor thermo-nuclear review — updated `README.md`, `Documentation/Index.rst`, and `Documentation/Contributing.rst` with pass verdict, corrected file-size inventory, optional backlog, and expanded architecture sections.
- **Documentation:** Second thermo-nuclear pass — complete measured file-size inventory (all PHP/JS units ≥300 lines), watch list for files approaching 700 lines, PR blocker checklist, fixed duplicate RST anchor in `Contributing.rst`.
- **Security:** Removed standalone `SECURITY.md`; security reporting and audit summary now live in the README and manual. Re-audited controllers, services, AJAX routes, and JavaScript (2026-06-03): prior high/medium findings remain fixed; no new critical issues.

### Fixed

- Toolbar dropdown was rendered behind the module content iframe after switching into a workspace without reloading. The menu is now (re)converted to a native top-layer popover when its toolbar item is injected by a topbar re-render.

### Security (audit 2026-06-03)

- **Verified:** Table allow-list (`WorkspaceTablePolicy`), active-workspace checks before publish/discard cmdmaps, `enabled` / `enableRevert` / `enablePreviewLink` server-side gates, parameterized SQL, generic AJAX error messages, TYPO3 v14 `discard` command, stale workspace dependency listener, POST-only mutating routes, backend authentication and route tokens on all endpoints.
- **Accepted backend scope:** `items` / `hasChanges` / `diff` do not call `readPageAccess()` explicitly; TYPO3 backend session and record-level APIs still apply. Optional hardening: add page/news access checks before listing or diffing if your site requires stricter IDOR prevention than Core defaults.
- **Report vulnerabilities:** [GitHub Security Advisories](https://github.com/dirnbauer/typo3-webcon-easy-workspace/security/advisories/new) (private) — not public issues.

## [14.0.0] - 2026-05-24

### Added

- First stable TYPO3 14.3+ release of Easy Workspace.
- Backend toolbar dropdown and server-rendered Easy Workspace module for page-scoped workspace publishing.
- Publishing support for page records, content elements, inline child records, file references, file metadata, and optional EXT:news bundles.
- Checks and diagnostics submodule for workspace integrity issues and manual release risks.
- English and German XLIFF 2.0 labels for backend UI, diagnostics, and health-check output.

### Changed

- Composer metadata is the release source of truth for TYPO3 14.3+ classic-mode compatibility.
- Installation documentation now targets the tagged `^14.0` release.

### Security

- Publishing and discard actions use TYPO3 backend routes, route tokens, and TYPO3 DataHandler commands.
- Workspace dependency handling ignores stale references only after verifying missing source or target records.
