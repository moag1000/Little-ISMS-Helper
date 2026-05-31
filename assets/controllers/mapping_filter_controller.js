import { Controller } from '@hotwired/stimulus';

/**
 * Multi-facet client-side filter for the cross-framework mapping list.
 *
 * Combines a free-text search with up to five select facets (source framework,
 * target framework, type, confidence, review status) using AND logic. Select
 * options are derived from the rendered rows on connect — the dropdowns only
 * ever offer values that actually exist in the current result set.
 *
 * Each row carries data-* attributes (sourceFw/targetFw/type/confidence/status
 * + a pre-lowercased `search` blob). Filtering toggles `display` only, so it is
 * instant and never touches the server.
 */
export default class extends Controller {
    static targets = ['row', 'search', 'sourceFw', 'targetFw', 'type', 'confidence', 'status', 'count', 'noResults'];

    static SELECT_FACETS = ['sourceFw', 'targetFw', 'type', 'confidence', 'status'];

    connect() {
        this.populateSelects();
        this.apply();
    }

    populateSelects() {
        const buckets = {};
        this.constructor.SELECT_FACETS.forEach(f => (buckets[f] = new Set()));

        this.rowTargets.forEach(row => {
            this.constructor.SELECT_FACETS.forEach(f => {
                const v = row.dataset[f];
                if (v) buckets[f].add(v);
            });
        });

        this.constructor.SELECT_FACETS.forEach(f => {
            if (!this.hasTargetFor(f)) return;
            const select = this.targetFor(f);
            [...buckets[f]].sort((a, b) => a.localeCompare(b)).forEach(v => {
                const opt = document.createElement('option');
                opt.value = v;
                opt.textContent = v;
                select.appendChild(opt);
            });
        });
    }

    apply() {
        const q = this.hasSearchTarget ? this.searchTarget.value.toLowerCase().trim() : '';
        const active = {};
        this.constructor.SELECT_FACETS.forEach(f => (active[f] = this.valueFor(f)));

        let visible = 0;
        this.rowTargets.forEach(row => {
            const d = row.dataset;
            const ok =
                (q === '' || (d.search || '').includes(q)) &&
                this.constructor.SELECT_FACETS.every(f => active[f] === '' || d[f] === active[f]);
            row.style.display = ok ? '' : 'none';
            if (ok) visible++;
        });

        if (this.hasCountTarget) this.countTarget.textContent = visible;
        if (this.hasNoResultsTarget) this.noResultsTarget.style.display = visible === 0 ? '' : 'none';
    }

    reset() {
        if (this.hasSearchTarget) this.searchTarget.value = '';
        this.constructor.SELECT_FACETS.forEach(f => {
            if (this.hasTargetFor(f)) this.targetFor(f).value = '';
        });
        this.apply();
    }

    // --- helpers: map facet key → Stimulus target getters ---------------------
    cap(name) {
        return name.charAt(0).toUpperCase() + name.slice(1);
    }

    hasTargetFor(name) {
        return this[`has${this.cap(name)}Target`];
    }

    targetFor(name) {
        return this[`${name}Target`];
    }

    valueFor(name) {
        return this.hasTargetFor(name) ? this.targetFor(name).value : '';
    }
}
