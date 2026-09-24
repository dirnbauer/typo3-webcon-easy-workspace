/**
 * A scripted five-minute Visual Editor session, shaped like the one measured
 * on production (page 1149, workspace "Staging"): the editor works in the
 * preview and keeps clicking back into the backend frame, saves every half
 * minute, twice moves to another page in the page tree, checks the dropdown
 * once and spends twenty seconds in another window.
 *
 * `env` drives one BadgeSync implementation:
 *   advance(ms)          let time pass (fake timers)
 *   focus()              window focus — every click from the nested preview
 *                        iframe back into the top frame fires one
 *   visibility(hidden)   the tab is hidden / shown again
 *   docEvent(name)       a Core event on the top document
 *   veSave(pageTree)     the signals of one Visual Editor save
 *   navigate(pageUid)    a page-tree click: module-state events, then the
 *                        module finishes loading 700 ms later
 *   openDropdown() / closeDropdown()
 *
 * Returns nothing; the caller counts requests.
 */
export const SESSION_MS = 5 * 60 * 1000;

export async function playEditingSession(env) {
  const events = [];
  for (let t = 2000; t < SESSION_MS; t += 4000) {
    events.push([t, () => env.focus()]);
  }
  for (let t = 31000, n = 0; t < SESSION_MS; t += 30000, n++) {
    // Every fourth save also changed page properties, so VE refreshes the tree.
    events.push([t, () => env.veSave(n % 4 === 3)]);
  }
  events.push([100500, () => env.navigate(1150)]);
  events.push([200500, () => env.navigate(1149)]);
  events.push([150500, () => env.openDropdown()]);
  events.push([156500, () => env.closeDropdown()]);
  events.push([240500, () => env.visibility(true)]);
  events.push([260500, () => env.visibility(false)]);
  events.sort((a, b) => a[0] - b[0]);

  let now = 0;
  for (const [at, action] of events) {
    await env.advance(at - now);
    now = at;
    await action();
  }
  await env.advance(SESSION_MS - now);
}
