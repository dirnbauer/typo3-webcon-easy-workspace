import { fileURLToPath } from 'node:url';
import { defineConfig } from 'vitest/config';

const here = (relative) => fileURLToPath(new URL(relative, import.meta.url));

// The toolbar modules import TYPO3 backend modules through the backend
// import map. Tests alias the import-map prefixes to local sources and to
// small mocks of the Core modules that are touched by the toolbar.
export default defineConfig({
  resolve: {
    alias: [
      { find: '@webconsulting/webcon-easy-workspace/', replacement: here('./Resources/Public/JavaScript/') },
      { find: '@typo3/core/ajax/ajax-request.js', replacement: here('./Tests/JavaScript/mocks/ajax-request.js') },
      { find: '@typo3/backend/notification.js', replacement: here('./Tests/JavaScript/mocks/notification.js') },
      { find: '@typo3/backend/modal.js', replacement: here('./Tests/JavaScript/mocks/modal.js') },
      { find: '@typo3/backend/enum/severity.js', replacement: here('./Tests/JavaScript/mocks/severity.js') },
      { find: '@typo3/backend/element/icon-element.js', replacement: here('./Tests/JavaScript/mocks/noop.js') },
    ],
  },
  test: {
    environment: 'jsdom',
    include: ['Tests/JavaScript/**/*.test.js'],
    setupFiles: ['Tests/JavaScript/setup.js'],
    restoreMocks: true,
  },
});
