/**
 * DOM utilities shared by menu modules (breaks menu-context ↔ preview-locate cycle).
 */

export function collectIframes(maxDepth = 4) {
  const seen = new Set();
  const out = [];
  const roots = [document];
  try { if (window.top?.document && window.top.document !== document) roots.push(window.top.document); } catch { /* cross-origin */ }
  const walk = (root, depth) => {
    if (!root || depth > maxDepth) return;
    let frames;
    try { frames = root.querySelectorAll('iframe'); } catch { return; }
    for (const iframe of frames) {
      if (seen.has(iframe)) continue;
      seen.add(iframe);
      out.push(iframe);
      let inner;
      try { inner = iframe.contentDocument; } catch { inner = null; }
      if (inner) walk(inner, depth + 1);
    }
  };
  for (const root of roots) walk(root, 0);
  return out;
}
