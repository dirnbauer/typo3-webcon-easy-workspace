# Changelog

All notable changes to Easy Workspace are documented in this file.

## [Unreleased]

### Changed

- News is now scoped to a single article on its **detail view** (the Visual Editor / preview page, or its edit form) — the news record plus its linked content elements — instead of scanning every news record stored on the selected page or folder. Driven by `tx_news_pi1[news]` in the preview iframe (and the news edit-form URL); still gated by `enableNewsBundles`.

### Fixed

- Toolbar dropdown was rendered behind the module content iframe after switching into a workspace without reloading. The menu is now (re)converted to a native top-layer popover when its toolbar item is injected by a topbar re-render, so it always paints above the iframe.

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
