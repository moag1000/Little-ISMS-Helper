import { defineConfig } from 'vitest/config';
import { fileURLToPath } from 'node:url';

// @hotwired/stimulus ships `main: dist/stimulus.umd.js` (reads bare `Node` off
// the global scope → breaks under jsdom) and `module: dist/stimulus.js` (clean
// ESM) but no `exports` map, so resolve conditions can't pick the ESM build.
// Alias it explicitly.
const stimulusEsm = fileURLToPath(new URL('./node_modules/@hotwired/stimulus/dist/stimulus.js', import.meta.url));

// JS unit-test harness for the Stimulus controllers under assets/controllers/.
// AssetMapper ships ES modules with no bundler, so Vitest runs them directly.
// Tests live under tests/js/ (NOT assets/, so AssetMapper never serves them).
export default defineConfig({
    test: {
        environment: 'jsdom',
        include: ['tests/js/**/*.test.js'],
        setupFiles: ['tests/js/support/setup.js'],
        globals: true,
        restoreMocks: true,
    },
    resolve: {
        alias: {
            '@hotwired/stimulus': stimulusEsm,
        },
    },
});
