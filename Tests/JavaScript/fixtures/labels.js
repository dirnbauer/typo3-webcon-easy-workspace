import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

/**
 * The extension's real labels, read from the XLIFF files, so the template
 * tests assert what editors read (German) and a broken label shows up here.
 */
// Vitest runs from the package root (vitest.config.js).
const root = `${resolve(process.cwd())}/`;

function decode(text) {
  return text.replaceAll('&lt;', '<').replaceAll('&gt;', '>').replaceAll('&quot;', '"').replaceAll('&apos;', "'").replaceAll('&amp;', '&');
}

/** @returns {Record<string, string>} label id => target text of the language ('en' or 'de') */
export function xliffLabels(language = 'de') {
  const file = language === 'en' ? 'locallang.xlf' : `${language}.locallang.xlf`;
  const xml = readFileSync(`${root}Resources/Private/Language/${file}`, 'utf8');
  const labels = {};
  for (const match of xml.matchAll(/<unit id="([^"]+)">\s*<segment>\s*<source>[\s\S]*?<\/source>\s*<target[^>]*>([\s\S]*?)<\/target>/g)) {
    labels[match[1]] = decode(match[2]);
  }
  return labels;
}

/** The label ids the toolbar receives (LocalizationService::JS_LABEL_KEYS). */
export function javaScriptLabelKeys() {
  const php = readFileSync(`${root}Classes/Service/LocalizationService.php`, 'utf8');
  const block = php.slice(php.indexOf('JS_LABEL_KEYS'), php.indexOf('];', php.indexOf('JS_LABEL_KEYS')));
  return Array.from(block.matchAll(/^\s+'([^']+)',$/gm), (match) => match[1]);
}

/** The label ids the backend module receives (EasyWorkspaceModuleController::buildJsLabelMap()). */
export function moduleLabelKeys() {
  const php = readFileSync(`${root}Classes/Controller/Backend/EasyWorkspaceModuleController.php`, 'utf8');
  const start = php.indexOf('$keys = [', php.indexOf('function buildJsLabelMap'));
  const block = php.slice(start, php.indexOf('];', start));
  return Array.from(block.matchAll(/^\s+'([^']+)',$/gm), (match) => match[1]);
}
