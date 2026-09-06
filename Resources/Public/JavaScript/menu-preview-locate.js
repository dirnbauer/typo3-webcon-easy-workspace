import Notification from '@typo3/backend/notification.js';
import { IFRAME_HIGHLIGHT_STYLE } from '@webconsulting/webcon-easy-workspace/menu-constants.js';
import {
  collectIframes,
  discardTagSubtitleKey,
  discardTagTitleKey,
  isKnownPreviewFrame,
  label,
} from '@webconsulting/webcon-easy-workspace/menu-context.js';

export { isKnownPreviewFrame } from '@webconsulting/webcon-easy-workspace/menu-context.js';

// Visual Editor renders the per-element icon bar (drag handle + edit
// actions) inside the element's shadow root and reveals it on CSS :hover.
// Synthetic mouse events do not trigger :hover, so when we programmatically
// "show" an element we force the handle visible and remember prior values.
const VE_TOOLBAR_FORCE_STYLE = {
  opacity: '1',
  visibility: 'visible',
  'pointer-events': 'auto',
};

// Transient green outline confirming the element a mutation just touched
// (revert, save, or rollback). Hard-coded colors: the preview document has
// its own CSS scope, so backend custom properties do not reach it.
const CONFIRM_FLASH_STYLE = {
  outline: '3px solid #3aae6a',
  outlineOffset: '2px',
  boxShadow: '0 0 0 6px rgba(58, 174, 106, 0.22)',
  transition: 'outline 0.2s ease, box-shadow 0.2s ease',
  scrollMarginTop: '48px',
  scrollMarginBottom: '48px',
};
const CONFIRM_FLASH_DURATION = 1600;
const LOCATE_TOP_RESERVE = 96;
const LOCATE_MIN_TOP_RESERVE = 48;
const LOCATE_VIEWPORT_RESERVE_RATIO = 0.5;
const LOCATE_HORIZONTAL_MARGIN = 24;

export function isPreviewGate(doc) {
  if (!doc) return false;
  return doc.title === 'Backend login required';
}

export function locateTarget(item) {
  return {
    table: String(item?.locateTable || item?.table || ''),
    liveUid: parseInt(item?.locateLiveUid ?? item?.liveUid ?? 0, 10) || 0,
    workspaceUid: parseInt(item?.locateWorkspaceUid ?? item?.workspaceUid ?? 0, 10) || 0,
  };
}

export function isLocatable(host, item) {
  const target = locateTarget(item);
  return host._config.enableHoverHighlight && target.table === 'tt_content' && (target.liveUid > 0 || target.workspaceUid > 0);
}

export function isEditable(item) {
  return Boolean(item?.contextualEditUrl || item?.editUrl);
}

export function findContentElement(doc, uid) {
  const selectors = [
    '#c' + uid,
    '[id="c' + uid + '"]',
    '#cb-content-' + uid,
    '#cb' + uid,
    '#content-block-' + uid,
    '[data-uid="' + uid + '"][data-table="tt_content"]',
    '[data-content-uid="' + uid + '"]',
    '[data-tt-content-uid="' + uid + '"]',
    '[data-record-uid="' + uid + '"]',
    '[data-typo3-record-uid="' + uid + '"]',
    '[data-veedit*="\\"uid\\":' + uid + ',\\"table\\":\\"tt_content\\""]',
    '[data-veedit*="\\"uid\\":' + uid + '"][data-veedit*="tt_content"]',
    '.tt-content-' + uid,
    '.ce-' + uid,
  ];

  for (const sel of selectors) {
    try {
      const hit = doc.querySelector(sel);
      if (hit) return hit;
    } catch { /* invalid selector — ignore */ }
  }

  const probe = doc.querySelector(`[id^="c"][id$="${uid}"]`);
  return probe || null;
}

export function targetUids(item) {
  const target = locateTarget(item);
  if (target.table !== 'tt_content') return [];
  return [target.liveUid, target.workspaceUid]
    .map((n) => parseInt(n, 10))
    .filter((n) => n > 0)
    .filter((n, i, arr) => arr.indexOf(n) === i);
}

export function locateInDoc(doc, item) {
  const uids = targetUids(item);
  if (uids.length === 0) return null;

  const veSelector = uids.flatMap((u) => [
    `ve-content-element[uid="${u}"][table="tt_content"]`,
    `ve-content-element[id="tt_content:${u}"]`,
  ]).join(', ');

  const veEl = doc.querySelector(veSelector);
  if (veEl) return { doc, el: veEl, isVeWrapper: true };

  for (const u of uids) {
    const el = findContentElement(doc, u);
    if (el) return { doc, el, isVeWrapper: false };
  }
  return null;
}

export function locateInAnyIframe(host, item) {
  if (targetUids(item).length === 0) return null;

  for (const iframe of collectIframes()) {
    let doc;
    try {
      doc = iframe.contentDocument || iframe.contentWindow?.document;
    } catch { continue; }
    if (!doc) continue;

    const located = locateInDoc(doc, item);
    if (located) return { iframe, ...located };
  }
  return null;
}

export function findVisualEditorIframe() {
  const tries = [
    () => document.querySelector('iframe#visual-editor-iframe'),
    () => document.querySelector('iframe[id*="visual-editor"]'),
    () => window.top?.document?.querySelector('iframe#visual-editor-iframe'),
    () => window.top?.document?.querySelector('iframe[id*="visual-editor"]'),
    () => document.querySelector('iframe#tx_viewpage_iframe'),
    () => window.top?.document?.querySelector('iframe#tx_viewpage_iframe'),
    () => window.top?.document?.querySelector('iframe[id*="page-preview"], iframe[id*="pagepreview"], iframe[name*="pagepreview"], iframe[name*="preview"]'),
    () => {
      const roots = [document, window.top?.document].filter(Boolean);
      for (const root of roots) {
        for (const candidate of root.querySelectorAll('iframe')) {
          try {
            if (candidate.contentDocument?.body) return candidate;
          } catch { /* cross-origin */ }
        }
      }
      return null;
    },
  ];
  for (const fn of tries) {
    try {
      const iframe = fn();
      if (iframe) return iframe;
    } catch { /* skip */ }
  }
  return null;
}

// Force a Visual Editor element's icon bar visible and return a restore
// list ([node, prop, value, priority]) so it can be reset on clear.
export function revealVeToolbar(el) {
  try {
    el.dispatchEvent(new MouseEvent('mouseenter', { bubbles: true }));
    el.dispatchEvent(new MouseEvent('mouseover', { bubbles: true }));
  } catch { /* event constructor unavailable - ignore */ }

  const restore = [];
  const handle = el.shadowRoot?.querySelector('ve-drag-handle');
  if (handle) {
    for (const [prop, value] of Object.entries(VE_TOOLBAR_FORCE_STYLE)) {
      restore.push([handle, prop, handle.style.getPropertyValue(prop), handle.style.getPropertyPriority(prop)]);
      handle.style.setProperty(prop, value, 'important');
    }
  }
  return restore;
}

export function restoreVeToolbar(restore) {
  if (!Array.isArray(restore)) return;
  for (const [node, prop, value, priority] of restore) {
    try {
      if (value) {
        node.style.setProperty(prop, value, priority || '');
      } else {
        node.style.removeProperty(prop);
      }
    } catch { /* node may be detached */ }
  }
}

function scrollTargetIntoPreview(el, { behavior = 'smooth' } = {}) {
  const doc = el.ownerDocument;
  const win = doc.defaultView;
  if (!win) {
    el.scrollIntoView();
    return;
  }

  const rect = el.getBoundingClientRect();
  const viewportHeight = win.innerHeight || doc.documentElement.clientHeight || 0;
  if (!viewportHeight) {
    el.scrollIntoView({ behavior, block: 'start', inline: 'nearest' });
    return;
  }

  const scrollY = win.scrollY ?? doc.documentElement.scrollTop ?? 0;
  const scrollX = win.scrollX ?? doc.documentElement.scrollLeft ?? 0;
  const maxScrollY = Math.max(0, Math.max(
    doc.documentElement.scrollHeight || 0,
    doc.body?.scrollHeight || 0,
  ) - viewportHeight);
  const topReserve = Math.min(
    LOCATE_TOP_RESERVE,
    Math.max(LOCATE_MIN_TOP_RESERVE, Math.floor(viewportHeight * LOCATE_VIEWPORT_RESERVE_RATIO)),
  );
  const targetTop = Math.max(0, Math.min(maxScrollY, scrollY + rect.top - topReserve));

  const viewportWidth = win.innerWidth || doc.documentElement.clientWidth || 0;
  const maxScrollX = Math.max(0, Math.max(
    doc.documentElement.scrollWidth || 0,
    doc.body?.scrollWidth || 0,
  ) - viewportWidth);
  let targetLeft = scrollX;
  if (viewportWidth > 0 && rect.left < LOCATE_HORIZONTAL_MARGIN) {
    targetLeft = scrollX + rect.left - LOCATE_HORIZONTAL_MARGIN;
  } else if (viewportWidth > 0 && rect.right > viewportWidth - LOCATE_HORIZONTAL_MARGIN) {
    targetLeft = scrollX + rect.right - viewportWidth + LOCATE_HORIZONTAL_MARGIN;
  }
  targetLeft = Math.max(0, Math.min(maxScrollX, targetLeft));

  try {
    win.scrollTo({ top: targetTop, left: targetLeft, behavior });
  } catch {
    win.scrollTo(targetLeft, targetTop);
  }
}

export function highlightInIframe(host, item, { announce = false, behavior = 'smooth' } = {}) {
  clearIframeHighlight(host);
  const target = locateTarget(item);
  const liveUid = target.liveUid;
  const workspaceUid = target.workspaceUid;
  if (!liveUid && !workspaceUid) return;

  const located = locateInAnyIframe(host, item);
  if (!located) {
    if (announce) {
      const allIframes = collectIframes();
      const accessible = allIframes.filter((f) => {
        try { return !!f.contentDocument?.body; } catch { return false; }
      });
      const gated = accessible.filter((f) => isPreviewGate(f.contentDocument));
      if (accessible.length === 0) {
        const hint = host._config.hasVisualEditor
          ? label(host, 'preview.noIframe.visualEditor')
          : (host._config.hasViewpage
              ? label(host, 'preview.noIframe.viewpage')
              : label(host, 'preview.noIframe.install'));
        Notification.info(label(host, 'preview.show.title'), hint, 5);
      } else if (gated.length === accessible.length) {
        Notification.info(
          label(host, 'preview.show.title'),
          label(host, 'preview.loginHint'),
          10,
        );
      } else {
        console.warn('[easy-workspace] could not locate content element', { liveUid, workspaceUid, iframeCount: accessible.length });
        Notification.warning(
          label(host, 'preview.show.title'),
          label(host, 'preview.notFound', { count: accessible.length, liveUid, workspaceUid }),
          8,
        );
      }
    }
    return;
  }
  const { el, isVeWrapper } = located;

  scrollTargetIntoPreview(el, { behavior });

  if (isVeWrapper) {
    host._iframeHighlight = { el, isVeWrapper: true, veRestore: revealVeToolbar(el) };
    return;
  }

  const previous = {};
  for (const key of Object.keys(IFRAME_HIGHLIGHT_STYLE)) {
    previous[key] = el.style[key];
  }
  Object.assign(el.style, IFRAME_HIGHLIGHT_STYLE);
  host._iframeHighlight = { el, isVeWrapper: false, previous };
}

export function clearIframeHighlight(host) {
  const current = host._iframeHighlight;
  if (current) {
    try {
      if (current.isVeWrapper) {
        current.el.dispatchEvent(new MouseEvent('mouseleave', { bubbles: true }));
        restoreVeToolbar(current.veRestore);
      } else if (current.previous) {
        for (const [key, value] of Object.entries(current.previous)) {
          current.el.style[key] = value ?? '';
        }
      }
    } catch { /* element may already be detached */ }
    host._iframeHighlight = null;
  }
  for (const iframe of collectIframes()) {
    let doc;
    try { doc = iframe.contentDocument; } catch { doc = null; }
    if (!doc) continue;
    doc.querySelectorAll('.wew-discard-tag').forEach((el) => el.remove());
  }
}

function isPreviewIframe(iframe) {
  return iframe?.id === 'visual-editor-iframe' || /[?&]editMode=/.test(iframe?.src || '');
}

export function reloadPreviewIframes(onReloaded = null) {
  let reloaded = 0;
  for (const iframe of collectIframes()) {
    if (!isPreviewIframe(iframe)) continue;
    if (typeof onReloaded === 'function') {
      const handler = () => {
        iframe.removeEventListener('load', handler);
        onReloaded(iframe);
      };
      iframe.addEventListener('load', handler);
    }
    try {
      iframe.contentWindow.location.reload();
    } catch {
      iframe.src = iframe.src;
    }
    reloaded++;
  }
  return reloaded;
}

/**
 * Reload preview iframes after a discard and keep the editor's eye on the
 * affected element: re-center it and flash a confirmation. If the element
 * no longer exists (a new record was discarded, a delete was cancelled),
 * restore the prior scroll position instead of snapping to the page header.
 */
export function reloadPreviewAndRefocus(host, item) {
  const priorScroll = new WeakMap();
  for (const iframe of collectIframes()) {
    if (!isPreviewIframe(iframe)) continue;
    try {
      const win = iframe.contentWindow;
      if (win) priorScroll.set(iframe, { x: win.scrollX || 0, y: win.scrollY || 0 });
    } catch { /* cross-origin - cannot read scroll */ }
  }

  return reloadPreviewIframes((iframe) => {
    refocusAfterReload(iframe, item, priorScroll.get(iframe));
  });
}

function refocusAfterReload(iframe, item, prior) {
  let doc;
  let win;
  try {
    doc = iframe.contentDocument;
    win = iframe.contentWindow;
  } catch {
    return; // cross-origin preview - leave it alone
  }
  if (!doc || !win) return;

  // The reverted content may render a tick after the load event fires;
  // poll briefly before deciding the element is truly gone.
  const settle = (attempt) => {
    const located = locateInDoc(doc, item);
    if (located) {
      scrollTargetIntoPreview(located.el);
      flashConfirmation(located.el, located.isVeWrapper, win);
      return;
    }
    if (attempt < 8) {
      win.setTimeout(() => settle(attempt + 1), 120);
      return;
    }
    if (prior) {
      try { win.scrollTo(prior.x, prior.y); } catch { /* ignore */ }
    }
  };
  settle(0);
}

function flashConfirmation(el, isVeWrapper, win) {
  const previous = {};
  for (const key of Object.keys(CONFIRM_FLASH_STYLE)) {
    previous[key] = el.style[key];
  }
  Object.assign(el.style, CONFIRM_FLASH_STYLE);

  const veRestore = isVeWrapper ? revealVeToolbar(el) : null;

  win.setTimeout(() => {
    try {
      for (const [key, value] of Object.entries(previous)) {
        el.style[key] = value ?? '';
      }
      restoreVeToolbar(veRestore);
    } catch { /* element detached during the flash */ }
  }, CONFIRM_FLASH_DURATION);
}

export function isKnownPreviewWindow(host, source) {
  if (!source) return false;
  for (const iframe of collectIframes()) {
    try {
      if (isKnownPreviewFrame(iframe) && iframe.contentWindow === source) {
        return true;
      }
    } catch { /* cross-origin frame access can throw */ }
  }
  return false;
}

export function previewDiscard(host, item) {
  const located = locateInAnyIframe(host, item);
  if (!located) return;
  highlightInIframe(host, item, { announce: false, behavior: 'auto' });
  try {
    const doc = located.doc;
    const existing = doc.querySelector('.wew-discard-tag');
    if (existing) existing.remove();
    const title = label(host, discardTagTitleKey(item), { title: item.title || '' });
    const subtitle = label(host, discardTagSubtitleKey(item), { title: item.title || '' });
    const tag = doc.createElement('div');
    tag.className = 'wew-discard-tag';
    Object.assign(tag.style, {
      position: 'absolute',
      zIndex: '999999',
      padding: '4px 9px 5px',
      fontSize: '11px',
      lineHeight: '1.25',
      background: 'rgba(220, 53, 69, 0.78)',
      color: '#fff',
      border: '1px solid rgba(255, 255, 255, 0.32)',
      borderRadius: '3px',
      boxShadow: '0 3px 12px rgba(0, 0, 0, 0.28)',
      backdropFilter: 'blur(4px) saturate(135%)',
      WebkitBackdropFilter: 'blur(4px) saturate(135%)',
      pointerEvents: 'none',
      whiteSpace: 'normal',
      maxWidth: 'min(360px, calc(100vw - 32px))',
      fontFamily: 'inherit',
      textShadow: '0 1px 1px rgba(0, 0, 0, 0.38)',
    });
    const titleEl = doc.createElement('div');
    titleEl.textContent = title;
    titleEl.style.fontWeight = '600';
    const subtitleEl = doc.createElement('div');
    subtitleEl.textContent = subtitle;
    subtitleEl.style.fontWeight = '400';
    subtitleEl.style.opacity = '.92';
    subtitleEl.style.fontSize = '10.5px';
    tag.appendChild(titleEl);
    tag.appendChild(subtitleEl);
    const rect = located.el.getBoundingClientRect();
    const scrollY = doc.defaultView?.scrollY ?? 0;
    const scrollX = doc.defaultView?.scrollX ?? 0;
    doc.body.appendChild(tag);
    const tagRect = tag.getBoundingClientRect();
    const tagHeight = tagRect.height || 36;
    const tagWidth = tagRect.width || 0;
    const viewportWidth = doc.defaultView?.innerWidth || doc.documentElement.clientWidth || 0;
    const left = viewportWidth > 0
      ? Math.max(scrollX + 8, Math.min(rect.left + scrollX, scrollX + viewportWidth - tagWidth - 8))
      : rect.left + scrollX;
    const viewportHeight = doc.defaultView?.innerHeight || doc.documentElement.clientHeight || 0;
    let top = rect.top + scrollY - tagHeight - 6;
    if (rect.top - tagHeight - 6 < 8) {
      top = rect.bottom + scrollY + 6;
    }
    if (viewportHeight > 0) {
      const minTop = scrollY + 8;
      const maxTop = scrollY + viewportHeight - tagHeight - 8;
      top = Math.max(minTop, Math.min(top, maxTop));
    }
    tag.style.top = `${top}px`;
    tag.style.left = `${left}px`;
  } catch {
    /* DOM access guarded — cross-origin iframes throw silently. */
  }
}
