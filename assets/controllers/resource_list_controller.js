import { Controller } from '@hotwired/stimulus';

/**
 * resource-list — P-9 JsonBuilder for BCPlan.requiredResources (ISO 22301 §8.1).
 *
 * Three-tab chip builder: personnel / equipment / supplies. Serialises into:
 *   {"personnel": [...], "equipment": [...], "supplies": [...]}
 *
 * Backward compat:
 *   - legacy {"personnel": 10, ...} (numeric) becomes ["10"]
 *   - legacy [] flat list (no categories) lands in "personnel"
 */
export default class extends Controller {
    static targets = ['hidden', 'tab', 'panel', 'chips', 'count', 'emptyState', 'addInput', 'addForm', 'rawPanel', 'rawTextarea'];
    static values = { name: String };

    static CATEGORIES = ['personnel', 'equipment', 'supplies'];

    connect() {
        this.data = this.parseValue(this.hiddenTarget.value || '');
        this.render();
    }

    parseValue(raw) {
        const empty = { personnel: [], equipment: [], supplies: [] };
        const trimmed = (raw || '').trim();
        if (trimmed === '') return empty;
        let decoded;
        try {
            decoded = JSON.parse(trimmed);
        } catch (_) {
            return empty;
        }
        if (Array.isArray(decoded)) {
            // Legacy flat list → personnel bucket.
            return { ...empty, personnel: decoded.map((v) => String(v)) };
        }
        if (decoded && typeof decoded === 'object') {
            const result = { ...empty };
            this.constructor.CATEGORIES.forEach((cat) => {
                const v = decoded[cat];
                if (Array.isArray(v)) {
                    result[cat] = v.map((entry) => String(entry)).filter((s) => s !== '');
                } else if (typeof v === 'number') {
                    result[cat] = [String(v)];
                } else if (typeof v === 'string' && v.trim() !== '') {
                    result[cat] = [v];
                }
            });
            return result;
        }
        return empty;
    }

    switchTab(event) {
        event.preventDefault();
        const target = event.currentTarget;
        const category = target.dataset.category;
        this.tabTargets.forEach((tab) => {
            const active = tab.dataset.category === category;
            tab.setAttribute('aria-selected', active ? 'true' : 'false');
        });
        this.panelTargets.forEach((panel) => {
            panel.classList.toggle('is-active', panel.dataset.category === category);
        });
        // Focus the input of the activated tab for keyboard flow.
        const input = this.addInputTargets.find((i) => i.dataset.category === category);
        if (input) input.focus();
    }

    addEntry(event) {
        event.preventDefault();
        const form = event.currentTarget;
        const category = form.dataset.category;
        const input = this.addInputTargets.find((i) => i.dataset.category === category);
        if (!input) return;
        const value = input.value.trim();
        if (value === '') return;
        if (!this.data[category]) this.data[category] = [];
        this.data[category].push(value);
        input.value = '';
        this.render();
    }

    removeEntry(event) {
        event.preventDefault();
        const category = event.currentTarget.dataset.category;
        const idx = parseInt(event.currentTarget.dataset.index, 10);
        if (!Number.isNaN(idx) && this.data[category] && this.data[category][idx] !== undefined) {
            this.data[category].splice(idx, 1);
            this.render();
        }
    }

    showRawJson(event) {
        event.preventDefault();
        const btn = event.currentTarget;
        const isOpen = !this.rawPanelTarget.classList.contains('d-none');
        if (isOpen) {
            this.rawPanelTarget.classList.add('d-none');
            btn.setAttribute('aria-pressed', 'false');
        } else {
            this.rawTextareaTarget.value = JSON.stringify(this.data, null, 2);
            this.rawPanelTarget.classList.remove('d-none');
            btn.setAttribute('aria-pressed', 'true');
        }
    }

    syncFromRaw(event) {
        const raw = event.currentTarget.value;
        const parsed = this.parseValue(raw);
        this.data = parsed;
        this.render();
    }

    render() {
        this.constructor.CATEGORIES.forEach((category) => {
            const chipsBox = this.chipsTargets.find((c) => c.dataset.category === category);
            const empty = this.emptyStateTargets.find((e) => e.dataset.category === category);
            if (!chipsBox) return;
            chipsBox.innerHTML = '';
            const entries = this.data[category] || [];
            entries.forEach((entry, idx) => {
                chipsBox.insertAdjacentHTML('beforeend', this.chipHtml(entry, category, idx));
            });
            // Wire-up remove buttons.
            chipsBox.querySelectorAll('.fa-resource-list__chip-remove').forEach((btn) => {
                btn.dataset.action = 'click->resource-list#removeEntry';
            });
            if (empty) empty.classList.toggle('d-none', entries.length > 0);

            const countEl = this.countTargets.find((c) => c.dataset.countFor === category);
            if (countEl) countEl.textContent = String(entries.length);
        });
        this.sync();
    }

    chipHtml(entry, category, idx) {
        return `<span class="fa-resource-list__chip" data-category="${category}" data-index="${idx}">
            <span class="fa-resource-list__chip-label">${this.escapeHtml(entry)}</span>
            <button type="button"
                    class="fa-resource-list__chip-remove"
                    data-category="${category}"
                    data-index="${idx}"
                    data-action="click->resource-list#removeEntry"
                    aria-label="Remove">×</button>
        </span>`;
    }

    sync() {
        const allEmpty = this.constructor.CATEGORIES.every((c) => (this.data[c] || []).length === 0);
        this.hiddenTarget.value = allEmpty ? '' : JSON.stringify(this.data);
    }

    escapeHtml(value) {
        return String(value ?? '').replace(/[&<>"']/g, (ch) => ({
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#39;',
        }[ch]));
    }
}
