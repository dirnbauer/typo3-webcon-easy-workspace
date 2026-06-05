# Backend screenshots

PNG files for the TYPO3 manual. See [../Screenshots.rst](../Screenshots.rst) for
figures and capture notes.

Lab context for the current set: workspace **Staging**, page **505** (TYPO3Camp
Vienna 2026), **six** pending publishable changes.

Filenames:

- `toolbar-trigger.png`
- `toolbar-dropdown-open.png`
- `toolbar-dropdown-publish.png`
- `toolbar-diff-modal.png`
- `module-open-items.png`
- `module-all-records.png`
- `module-diagnostics.png`
- `module-doc-header.png`
- `user-settings.png`

Optional: `toolbar-news-scope.png`, `toolbar-eye-highlight.png`, `toolbar-child-disclosure.png`

Decode CDP capture logs:

```bash
python3 Build/Scripts/decode-cdp-screenshot.py /path/to/cdp-response.json Documentation/Images/example.png
```
