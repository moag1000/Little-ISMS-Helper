// Bridge jsdom window globals that the @hotwired/stimulus UMD build reads off
// the global scope but that Vitest's jsdom env does not always hoist.
for (const name of ['Node', 'Element', 'HTMLElement', 'MutationObserver', 'CustomEvent', 'Event']) {
    if (typeof globalThis[name] === 'undefined' && typeof window !== 'undefined' && window[name]) {
        globalThis[name] = window[name];
    }
}

// jsdom has no animation frame scheduler — Stimulus controllers use it for
// post-connect focus. Map it onto a macrotask.
if (typeof globalThis.requestAnimationFrame === 'undefined') {
    globalThis.requestAnimationFrame = (cb) => setTimeout(() => cb(Date.now()), 0);
    globalThis.cancelAnimationFrame = (id) => clearTimeout(id);
}
