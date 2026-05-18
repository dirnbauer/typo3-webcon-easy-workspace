/**
 * Easy Workspace backend module — interactive bindings.
 *
 * The module's HTML is fully server-rendered with Fluid. This script
 * is the small client-side complement that wires the rendered DOM
 * into TYPO3 Core's modal / notification / ajax APIs:
 *
 *   - Discard button:  Modal.confirm → POST → reload
 *   - Diff button:     Modal.advanced({type: ajax}) on the diff endpoint
 *   - Edit / open:     Modal.advanced({type: iframe}, position: sheet)
 *   - Preview link:    AjaxRequest → clipboard + Notification
 *   - Publish form:    select-all sync + deselect button
 *
 * There is no client-side rendering. The Fluid layout is the source
 * of truth; this file only attaches behaviour to it.
 */

import AjaxRequest from '@typo3/core/ajax/ajax-request.js';
import Notification from '@typo3/backend/notification.js';
import Modal, { Sizes as ModalSizes, Types as ModalTypes, Positions as ModalPositions } from '@typo3/backend/modal.js';
import { SeverityEnum } from '@typo3/backend/enum/severity.js';
import '@typo3/backend/multi-record-selection.js';

const root = document.querySelector('[data-wew-module]');
const LABELS = parseLabelMap(root);

if (root) {
  init(root);
  initDocHeader(root);
}

function parseLabelMap(container) {
  if (!container) return {};
  try {
    return JSON.parse(container.dataset.wewLabels || '{}');
  } catch (error) {
    console.warn('[easy-workspace-module] could not parse label map; falling back to keys.', error);
    return {};
  }
}

function init(container) {
  const diffUrl = container.dataset.wewDiffUrl || '';

  // Discard — one click, one confirm modal, one POST, one reload.
  container.addEventListener('click', (event) => {
    const discardBtn = event.target.closest('[data-wew-discard]');
    if (discardBtn) {
      event.preventDefault();
      onDiscard(discardBtn);
      return;
    }

    const editBtn = event.target.closest('[data-wew-edit]');
    if (editBtn) {
      event.preventDefault();
      onEdit(editBtn);
      return;
    }

    const diffBtn = event.target.closest('[data-wew-diff]');
    if (diffBtn && diffUrl) {
      event.preventDefault();
      onDiff(diffBtn, diffUrl);
      return;
    }

    const deselectBtn = event.target.closest('[data-wew-deselect-all]');
    if (deselectBtn) {
      event.preventDefault();
      onDeselectAll(container);
      return;
    }

    const relatedOpenAllBtn = event.target.closest('[data-wew-related-open-all]');
    if (relatedOpenAllBtn) {
      event.preventDefault();
      setRelatedChangesOpen(relatedOpenAllBtn, true);
      return;
    }

    const relatedCloseAllBtn = event.target.closest('[data-wew-related-close-all]');
    if (relatedCloseAllBtn) {
      event.preventDefault();
      setRelatedChangesOpen(relatedCloseAllBtn, false);
    }
  });

  // Selection counter updates as the editor (de)checks rows.
  container.addEventListener('change', (event) => {
    const check = event.target.closest('[data-wew-row-check]');
    if (!check) return;
    updateSelectionSummary(container);
  });

  updateSelectionSummary(container);
  initRelatedChangeControls(container);
}

function initDocHeader(container) {
  const previewUrl = container.dataset.wewPreviewUrl || '';
  document.addEventListener('click', (event) => {
    const previewBtn = event.target.closest('[data-wew-preview-trigger]');
    if (!previewBtn || !previewUrl) {
      return;
    }
    event.preventDefault();
    onPreview(previewBtn, previewUrl);
  });
}

function onDiscard(btn) {
  const table = btn.dataset.wewTable || '';
  const workspaceUid = parseInt(btn.dataset.wewWorkspaceUid || '0', 10);
  const title = btn.dataset.wewTitle || '';
  if (!table || workspaceUid <= 0) return;

  const form = btn.closest('form');
  const modal = Modal.confirm(
    label('discard.modal.title'),
    formatMessage(label('discard.modal.message'), { title, table }),
    SeverityEnum.warning,
    [
      { text: label('discard.modal.cancel'), btnClass: 'btn-default', name: 'cancel', trigger: () => modal.hideModal() },
      { text: label('discard.modal.confirm'), btnClass: 'btn-warning', name: 'discard', active: true, trigger: () => modal.hideModal() },
    ],
  );
  modal.addEventListener('button.clicked', (event) => {
    if (event.target?.getAttribute('name') !== 'discard') return;
    submitDiscardForm(form || btn.closest('[data-wew-module]'), table, workspaceUid);
  });
}

function submitDiscardForm(referenceForm, table, workspaceUid) {
  // Submit a synthetic POST form so the controller's redirect-back
  // flow drives the reload — same flow as the publish button.
  const action = (referenceForm?.action)
    || (referenceForm?.querySelector?.('form')?.action)
    || window.location.href;
  const form = document.createElement('form');
  form.method = 'post';
  form.action = action;
  appendField(form, '_action', 'discard');
  appendField(form, 'table', table);
  appendField(form, 'workspaceUid', String(workspaceUid));
  document.body.appendChild(form);
  form.submit();
}

function appendField(form, name, value) {
  const input = document.createElement('input');
  input.type = 'hidden';
  input.name = name;
  input.value = value;
  form.appendChild(input);
}

function onEdit(btn) {
  const editUrl = btn.dataset.wewEditUrl || '';
  const contextual = btn.dataset.wewContextual === '1';
  const title = btn.dataset.wewTitle || '';
  if (!editUrl) {
    Notification.info(label('edit.title'), label('edit.noForm'));
    return;
  }
  Modal.advanced({
    title: contextual ? '' : formatMessage(label('edit.modalTitle'), { title }),
    type: ModalTypes.iframe,
    content: editUrl,
    size: contextual ? ModalSizes.expand : ModalSizes.large,
    position: ModalPositions.sheet,
    hideHeader: contextual,
    additionalCssClasses: ['wew-edit-modal-shell'],
  });
}

function onDiff(btn, diffEndpoint) {
  const table = btn.dataset.wewTable || '';
  const workspaceUid = parseInt(btn.dataset.wewWorkspaceUid || '0', 10);
  const title = btn.dataset.wewTitle || label('diff.noTitle');
  if (!table || workspaceUid <= 0) return;
  const url = `${diffEndpoint}&table=${encodeURIComponent(table)}&workspaceUid=${workspaceUid}`;
  Modal.advanced({
    title: formatMessage(label('diff.modal.historyTitle'), { title }),
    type: ModalTypes.ajax,
    content: url,
    size: ModalSizes.large,
    additionalCssClasses: ['wew-diff-modal-shell'],
  });
}

async function onPreview(btn, endpoint) {
  const pageUid = parseInt(btn.dataset.wewPreviewPageUid || '0', 10);
  if (pageUid <= 0) return;
  btn.disabled = true;
  try {
    const response = await new AjaxRequest(endpoint).withQueryArguments({ pageUid }).get();
    const data = await response.resolve();
    if (!data?.url) {
      Notification.error(label('preview.link.title'), data?.error || label('preview.link.noUrl'));
      return;
    }
    await writeToClipboard(data.url);
    Notification.success(label('preview.link.copied'), data.url, 4);
  } catch (error) {
    Notification.error(label('preview.link.title'), error?.message || label('error.unexpected'));
  } finally {
    btn.disabled = false;
  }
}

function onDeselectAll(container) {
  container.querySelectorAll('[data-wew-row-check]').forEach((input) => {
    setRowCheckState(input, false);
  });
  updateSelectionSummary(container);
}

function setRowCheckState(input, checked) {
  if (input.checked === checked) {
    return;
  }
  input.checked = checked;
  input.dispatchEvent(new Event('change', { bubbles: true }));
}

function initRelatedChangeControls(container) {
  container.querySelectorAll('[data-wew-related-open-all], [data-wew-related-close-all]').forEach((button) => {
    updateRelatedChangeControls(button.closest('.card') || container);
  });

  container.addEventListener('toggle', (event) => {
    if (!event.target.closest?.('[data-wew-related-changes]')) {
      return;
    }
    updateRelatedChangeControls(event.target.closest('.card') || container);
  }, true);
}

function setRelatedChangesOpen(button, open) {
  const scope = button.closest('.card') || button.closest('[data-wew-module]') || document;
  scope.querySelectorAll('[data-wew-related-changes]').forEach((details) => {
    details.open = open;
  });
  updateRelatedChangeControls(scope);
}

function updateRelatedChangeControls(scope) {
  const relatedChanges = Array.from(scope.querySelectorAll('[data-wew-related-changes]'));
  const openCount = relatedChanges.filter((details) => details.open).length;
  scope.querySelectorAll('[data-wew-related-open-all]').forEach((button) => {
    button.disabled = relatedChanges.length === 0 || openCount === relatedChanges.length;
  });
  scope.querySelectorAll('[data-wew-related-close-all]').forEach((button) => {
    button.disabled = relatedChanges.length === 0 || openCount === 0;
  });
}

function updateSelectionSummary(container) {
  const total = parseInt(
    container.querySelector('[data-wew-publishbar]')?.dataset.wewTotal || '0',
    10,
  );
  const selected = container.querySelectorAll('[data-wew-row-check]:checked').length;
  const counter = container.querySelector('[data-wew-selected-count]');
  if (counter) counter.textContent = String(selected);
  const summary = container.querySelector('[data-wew-publishbar-summary]');
  if (summary) {
    summary.textContent = selected > 0
      ? formatMessage(label('module.publishBar.summary'), { count: selected })
      : label('module.publishBar.unselected');
  }
  const submit = container.querySelector('[data-wew-publish-submit]');
  if (submit) {
    submit.disabled = selected === 0;
  }
  const submitLabel = container.querySelector('[data-wew-publish-label]');
  if (submitLabel) {
    const base = label('toolbar.publishToLive');
    submitLabel.textContent = selected > 0 ? `${base} (${selected})` : base;
  }
  if (Number.isFinite(total)) {
    const deselect = container.querySelector('[data-wew-deselect-all]');
    if (deselect) deselect.hidden = selected === 0;
  }
}

/**
 * Translation lookup driven by the single `data-wew-labels` JSON
 * blob the Fluid template emits on the module root. We avoid
 * bundling a label table on the JS side — the source of truth stays
 * on the PHP/XLF side.
 *
 * Fallback to the key itself if the entry is missing — the JS
 * never crashes if a label slips through; it just renders the key
 * which makes the gap obvious during development.
 */
function label(key) {
  return typeof LABELS[key] === 'string' && LABELS[key] !== '' ? LABELS[key] : key;
}

function formatMessage(template, vars) {
  let out = String(template);
  out = out.replace(
    /\{(\w+),\s*plural,\s*one\s*\{([^{}]*)\}\s*other\s*\{([^{}]*)\}\}/g,
    (_, name, one, other) => {
      const count = Number(vars?.[name] ?? 0);
      return (count === 1 ? one : other).replaceAll('#', String(count));
    },
  );
  for (const [name, value] of Object.entries(vars || {})) {
    out = out.replaceAll(`{${name}}`, String(value));
  }
  return out;
}

async function writeToClipboard(text) {
  if (navigator.clipboard && window.isSecureContext) {
    await navigator.clipboard.writeText(text);
    return;
  }
  const textarea = document.createElement('textarea');
  textarea.value = text;
  textarea.setAttribute('readonly', '');
  textarea.style.position = 'fixed';
  textarea.style.left = '-9999px';
  document.body.appendChild(textarea);
  textarea.select();
  try {
    document.execCommand('copy');
  } finally {
    document.body.removeChild(textarea);
  }
}
