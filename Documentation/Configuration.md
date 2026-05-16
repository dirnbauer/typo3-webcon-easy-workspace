# Easy Workspace — Configuration reference

Every visible affordance is gated by a TSconfig flag. Defaults are shipped in [`Configuration/user.tsconfig`](../Configuration/user.tsconfig) and auto-loaded by TYPO3 v14, so a fresh install gives the full feature set to every backend user. Switch flags to `0` per **user / group / page** when a role doesn't need them.

## How it's loaded

TYPO3 v14 auto-loads `Configuration/user.tsconfig` from every active extension. This extension's defaults therefore apply automatically — no manual import.

Override precedence (highest wins):

1. **Page TSconfig** at the currently selected page (resolved per request via `BackendUtility::getPagesTSconfig($pageUid)`).
2. **User TSconfig** field on the backend user record.
3. **User TSconfig** field on the backend user group(s).
4. The shipped defaults.

The merge happens in `Webconsulting\WebconEasyWorkspace\Configuration\ConfigurationProvider::get()` which reads via two public v14 APIs:

- `BackendUserAuthentication::getTSConfig()` for #2 + #3 (TYPO3 already does the user/group merge).
- `BackendUtility::getPagesTSconfig($pageUid)` for #1.

## Option reference

All keys live under `options.webcon_easy_workspace`. Boolean keys take `1` (on) or `0` (off).

### Master

| Key | Default | Effect when `0` |
|---|---|---|
| `enabled` | `1` | Hides the toolbar item entirely; AJAX endpoints respond `403`. |

### Header

| Key | Default | Effect when `0` |
|---|---|---|
| `enableWorkspaceChip` | `1` | Hides the workspace-name chip ("Staging") next to the title. |
| `enablePreviewLink` | `1` | Hides the "Preview link" button. The corresponding AJAX route also returns `403`, so a crafted request can't bypass it. |

### List filter

| Key | Default | Effect when `0` |
|---|---|---|
| `enableFilter` | `1` | Hides the chip row; the dropdown always uses "Changes only". |
| `defaultMode` | `changed` | `changed` shows pending changes only; `all` lists every record on the page with `isChanged` flags on each. |

### List rendering

| Key | Default | Effect when `0` |
|---|---|---|
| `enableThumbnails` | `1` | Skips the first-image lookup entirely (saves the `sys_file_reference` query) and hides the thumbnail column. |
| `enableTypeLabels` | `1` | Hides the second meta line (`Page · Blog Post`). State badge stays. |
| `enableHiddenBadge` | `1` | Hides the "Hidden" badge and the diagonal-stripe thumbnail overlay. |
| `showHidden` | `1` | Hidden records are filtered out of the response **server-side** — they don't reach the client at all. |
| `maxItems` | `200` | Hard cap on rows returned per request. Set lower on extremely active pages. |

### Aggregation scope

| Key | Default | Effect when `0` |
|---|---|---|
| `enableNewsBundles` | `1` | Skips the news lookup loop. If EXT:news is not installed the flag has no effect either way. |

### Per-row actions

| Key | Default | Effect when `0` |
|---|---|---|
| `enableHoverHighlight` | `1` | Hides the eye icon. Hover / click no longer scrolls or outlines anything in the Visual Editor iframe. |
| `enableRevert` | `1` | Hides the discard button. The discard AJAX endpoint also returns `403`. |

## Examples

### Reduced surface for junior editors

A group that should only see what's pending and publish — no discards, no preview link, no filter toggle:

```typoscript
options.webcon_easy_workspace {
    enablePreviewLink = 0
    enableFilter      = 0
    enableRevert      = 0
}
```

### Reviewer profile (no publishing, just inspection)

(The publish + discard buttons can be hidden; reviewers can still see what's pending and use the preview link to QA.)

```typoscript
options.webcon_easy_workspace {
    enableRevert      = 0
    defaultMode       = all
}
```

### Performance setup for a heavy-traffic page

Disable thumbnails to skip the `sys_file_reference` query:

```typoscript
options.webcon_easy_workspace {
    enableThumbnails = 0
    maxItems         = 100
}
```

### News-free site

Save one DB query per dropdown open when EXT:news is installed but you don't need news bundles on this page tree:

```typoscript
options.webcon_easy_workspace {
    enableNewsBundles = 0
}
```

### Power-user profile (all features visible)

This is the default — no overrides needed.

## Where to place overrides

- **Per user:** `User Settings → TSconfig` field on the backend user record.
- **Per group:** `TSconfig` field on the backend user group (applies to every member).
- **Per page tree:** Page Properties → `TSconfig` field on the root page of the subtree. Page TSconfig wins over User TSconfig for that page only.

## Server-side enforcement

Toggling a flag in the dropdown only would let a power user re-enable it via DevTools. **All boolean flags are also enforced on the PHP side** — `EasyWorkspaceAjaxController` re-reads `ConfigurationProvider::get()` on every request and returns `403` when the matching flag is off. `showHidden = 0` and `enableThumbnails = 0` additionally short-circuit the database / FAL work so the response payload is smaller.
