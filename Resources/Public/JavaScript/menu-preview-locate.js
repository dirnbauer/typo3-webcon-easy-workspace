import Notification from '@typo3/backend/notification.js';
import { IFRAME_HIGHLIGHT_STYLE } from './menu-constants.js';
import { label } from './menu-context.js';
import { collectIframes, isKnownPreviewFrame } from './menu-dom-utils.js';

export { isKnownPreviewFrame } from './menu-dom-utils.js';

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

export function locateInAnyIframe(host, item) {
  const target = locateTarget(item);
  if (target.table !== 'tt_content') return null;
  const uids = [target.liveUid, target.workspaceUid]
    .map((n) => parseInt(n, 10))
    .filter((n) => n > 0)
    .filter((n, i, arr) => arr.indexOf(n) === i);
  if (uids.length === 0) return null;

  const veSelector = uids.flatMap((u) => [
    `ve-content-element[uid="${u}"][table="tt_content"]`,
    `ve-content-element[id="tt_content:${u}"]`,
  ]).join(', ');

  for (const iframe of collectIframes()) {
    let doc;
    try {
      doc = iframe.contentDocument || iframe.contentWindow?.document;
    } catch { continue; }
    if (!doc) continue;

    const veEl = doc.querySelector(veSelector);
    if (veEl) return { iframe, doc, el: veEl, isVeWrapper: true };

    for (const u of uids) {
      const el = findContentElement(doc, u);
      if (el) return { iframe, doc, el, isVeWrapper: false };
    }
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

export function logIframeDiagnostics(iframes, liveUid, workspaceUid) {
  const payload = [];
  for (const [i, iframe] of iframes.entries()) {
    const doc = iframe.contentDocument;
    if (!doc?.body) continue;
    const veWrappers = Array.from(doc.querySelectorAll('ve-content-element'));
    const dataUidElements = Array.from(doc.querySelectorAll('[data-uid]'));
    const cElements = Array.from(doc.querySelectorAll('[id]'))
      .map((el) => el.id)
      .filter((id) => /^c\d+$/.test(id) || /tt_content/.test(id));
    const haystack = doc.body.innerHTML;
    const textHits = {};
    for (const uid of [liveUid, workspaceUid].filter((u, idx, arr) => u && arr.indexOf(u) === idx)) {
      const re = new RegExp(`["'\\s=>](c${uid}|tt_content:${uid}|uid="${uid}")["'\\s<]`, 'g');
      textHits[`uid_${uid}`] = haystack.match(re)?.slice(0, 5) || null;
    }
    payload.push({
      iframe: i,
      id: iframe.id || '(none)',
      src: iframe.src?.slice(0, 120),
      bodyLength: haystack.length,
      veContentElementCount: veWrappers.length,
      veContentElements: veWrappers.slice(0, 20).map((el) => ({
        uid: el.getAttribute('uid'),
        table: el.getAttribute('table'),
        id: el.getAttribute('id'),
        CType: el.getAttribute('CType'),
      })),
      idCElementCount: cElements.length,
      idCElements: cElements.slice(0, 30),
      dataUidElementCount: dataUidElements.length,
      dataUidElements: dataUidElements.slice(0, 20).map((el) => ({
        tag: el.tagName.toLowerCase(),
        'data-uid': el.getAttribute('data-uid'),
        'data-table': el.getAttribute('data-table'),
        'data-content-uid': el.getAttribute('data-content-uid'),
        id: el.id || null,
      })),
      textHits,
    });
  }
  console.warn(
    `[easy-workspace] eye couldn't locate uid ${liveUid} / ws #${workspaceUid}. Iframe diagnostics:`,
    payload,
  );
}

export function highlightInIframe(host, item, { announce = false } = {}) {
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
        logIframeDiagnostics(accessible, liveUid, workspaceUid);
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

  try {
    el.scrollIntoView({ behavior: 'smooth', block: 'center', inline: 'nearest' });
  } catch {
    el.scrollIntoView();
  }

  if (isVeWrapper) {
    try {
      el.dispatchEvent(new MouseEvent('mouseenter', { bubbles: true }));
      el.dispatchEvent(new MouseEvent('mouseover', { bubbles: true }));
    } catch { /* event constructor failed - ignore */ }
    host._iframeHighlight = { el, isVeWrapper: true };
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

export function reloadPreviewIframes() {
  let reloaded = 0;
  for (const iframe of collectIframes()) {
    const src = iframe.src || '';
    const isPreview = iframe.id === 'visual-editor-iframe'
      || /[?&]editMode=/.test(src);
    if (!isPreview) continue;
    try {
      iframe.contentWindow.location.reload();
    } catch {
      // eslint-disable-next-line no-self-assign
      iframe.src = iframe.src;
    }
    reloaded++;
  }
  return reloaded;
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
  highlightInIframe(host, item, { announce: false });
  try {
    const doc = located.doc;
    const existing = doc.querySelector('.wew-discard-tag');
    if (existing) existing.remove();
    const title = label(host, 'discardTag.title');
    const subtitle = label(host, 'discardTag.subtitle');
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
      whiteSpace: 'nowrap',
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
    const tagHeight = tag.getBoundingClientRect().height || 36;
    tag.style.top = `${rect.top + scrollY - tagHeight - 6}px`;
    tag.style.left = `${rect.left + scrollX}px`;
  } catch {
    /* DOM access guarded — cross-origin iframes throw silently. */
  }
}
