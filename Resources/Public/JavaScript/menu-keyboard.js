import { findItemByKey } from '@webconsulting/webcon-easy-workspace/menu-toolbar-helpers.js';

/**
 * Roving focus for the list, on "stops" (`[data-wew-stop]`, in DOM order):
 * the checkbox of each change row, each section toggle, the first chip of
 * each cleanup line. Native controls, so a screen reader announces their
 * role and state.
 *
 * Exactly one stop is in the tab order. With it, its actions are too (a
 * row's buttons and translation chips, a cleanup line's other chips) and,
 * for a section toggle, the section's checkbox and cleanup button — so Tab
 * walks from the active stop through what belongs to it, then on to the
 * footer. Arrow keys move between stops; ArrowRight/ArrowLeft step into and
 * out of the actions and open and close sections; Enter on a row checkbox
 * opens the editor; Space stays native.
 */
const STOP = '[data-wew-stop]';
const SCOPE = '[data-wew-row], [data-wew-copy-group], [data-wew-section-head]';
const ACTIONS = '[data-wew-action]:not(:disabled), [data-wew-companion]:not(:disabled)';
const HEAD_CONTROLS = '[data-wew-section-check], [data-wew-discard-copies]';
const MANAGED = `${STOP}, [data-wew-action], [data-wew-companion], ${HEAD_CONTROLS}`;

export function stopsOf(host) {
  return Array.from(host.querySelectorAll(STOP));
}

/** The stop an element belongs to (itself, or the stop of its row, line or section head). */
export function stopOf(element) {
  if (!element?.closest) return null;
  if (element.matches(STOP)) return element;
  return element.closest(SCOPE)?.querySelector(STOP) || null;
}

/** The actions of a stop, in DOM order, without the stop itself. */
export function actionsOf(stop) {
  const scope = stop?.closest(SCOPE);
  if (!scope || scope.matches('[data-wew-section-head]')) return [];
  return Array.from(scope.querySelectorAll(ACTIONS)).filter((element) => element !== stop && !element.matches(STOP));
}

function headControlsOf(stop) {
  const scope = stop?.closest('[data-wew-section-head]');
  return scope ? Array.from(scope.querySelectorAll(HEAD_CONTROLS)) : [];
}

export function setActiveStop(host, activeStop) {
  const enabled = new Set(activeStop ? [activeStop, ...actionsOf(activeStop), ...headControlsOf(activeStop)] : []);
  for (const element of host.querySelectorAll(MANAGED)) {
    element.setAttribute('tabindex', enabled.has(element) ? '0' : '-1');
  }
  host._activeStop = activeStop || null;
}

/**
 * After a render: keep the active stop when it is still there, else make
 * the first stop the tabbable one, so the list is always one Tab away.
 */
export function syncRovingTabindex(host) {
  const stops = stopsOf(host);
  if (stops.length === 0) {
    host._activeStop = null;
    return;
  }
  const current = host._activeStop && host._activeStop.isConnected && stops.includes(host._activeStop) ? host._activeStop : stops[0];
  setActiveStop(host, current);
}

export function onListFocusIn(host, event) {
  const stop = stopOf(event.target);
  if (stop && stop !== host._activeStop) setActiveStop(host, stop);
  // What had focus and where its stop was: a render that removes it hands
  // focus to the stop now at that place (see the menu's updated()).
  if (stop) {
    host._lastFocused = event.target;
    host._lastFocusIndex = stopsOf(host).indexOf(stop);
  }
}

function isToggle(element) {
  return element?.hasAttribute?.('data-wew-section-toggle');
}

function sectionToggleAbove(element) {
  const body = element.closest('[data-wew-section-body]');
  return body?.closest('[data-wew-section]')?.querySelector('[data-wew-section-toggle]') || null;
}

export function onListKeydown(host, event) {
  if (event.defaultPrevented || event.altKey || event.ctrlKey || event.metaKey) return;
  const target = event.target;
  const stop = stopOf(target);
  if (!stop) return;
  const stops = stopsOf(host);
  const index = stops.indexOf(stop);
  const actions = actionsOf(stop);
  const actionIndex = actions.indexOf(target);
  const onStop = target === stop;
  const focus = (element) => {
    if (!element) return;
    event.preventDefault();
    if (stops.includes(element)) setActiveStop(host, element);
    element.focus();
  };
  switch (event.key) {
    case 'ArrowDown': focus(stops[index + 1]); break;
    case 'ArrowUp': focus(stops[index - 1]); break;
    case 'Home': focus(stops[0]); break;
    case 'End': focus(stops.at(-1)); break;
    case 'ArrowRight':
      if (onStop && isToggle(stop)) {
        event.preventDefault();
        if (stop.getAttribute('aria-expanded') !== 'true') host.toggleSection?.(stop.getAttribute('data-wew-section-toggle'));
      } else {
        focus(onStop ? actions[0] : actions[actionIndex + 1]);
      }
      break;
    case 'ArrowLeft':
      if (onStop && isToggle(stop)) {
        event.preventDefault();
        if (stop.getAttribute('aria-expanded') === 'true') host.toggleSection?.(stop.getAttribute('data-wew-section-toggle'));
      } else if (!onStop && actionIndex >= 0) {
        focus(actionIndex === 0 ? stop : actions[actionIndex - 1]);
      } else if (onStop) {
        focus(sectionToggleAbove(stop));
      }
      break;
    case 'Enter': {
      // Space toggles the checkbox natively; Enter opens the editor.
      if (!onStop || !stop.hasAttribute('data-wew-row-check')) return;
      const row = stop.closest('[data-wew-row]');
      const item = findItemByKey(host, row?.getAttribute('data-wew-key') || '');
      if (item) host.handleRowAction(event, 'edit', item);
      break;
    }
    default: break;
  }
}

/**
 * What a layout's `onKeydown` hook gets to work with: the stop the event
 * belongs to and, for a change row, its item.
 */
export function keyboardContext(host, event) {
  const stop = stopOf(event.target);
  const row = event.target?.closest?.('[data-wew-row]') || null;
  return {
    stop,
    row,
    item: row ? findItemByKey(host, row.getAttribute('data-wew-key') || '') : null,
    inList: Boolean(event.target?.closest?.('.wew-list')),
  };
}
