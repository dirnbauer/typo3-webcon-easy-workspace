import clarity from '@webconsulting/webcon-easy-workspace/layouts/clarity.js';
import efficiency from '@webconsulting/webcon-easy-workspace/layouts/efficiency.js';
import core from '@webconsulting/webcon-easy-workspace/layouts/core.js';

/**
 * Layout registry of the toolbar dropdown.
 *
 * Every editor picks one presentation in User Settings → Easy Workspace
 * (`webconEasyWorkspaceLayout`; default from User TSconfig
 * `options.webcon_easy_workspace.layout`, else "clarity"). The toolbar item
 * passes it as `layout` in the config JSON. All three share the data, the
 * counts, the selection and what publish, discard and cleanup do; a layout
 * only changes presentation and interaction details.
 *
 *   clarity     "Klar" — explains what goes live (the defaults below)
 *   efficiency  "Effizient" — dense, keyboard shortcuts
 *   core        "TYPO3 Core" — accessible, in the backend's own style
 *
 * ## Where a layout lives
 *
 * - `layouts/<name>.js` exports a profile (default export).
 * - `Css/layouts/<name>.css` holds its styles, every rule scoped under
 *   `.wew-menu[data-wew-layout="<name>"]` (the root element carries the
 *   attribute). The toolbar item loads all three files after
 *   toolbar-menu.css, so scoped rules win over the base without !important.
 * - Shared renderers: templates/{header,row,group,footer,states}.js. They
 *   are the default presentation; their exported part renderers are the
 *   building blocks a profile reuses.
 *
 * ## Profile
 *
 * ```js
 * export default {
 *   name: 'efficiency',              // = data-wew-layout, = the setting value
 *   flags: { … },                    // see below; missing flags use the defaults
 *   // optional hooks, see below
 * };
 * ```
 *
 * ### Flags (menu-layout.js DEFAULT_FLAGS)
 *
 * - `selectAllMin` (2): the footer select-all appears from this many edit
 *   rows on. 1 = always.
 * - `otherLanguagesOpenUpTo` (0): the other-languages section opens by
 *   itself when nothing of the group is in view; with n > 0 also while it
 *   holds at most n rows.
 * - `cleanupOpen` ('alone'): default state of the cleanup section —
 *   'alone' (open when the group has no edits), 'never', 'always'.
 * - `keyboardHintKey` ('toolbar.keyboardHint'): label of the screen-reader
 *   keyboard help in the footer ('toolbar.keyboardHint.efficiency' names
 *   the Delete and Ctrl/Cmd+Enter shortcuts). A help that depends on the
 *   configuration comes from the `keyboardHint` hook instead.
 * - `copiesSummaryKey` ('toolbar.summary.copies'): label that names the
 *   cleanup records in the header summary ("4 unveränderte Kopien"). A
 *   layout that gives its cleanup section another name uses the same word
 *   here ('toolbar.summary.drafts' in core) and in the three keys below.
 * - `copyEditKey` ('toolbar.cleanup.edit'): the name of a cleanup line's
 *   language chip ("DE: Kopie „German“ bearbeiten").
 * - `cleanupSuccessKey` ('toolbar.cleanup.success.message') and
 *   `cleanupSkippedKey` ('toolbar.cleanup.skipped'): the notifications after
 *   a cleanup ("3 unveränderte Kopien verworfen.").
 * Any other key is free for the layout's own renderers.
 *
 * ### Renderer hooks
 *
 * A renderer hook receives the host (the <webcon-easy-workspace-menu-v2>
 * element), its arguments and a `ctx` whose `ctx.render()` returns the
 * default output — so a hook can replace it, wrap it or return it
 * unchanged. Return a Lit TemplateResult or `nothing`.
 *
 * - `renderRow(host, item, ctx)` — a change row. `ctx.row` is the row
 *   context (templates/row.js rowContext(): key, id, type, selected,
 *   inView, language, revert, locatable, editable, meta, children,
 *   companions, hoverLocate, hoverDiscard); `ctx.parts` the part renderers
 *   (check, icon, title, actions, meta, changeBadge, hiddenBadge,
 *   languageTag, metaText, children, companions — each `(host, item, row)`);
 *   `ctx.shell(content)` the `<li>` with every data attribute and the row
 *   click. Called once per row per render.
 * - `rowClasses(host, item, row)` → string[] — extra classes on the `<li>`
 *   (the default renderer adds wew-row--{type}, --selected, --hidden,
 *   --primary, --has-companions).
 * - `renderCompanions(host, item, groups, ctx)` — the translations line of
 *   a row; `groups` = companionGroups() ([{kind, labelKey, fields, editable,
 *   chips: [{companion, language}]}]); `ctx.row`. Chips must keep
 *   `data-wew-companion` (keyboard) and call host.handleCompanionEdit(item,
 *   companion).
 * - `renderGroupHead(host, group, ctx)` — icon, h3 title, path, count;
 *   `ctx.groupCount`, `ctx.index`.
 * - `renderGroupExtras(host, group, ctx)` — after the rows of the view,
 *   before the other languages (default: nothing).
 * - `renderSection(host, group, ctx)` — the other-languages section;
 *   `ctx.section` = otherLanguagesContext() (sectionId, domId, open, rows,
 *   selected, title, hint, shortHint).
 * - `renderCleanup(host, group, ctx)` — the cleanup section;
 *   `ctx.section` = cleanupContext() (sectionId, domId, open, records,
 *   revert, busy).
 * - `renderCopy(host, group, copy, ctx)` — one line of the cleanup section;
 *   `copy` = {key, title, iconIdentifier, typeLabel, members, records}.
 *   host.handleDiscardCopies(group, [copy]) discards just this line.
 * - `renderIntro(host, groups, ctx)` — above the first group (default:
 *   nothing).
 * - `renderHeader(host, ctx)` — the whole header (templates/header.js
 *   renderHeader(); keep `id=host.titleId` on the title and
 *   `id=host.summaryId` on the summary, the dialog points at both).
 * - `renderSummary(host, summary, ctx)` — the content of the header summary
 *   `<p id=host.summaryId data-wew-count-chip>`. `summary` = summaryText()
 *   with bound numbers; `ctx.render()` returns it unchanged. Keep the
 *   readable text identical to `summary`: the dialog's aria-describedby
 *   reads it as one sentence.
 * - `renderEmpty(host, ctx)` — the body when nothing is listed (keep
 *   `data-wew-empty-title` with tabindex -1 on its heading: focus goes
 *   there after the last publish).
 * - `footerExtras(host, state, ctx)` — in the footer before the actions;
 *   `state` = footerState() ({total, selectedCount, allChecked,
 *   someChecked}).
 *
 * ### Behaviour hooks
 *
 * - `onKeydown(host, event, ctx)` → boolean — every keydown inside the
 *   dialog, capture phase, before list navigation and Escape. Return true
 *   when handled (the event is then prevented and stopped). `ctx` =
 *   {stop, row, item, inList} (menu-keyboard.js keyboardContext()).
 *   Useful host methods: handlePublish(), handleRowAction(event, action,
 *   item), toggleSection(sectionId), setStatus(message).
 * - `beforeDiscardCopies(host, group, copies)` → boolean | Promise<boolean>
 *   — asked before a cleanup discard; false cancels (default: true, no
 *   confirmation). A Core modal opened here hides the dropdown: pass it to
 *   keepOpenAcrossModal() (menu-dropdown.js) so it opens again afterwards.
 * - `publishShortcut(host)` → {text, keys} | null — the publish button's
 *   shortcut: `text` goes into its tooltip ('toolbar.shortcut.publishTitle'),
 *   `keys` into aria-keyshortcuts (e.g. 'Control+Enter Meta+Enter'). It only
 *   announces the shortcut; the layout's onKeydown handles the keys
 *   (default: null, no shortcut).
 * - `keyboardHint(host)` → label id — the footer's screen-reader keyboard
 *   help, when it depends on the configuration (efficiency leaves out the
 *   Delete key while enableRevert is off); default: the `keyboardHintKey`
 *   flag.
 *
 * ## Contracts a layout keeps
 *
 * - Keyboard stops are `[data-wew-stop]`: the row checkbox, section toggles
 *   (`data-wew-section-toggle`), the first chip of a cleanup line. Row
 *   actions are `[data-wew-action]` and `[data-wew-companion]` inside the
 *   `[data-wew-row]` / `[data-wew-copy-group]`; section heads are
 *   `[data-wew-section-head]`. Render them with `tabindex="-1"`; the
 *   keyboard module hands out tabindex 0.
 * - Keep the test hooks: data-wew-row, data-wew-key, data-wew-row-check,
 *   data-wew-change-badge, data-wew-companions, data-wew-cleanup,
 *   data-wew-discard-copies, data-wew-section, data-wew-section-check,
 *   data-wew-publish, data-wew-select-all, data-wew-status.
 * - Colours from tokens (tokens.css, --wew-*; colour values live only
 *   there), no gradients, Core building
 *   blocks (.badge, .btn, .form-check, <typo3-backend-icon>), motion through
 *   the --wew-motion-* tokens (0 under prefers-reduced-motion).
 */
export const LAYOUTS = Object.freeze({ clarity, efficiency, core });

export const DEFAULT_LAYOUT = 'clarity';

export function isLayoutName(name) {
  return typeof name === 'string' && Object.hasOwn(LAYOUTS, name);
}

/** The profile for the host's configured layout; "clarity" for anything unknown. */
export function layoutFor(host) {
  const name = String(host?._config?.layout || '').trim().toLowerCase();
  return isLayoutName(name) ? LAYOUTS[name] : LAYOUTS[DEFAULT_LAYOUT];
}
