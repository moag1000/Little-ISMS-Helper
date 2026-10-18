import { Controller } from '@hotwired/stimulus';

/**
 * json-builder — generic, schema-driven editor for JSON entity columns.
 *
 * Rendered by _fa_json_builder.html.twig (normally via the JsonBuilderType
 * form theme). The controller owns a JS model of the value, renders one
 * Aurora card per entry from `config.fields` and writes the serialised model
 * into the hidden textarea after every change — that textarea is what the
 * form submits. Users never see or type JSON.
 *
 * Shapes (config.shape):
 *   list   — array of objects
 *   map    — object of key → object (or key → scalar with config.scalarValue)
 *   object — one object, no add/remove
 *
 * Loss-free round-trip: each entry keeps the keys it had; the builder only
 * overwrites the keys it declares, and keys in config.hiddenKeys (map/object)
 * are neither shown nor dropped. Empty optional values are omitted.
 *
 * All visible text comes from the template (translated): field labels in
 * config, button/aria texts as *-label values.
 */
export default class extends Controller {
    static targets = ['hidden', 'items', 'empty'];
    static values = {
        config: Object,
        disabled: { type: Boolean, default: false },
        addLabel: String,
        removeLabel: String,
        chooseLabel: String,
        tagPlaceholder: String,
        tagRemoveLabel: String,
        entryLabel: String,
        duplicateKeyLabel: String,
    };

    connect() {
        this.uid = Math.random().toString(36).slice(2, 8);
        this.entries = this.#parse(this.hiddenTarget.value);
        this.#render();
    }

    // ── Actions ─────────────────────────────────────────────────────────────

    add() {
        this.entries.push(this.config.shape === 'map' ? { key: '', value: this.#blankValue() } : { value: {} });
        this.#render();
        this.#sync();
        const cards = this.itemsTarget.querySelectorAll('.fa-json-builder__item');
        cards[cards.length - 1]?.querySelector('input, select, textarea')?.focus();
    }

    remove(event) {
        const index = Number(event.currentTarget.dataset.index);
        this.entries.splice(index, 1);
        this.#render();
        this.#sync();
    }

    // ── Model ───────────────────────────────────────────────────────────────

    get config() {
        return {
            shape: 'list',
            scalarValue: false,
            hiddenKeys: [],
            keySuggestions: [],
            fields: [],
            ...this.configValue,
        };
    }

    #blankValue() {
        return this.config.scalarValue ? '' : {};
    }

    /** JSON text → internal entries: [{key?, value}] (object shape = one entry). */
    #parse(text) {
        let data = null;
        if (text && text.trim() !== '') {
            try {
                data = JSON.parse(text);
            } catch (_) {
                data = null;
            }
        }
        const { shape } = this.config;
        if (shape === 'object') {
            this.hidden = {};
            const obj = data && typeof data === 'object' && !Array.isArray(data) ? data : {};
            return [{ value: obj }];
        }
        if (shape === 'map') {
            const obj = data && typeof data === 'object' && !Array.isArray(data) ? data : {};
            this.hidden = {};
            const entries = [];
            for (const [key, value] of Object.entries(obj)) {
                if (this.config.hiddenKeys.includes(key)) {
                    this.hidden[key] = value;
                    continue;
                }
                entries.push({ key, value });
            }
            return entries;
        }
        const list = Array.isArray(data) ? data : [];
        return list.map((value) => ({ value: value && typeof value === 'object' ? value : {} }));
    }

    /** Internal entries → value written to the hidden textarea ('' = empty). */
    #serialize() {
        const { shape } = this.config;
        if (shape === 'object') {
            const obj = this.#clean(this.entries[0]?.value ?? {});
            return Object.keys(obj).length ? JSON.stringify(obj) : '';
        }
        if (shape === 'map') {
            const out = {};
            for (const entry of this.entries) {
                const key = String(entry.key ?? '').trim();
                const value = this.config.scalarValue ? entry.value : this.#clean(entry.value);
                const empty = this.config.scalarValue
                    ? value === '' || value === null || value === undefined
                    : Object.keys(value).length === 0;
                if (key === '' && empty) {
                    continue; // untouched new row
                }
                out[key] = value;
            }
            Object.assign(out, this.hidden || {});
            return Object.keys(out).length ? JSON.stringify(out) : '';
        }
        const list = this.entries.map((e) => this.#clean(e.value)).filter((v) => Object.keys(v).length > 0);
        return list.length ? JSON.stringify(list) : '';
    }

    /** Drop declared keys whose value is empty; keep undeclared keys as they were. */
    #clean(value) {
        const out = { ...(value || {}) };
        for (const field of this.config.fields) {
            const v = out[field.key];
            if (v === '' || v === null || v === undefined || (Array.isArray(v) && v.length === 0)) {
                delete out[field.key];
            }
        }
        return out;
    }

    #sync() {
        this.#flagDuplicateKeys();
        this.hiddenTarget.value = this.#serialize();
        this.hiddenTarget.dispatchEvent(new Event('input', { bubbles: true }));
        this.hiddenTarget.dispatchEvent(new Event('change', { bubbles: true }));
    }

    /**
     * Two map entries with the same key would silently collapse into one on
     * serialisation — block the submit via constraint validation instead.
     */
    #flagDuplicateKeys() {
        if (this.config.shape !== 'map') {
            return;
        }
        const seen = new Map();
        this.entries.forEach((entry, index) => {
            const key = String(entry.key ?? '').trim();
            const input = this.itemsTarget.querySelector(`[data-key-index="${index}"]`);
            const duplicate = key !== '' && seen.has(key);
            if (key !== '') {
                seen.set(key, index);
            }
            if (input) {
                input.setCustomValidity(duplicate ? (this.duplicateKeyLabelValue || key) : '');
                input.classList.toggle('is-invalid', duplicate);
            }
        });
    }

    // ── Rendering ───────────────────────────────────────────────────────────

    #render() {
        this.itemsTarget.replaceChildren();
        this.entries.forEach((entry, index) => {
            this.itemsTarget.appendChild(this.#renderEntry(entry, index));
        });
        const showEmpty = this.config.shape !== 'object' && this.entries.length === 0;
        this.emptyTarget.classList.toggle('d-none', !showEmpty);
    }

    #renderEntry(entry, index) {
        const { shape } = this.config;
        const card = document.createElement('div');
        card.className = 'fa-json-builder__item';
        if (shape !== 'object') {
            card.setAttribute('role', 'group');
            card.setAttribute('aria-label', (this.entryLabelValue || '#%index%').replace('%index%', String(index + 1)));
        }

        const grid = document.createElement('div');
        grid.className = 'fa-json-builder__grid';
        card.appendChild(grid);

        if (shape === 'map') {
            const keyControl = this.#renderControl(
                { key: '__key', type: 'text', label: this.config.keyLabel, required: true, suggestions: this.config.keySuggestions },
                entry.key,
                (v) => { entry.key = v; },
                index,
            );
            keyControl.querySelector('input').dataset.keyIndex = String(index);
            grid.appendChild(keyControl);
        }

        if (shape === 'map' && this.config.scalarValue) {
            const field = this.config.fields[0];
            grid.appendChild(this.#renderControl(field, entry.value, (v) => { entry.value = v; }, index));
        } else {
            for (const field of this.config.fields) {
                grid.appendChild(this.#renderControl(
                    field,
                    entry.value?.[field.key],
                    (v) => { entry.value = { ...(entry.value || {}), [field.key]: v }; },
                    index,
                ));
            }
        }

        if (shape !== 'object' && !this.disabledValue) {
            const remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'fa-cyber-btn fa-cyber-btn--ghost fa-cyber-btn--sm fa-cyber-btn--danger fa-json-builder__remove';
            remove.dataset.index = String(index);
            remove.dataset.action = 'click->json-builder#remove';
            remove.setAttribute('aria-label', this.removeLabelValue);
            remove.title = this.removeLabelValue;
            remove.innerHTML = '<i class="fa-icon fa-icon--ui-trash" aria-hidden="true"></i>';
            card.appendChild(remove);
        }
        return card;
    }

    #renderControl(field, value, onChange, index) {
        const id = `jb-${this.uid}-${index}-${field.key}`;
        const wrap = document.createElement('div');
        wrap.className = 'fa-json-builder__field' + (field.wide ? ' fa-json-builder__field--wide' : '');

        const label = document.createElement('label');
        label.className = 'fa-cyber-input__label';
        label.htmlFor = id;
        label.textContent = field.label || field.key;
        if (field.required) {
            const req = document.createElement('span');
            req.className = 'fa-cyber-input__req';
            req.setAttribute('aria-hidden', 'true');
            req.textContent = ' *';
            label.appendChild(req);
        }

        if (field.type === 'bool') {
            wrap.classList.add('fa-json-builder__field--bool');
            const check = document.createElement('div');
            check.className = 'form-check form-switch';
            const input = document.createElement('input');
            input.type = 'checkbox';
            input.className = 'form-check-input';
            input.setAttribute('role', 'switch');
            input.id = id;
            input.checked = value === true;
            input.disabled = this.disabledValue;
            input.addEventListener('change', () => { onChange(input.checked); this.#sync(); });
            label.className = 'form-check-label';
            check.append(input, label);
            wrap.appendChild(check);
            this.#appendHelp(wrap, field, id, input);
            return wrap;
        }

        wrap.appendChild(label);
        const frame = document.createElement('div');
        frame.className = 'fa-cyber-input__frame';
        wrap.appendChild(frame);

        if (field.type === 'tags') {
            frame.appendChild(this.#renderTags(field, Array.isArray(value) ? [...value] : [], onChange, id));
            this.#appendHelp(wrap, field, id, null);
            return wrap;
        }

        let input;
        if (field.type === 'textarea') {
            input = document.createElement('textarea');
            input.rows = 2;
            input.value = value ?? '';
        } else if (field.type === 'select') {
            input = document.createElement('select');
            const blank = document.createElement('option');
            blank.value = '';
            blank.textContent = this.chooseLabelValue;
            input.appendChild(blank);
            const choices = [...(field.choices || [])];
            // Keep a legacy value that is not (or no longer) a choice visible.
            if (value !== undefined && value !== null && value !== ''
                && !choices.some((c) => String(c.value) === String(value))) {
                choices.push({ value, label: String(value) });
            }
            for (const choice of choices) {
                const opt = document.createElement('option');
                opt.value = String(choice.value);
                opt.textContent = choice.label;
                opt.selected = String(choice.value) === String(value ?? '');
                input.appendChild(opt);
            }
        } else {
            input = document.createElement('input');
            input.type = { email: 'email', url: 'url', number: 'number', date: 'date' }[field.type] || 'text';
            if (field.type === 'number') {
                input.step = 'any';
            }
            input.value = value ?? '';
            if (field.suggestions?.length) {
                const list = document.createElement('datalist');
                list.id = `${id}-list`;
                for (const s of field.suggestions) {
                    const opt = document.createElement('option');
                    opt.value = s;
                    list.appendChild(opt);
                }
                input.setAttribute('list', list.id);
                frame.appendChild(list);
            }
        }
        input.id = id;
        input.className = field.type === 'select' ? 'form-select fa-cyber-input__field' : 'fa-cyber-input__field';
        input.disabled = this.disabledValue;
        if (field.required) {
            input.setAttribute('aria-required', 'true');
        }
        if (field.placeholder) {
            input.placeholder = field.placeholder;
        }
        const handler = () => {
            onChange(this.#coerce(field, input.value));
            this.#sync();
        };
        input.addEventListener(field.type === 'select' ? 'change' : 'input', handler);
        frame.appendChild(input);
        this.#appendHelp(wrap, field, id, input);
        return wrap;
    }

    #renderTags(field, items, onChange, id) {
        const box = document.createElement('div');
        box.className = 'fa-json-builder__tags';

        const commit = () => { onChange([...items]); this.#sync(); };
        const draw = () => {
            box.querySelectorAll('.fa-json-builder__tag').forEach((el) => el.remove());
            items.forEach((item, i) => {
                const chip = document.createElement('span');
                chip.className = 'fa-json-builder__tag';
                const text = document.createElement('span');
                text.textContent = item;
                chip.appendChild(text);
                if (!this.disabledValue) {
                    const x = document.createElement('button');
                    x.type = 'button';
                    x.className = 'fa-json-builder__tag-remove';
                    x.setAttribute('aria-label', `${this.tagRemoveLabelValue}: ${item}`);
                    x.innerHTML = '<i class="fa-icon fa-icon--ui-close" aria-hidden="true"></i>';
                    x.addEventListener('click', () => { items.splice(i, 1); draw(); commit(); });
                    chip.appendChild(x);
                }
                box.insertBefore(chip, input);
            });
        };

        const input = document.createElement('input');
        input.type = field.itemType === 'email' ? 'email' : 'text';
        input.id = id;
        input.className = 'fa-cyber-input__field fa-json-builder__tag-input';
        input.placeholder = field.placeholder || this.tagPlaceholderValue;
        input.disabled = this.disabledValue;
        const take = () => {
            const parts = input.value.split(/[,;\n]/).map((s) => s.trim()).filter(Boolean);
            if (!parts.length) {
                return;
            }
            if (field.itemType === 'email' && typeof input.checkValidity === 'function') {
                input.value = parts[0];
                if (parts.length === 1 && !input.checkValidity()) {
                    input.reportValidity?.();
                    return;
                }
            }
            for (const p of parts) {
                if (!items.includes(p)) {
                    items.push(p);
                }
            }
            input.value = '';
            draw();
            commit();
        };
        input.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' || e.key === ',') {
                e.preventDefault();
                take();
            } else if (e.key === 'Backspace' && input.value === '' && items.length) {
                items.pop();
                draw();
                commit();
            }
        });
        input.addEventListener('blur', take);
        box.appendChild(input);
        draw();
        return box;
    }

    #appendHelp(wrap, field, id, input) {
        if (!field.help) {
            return;
        }
        const help = document.createElement('small');
        help.className = 'fa-cyber-input__hint';
        help.id = `${id}-help`;
        help.textContent = field.help;
        wrap.appendChild(help);
        input?.setAttribute('aria-describedby', help.id);
    }

    #coerce(field, raw) {
        if (field.type === 'number') {
            if (raw === '' || raw === null) {
                return '';
            }
            const n = Number(raw);
            return Number.isFinite(n) ? n : raw;
        }
        return raw;
    }
}
