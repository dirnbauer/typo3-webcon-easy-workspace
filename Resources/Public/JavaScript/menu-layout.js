/**
 * The shared templates' access to the active layout profile.
 *
 * The component resolves the profile once (layouts/index.js, layoutFor())
 * and keeps it on `host.layoutProfile`. Templates never import the registry
 * (the profiles import the templates for their defaults), they ask here:
 * a flag, or a renderer hook that falls back to the default renderer.
 *
 * A host without a profile (unit tests, an old cached config) renders the
 * defaults, which are the "clarity" presentation.
 */

/** Flags every profile may set; a profile that leaves one out gets this value. */
export const DEFAULT_FLAGS = Object.freeze({
  // Footer select-all appears from this many edit rows on (1 = always).
  selectAllMin: 2,
  // The other-languages section opens by itself when nothing of the group is
  // in view; with n > 0 also while it holds at most n rows.
  otherLanguagesOpenUpTo: 0,
  // Cleanup section ("Unveränderte Kopien") default state:
  // 'alone' = open when the group has no edits, 'never', 'always'.
  cleanupOpen: 'alone',
  // Label of the screen-reader keyboard help in the footer (a layout with
  // shortcuts names them: 'toolbar.keyboardHint.efficiency').
  keyboardHintKey: 'toolbar.keyboardHint',
  // How the cleanup records are named outside their section's heading: the
  // header summary ("4 unveränderte Kopien"), the name of a copy's language
  // chip ("Kopie „German“ bearbeiten") and the notifications after a
  // cleanup. A layout that calls its cleanup section differently names them
  // the same way everywhere (core: 'toolbar.summary.drafts', …Draft…).
  copiesSummaryKey: 'toolbar.summary.copies',
  copyEditKey: 'toolbar.cleanup.edit',
  cleanupSuccessKey: 'toolbar.cleanup.success.message',
  cleanupSkippedKey: 'toolbar.cleanup.skipped',
});

const NO_PROFILE = Object.freeze({ name: 'clarity', flags: DEFAULT_FLAGS });

export function layoutOf(host) {
  const profile = host?.layoutProfile;
  return profile && typeof profile === 'object' ? profile : NO_PROFILE;
}

export function layoutName(host) {
  return String(layoutOf(host).name || 'clarity');
}

export function layoutFlag(host, flag) {
  const flags = layoutOf(host).flags;
  return flags && Object.hasOwn(flags, flag) ? flags[flag] : DEFAULT_FLAGS[flag];
}

/**
 * Render through a layout hook. Without the hook the default renderer runs;
 * with it, the hook gets `(host, ...args, ctx)` where `ctx.render()` is the
 * default output, so a profile can replace, wrap or extend it.
 */
export function viaLayout(host, hook, args, ctx, render) {
  const fn = layoutOf(host)[hook];
  if (typeof fn !== 'function') return render();
  return fn(host, ...args, { ...ctx, render });
}

/** Call a non-rendering hook; `fallback` when the profile has none. */
export function callLayout(host, hook, args, fallback) {
  const fn = layoutOf(host)[hook];
  return typeof fn === 'function' ? fn(host, ...args) : fallback;
}
