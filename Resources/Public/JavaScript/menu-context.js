import { DEFAULT_CONFIG } from './menu-constants.js';
import { collectIframes } from './menu-dom-utils.js';

export function readConfig(element) {
  const raw = element.getAttribute('config') || '';
  if (raw === '') {
    return { ...DEFAULT_CONFIG };
  }
  try {
    const parsed = JSON.parse(raw);
    return { ...DEFAULT_CONFIG, ...parsed };
  } catch (e) {
    console.warn('[easy-workspace] Could not parse TSconfig payload, falling back to defaults.', e);
    return { ...DEFAULT_CONFIG };
  }
}

export function formatIcu(message, variables = {}) {
  let formatted = String(message);
  formatted = formatted.replace(
    /\{(\w+),\s*plural,\s*one\s*\{([^{}]*)}\s*other\s*\{([^{}]*)}}/g,
    (_match, name, one, other) => {
      const count = Number(variables[name] ?? 0);
      return (count === 1 ? one : other).replaceAll('#', String(count));
    },
  );
  for (const [name, value] of Object.entries(variables)) {
    formatted = formatted.replaceAll(`{${name}}`, String(value));
  }
  return formatted;
}

export function label(host, key, variables = {}) {
  const labels = host._config.labels || {};
  const message = typeof labels[key] === 'string' && labels[key] !== '' ? labels[key] : key;
  return formatIcu(message, variables);
}

export function configBool(host, key, fallback = false) {
  const value = host._config?.[key];
  if (value === undefined || value === null) return fallback;
  if (typeof value === 'boolean') return value;
  if (typeof value === 'number') return value !== 0;
  if (typeof value === 'string') {
    const normalized = value.trim().toLowerCase();
    if (['1', 'true', 'on', 'yes'].includes(normalized)) return true;
    if (['0', 'false', 'off', 'no', ''].includes(normalized)) return false;
  }
  return Boolean(value);
}

export function buildContextLabel(host, data) {
  const changedCount = (data.items || []).filter((i) => i.isChanged).length;
  const totalCount = (data.items || []).length;
  // Header subtitle: just the counts. The page/news identifier
  // moved to a small footnote at the bottom of the dropdown so it
  // doesn't compete with the workspace name + preview button up
  // top.
  if (host.mode === 'all') {
    return label(host, 'toolbar.context.recordsPending', { total: totalCount, changed: changedCount });
  }
  return label(host, 'toolbar.context.pending', { count: changedCount });
}

export function detectContext(host) {
  const configuredNewsUid = parseInt(String(host._config.newsUid || '0'), 10);
  if (configuredNewsUid > 0) {
    return { pageUid: 0, newsUid: configuredNewsUid };
  }

  const configuredPageUid = parseInt(String(host._config.pageUid || '0'), 10);
  if (configuredPageUid > 0) {
    return { pageUid: configuredPageUid, newsUid: 0 };
  }

  // News detail context wins over the page: when the editor is on a
  // single news article — its frontend detail view in the Visual
  // Editor / preview, or its FormEngine edit form — scope the dropdown
  // to that one article (forNews) instead of the surrounding page.
  const newsUid = detectNewsUid(host);
  if (newsUid > 0) {
    return { pageUid: 0, newsUid };
  }

  // Primary source: v14's ModuleStateStorage tracks the currently
  // selected page in the Web module group (id stored in sessionStorage,
  // mutated whenever the page tree selection changes).
  let pageUid = 0;
  try {
    const storage = window.top?.ModuleStateStorage || window.ModuleStateStorage;
    if (storage && typeof storage.current === 'function') {
      const state = storage.current('web');
      const identifier = parseInt(String(state?.identifier || '0'), 10);
      if (identifier > 0) {
        pageUid = identifier;
      }
    }
  } catch {
    // Cross-frame access errors → fall through to URL parsing.
  }

  // Fallback: URL ?id= parameter (e.g. when a module link was opened
  // before any page-tree selection happened in this session).
  if (pageUid <= 0) {
    const fromUrl = parseInt(new URLSearchParams(window.location.search).get('id') || '0', 10);
    if (fromUrl > 0) {
      pageUid = fromUrl;
    }
  }

  return { pageUid: pageUid > 0 ? pageUid : 0, newsUid: 0 };
}

/**
 * Detect the news article the editor is currently working on, so the
 * dropdown can scope to that single article (its record + the content
 * elements linked via tx_news_related_news) instead of a page.
 */
export function detectNewsUid(host) {
  if (!configBool(host, 'enableNewsBundles', true)) {
    return 0;
  }
  const fromTop = matchNewsUid(window.location.search);
  if (fromTop > 0) {
    return fromTop;
  }
  for (const iframe of collectIframes()) {
    let url = '';
    try {
      url = iframe.contentWindow?.location?.href || '';
    } catch {
      url = '';
    }
    if (!url) {
      url = iframe.src || '';
    }
    const uid = matchNewsUid(url);
    if (uid > 0) {
      return uid;
    }
  }
  return 0;
}

/**
 * Extract a news uid from a URL string. Matches both the news detail
 * plugin signature (tx_news_pi1[news]=N — also alternate plugin
 * namespaces and URL-encoded brackets) and an open FormEngine news
 * edit form (edit[tx_news_domain_model_news][N]). Returns 0 if neither.
 */
export function matchNewsUid(url) {
  if (!url) {
    return 0;
  }
  const str = String(url);
  const detail = str.match(/tx_news_[^=&]*?(?:\[|%5B)news(?:\]|%5D)=(\d+)/i);
  if (detail) {
    return parseInt(detail[1], 10) || 0;
  }
  const edit = str.match(/edit(?:\[|%5B)tx_news_domain_model_news(?:\]|%5D)(?:\[|%5B)(\d+)(?:\]|%5D)/i);
  if (edit) {
    return parseInt(edit[1], 10) || 0;
  }
  return 0;
}

export function detectLanguageUid(host) {
  const stateCandidates = [];
  try {
    const storage = window.top?.ModuleStateStorage || window.ModuleStateStorage;
    if (storage && typeof storage.current === 'function') {
      stateCandidates.push(storage.current('web'));
      stateCandidates.push(storage.current('web_layout'));
      stateCandidates.push(storage.current('web_layout_language'));
    }
  } catch {
    // Cross-frame access errors -> URL parsing below.
  }

  for (const state of stateCandidates) {
    const languageUid = extractLanguageUid(state);
    if (languageUid !== null) return languageUid;
  }

  for (const params of collectSearchParams()) {
    for (const key of ['language', 'sys_language_uid', 'L', 'siteLanguage', 'lang']) {
      const value = params.get(key);
      if (value === null || value === '') continue;
      const languageUid = parseInt(value, 10);
      if (Number.isInteger(languageUid) && languageUid >= 0) {
        return languageUid;
      }
    }
  }

  return null;
}

export function extractLanguageUid(value) {
  if (!value || typeof value !== 'object') return null;
  const directKeys = ['language', 'languageUid', 'languageId', 'sys_language_uid', 'siteLanguage'];
  for (const key of directKeys) {
    const parsed = parseInt(String(value[key] ?? ''), 10);
    if (Number.isInteger(parsed) && parsed >= 0) return parsed;
  }
  for (const nestedKey of ['settings', 'moduleData', 'data']) {
    const nested = extractLanguageUid(value[nestedKey]);
    if (nested !== null) return nested;
  }
  return null;
}

export function collectSearchParams() {
  const seen = new Set();
  const params = [];
  const addUrl = (url) => {
    if (!url || seen.has(url)) return;
    seen.add(url);
    try {
      params.push(new URL(url, window.location.href).searchParams);
    } catch { /* ignore invalid/detached URLs */ }
  };

  addUrl(window.location.href);
  try { addUrl(window.top?.location?.href); } catch { /* cross-origin */ }

  const roots = [document];
  try {
    if (window.top?.document && window.top.document !== document) {
      roots.push(window.top.document);
    }
  } catch { /* cross-origin */ }

  for (const root of roots) {
    for (const iframe of root.querySelectorAll('iframe')) {
      addUrl(iframe.src);
      try { addUrl(iframe.contentWindow?.location?.href); } catch { /* cross-origin */ }
    }
  }

  return params;
}
