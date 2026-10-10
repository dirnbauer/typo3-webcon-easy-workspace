import { DEFAULT_CONFIG } from '@webconsulting/webcon-easy-workspace/menu-constants.js';
import { xliffLabels } from './labels.js';

/**
 * Items and hosts shaped like the server contract of 1.11 (lab page 2228
 * "Product": an English hero with German, Chinese and Hungarian
 * translations).
 */
export const LANGUAGES = {
  0: { uid: 0, title: 'English', flag: 'flags-us', code: 'EN', hreflang: 'en-US' },
  1: { uid: 1, title: 'German', flag: 'flags-de', code: 'DE', hreflang: 'de-AT' },
  2: { uid: 2, title: 'Chinese (Simplified)', flag: 'flags-cn', code: 'ZH', hreflang: 'zh-CN' },
  3: { uid: 3, title: 'Hungarian', flag: 'flags-hu', code: 'HU', hreflang: 'hu-HU' },
};

export const GERMAN = xliffLabels('de');

export function companion(languageUid, kind = 'identical', fields = []) {
  return {
    table: 'tt_content', workspaceUid: 161266 + languageUid, liveUid: 158000 + languageUid, languageUid, kind, fields,
    editUrl: `#edit-${languageUid}`, contextualEditUrl: `#contextual-${languageUid}`,
  };
}

export function change(uid, extra = {}) {
  return {
    table: 'tt_content', workspaceUid: uid, liveUid: uid - 100, title: `Element ${uid}`, kindKey: 'modified',
    isChanged: true, contentChanged: true, editUrl: '#', historyUrl: '#', languageUid: 0, role: 'change',
    companions: [], dependents: [], copyGroup: '', typeLabel: 'Hero-Bereich', tableLabel: 'Seiteninhalt', colPosLabel: 'Normal',
    ...extra,
  };
}

export function copy(uid, extra = {}) {
  return change(uid, { role: 'copy', contentChanged: false, copyGroup: 'tt_content:158035', ...extra });
}

export function host(items = [], { selected = null, labels = GERMAN, config = {}, ...overrides } = {}) {
  return {
    items,
    languages: LANGUAGES,
    viewLanguages: [0],
    selection: new Set(selected ?? items.filter((item) => item.role === 'change').map((item) => `${item.table}:${item.workspaceUid}`)),
    publishing: false,
    cleaning: false,
    splitOpen: false,
    pageUid: 2228,
    newsUid: 0,
    userUid: 1,
    titleId: 'wew-title-test',
    summaryId: 'wew-summary-test',
    state: items.length > 0 ? 'loaded' : 'empty',
    badgeCount: items.filter((item) => item.role === 'change').length,
    workspaceTitle: 'AI Review Test',
    stage: { id: 0, label: 'Bearbeitung' },
    contextRecord: { title: 'Product', path: 'Desiderio', iconIdentifier: 'apps-pagetree-page-default' },
    _config: { ...DEFAULT_CONFIG, enableRevert: true, enablePreviewLink: true, moduleUrl: '#module', ...config, labels },
    sectionState: new Map(),
    toggleSection(sectionId) {
      const open = this._effectiveSections?.get(sectionId) ?? false;
      this.sectionState = new Map(this.sectionState).set(sectionId, !open);
    },
    handleRowClick() {},
    handleRowCheck() {},
    handleRowAction() {},
    handleCompanionEdit() {},
    handleDiscardCopies() {},
    handleOpenModule() {},
    handleRefresh() {},
    handleSelectAll() {},
    handlePublish() {},
    _highlightInIframe() {},
    _clearIframeHighlight() {},
    _previewDiscard() {},
    ...overrides,
  };
}
