import { Controller } from '@hotwired/stimulus';

/**
 * condition-builder — Aurora chip-row editor for a Symfony collection of
 * condition rows (see templates/_components/_fa_condition_builder.html.twig).
 *
 * The rows hold the real form widgets; this controller only
 *   - clones the collection prototype (from the <template> target) with the
 *     next free index when "Add condition" is clicked,
 *   - removes a row (Symfony's allow_delete drops the missing index),
 *   - keeps the keywords right: first row WHEN, the others AND or OR depending
 *     on the logic select,
 *   - announces add/remove in the polite live region.
 * All visible text comes from data-*-value attributes rendered by the macro.
 */
export default class extends Controller {
    static values = {
        index: { type: Number, default: 0 },
        prototypeName: { type: String, default: '__name__' },
        whenLabel: { type: String, default: 'WHEN' },
        andLabel: { type: String, default: 'AND' },
        orLabel: { type: String, default: 'OR' },
        addedLabel: { type: String, default: '' },
        removedLabel: { type: String, default: '' },
    };

    static targets = ['rows', 'row', 'template', 'logic', 'keyword', 'empty', 'status'];

    add(event) {
        event?.preventDefault?.();
        if (!this.hasTemplateTarget || !this.hasRowsTarget) {
            return;
        }
        const placeholder = new RegExp(this.escapeRegExp(this.prototypeNameValue), 'g');
        const html = this.templateTarget.innerHTML.replace(placeholder, String(this.indexValue));
        this.indexValue += 1;

        const holder = document.createElement('div');
        holder.innerHTML = html.trim();
        const row = holder.firstElementChild;
        if (!row) {
            return;
        }
        this.rowsTarget.appendChild(row);
        this.relabel();
        this.announce(this.addedLabelValue);
        row.querySelector('input, select')?.focus();
    }

    remove(event) {
        event?.preventDefault?.();
        const row = event?.target?.closest('[data-condition-builder-target="row"]');
        if (!row || !this.element.contains(row)) {
            return;
        }
        row.remove();
        this.relabel();
        this.announce(this.removedLabelValue);
    }

    relabel() {
        const joiner = this.hasLogicTarget && this.logicTarget.value === 'or' ? 'or' : 'and';
        const rows = this.hasRowsTarget
            ? Array.from(this.rowsTarget.querySelectorAll('[data-condition-builder-target="keyword"]'))
            : [];
        rows.forEach((keyword, i) => {
            const kind = i === 0 ? 'when' : joiner;
            keyword.textContent = this[`${kind}LabelValue`];
            keyword.classList.remove(
                'fa-condition-builder__keyword--when',
                'fa-condition-builder__keyword--and',
                'fa-condition-builder__keyword--or',
            );
            keyword.classList.add(`fa-condition-builder__keyword--${kind}`);
        });
        if (this.hasEmptyTarget) {
            this.emptyTarget.hidden = rows.length > 0;
        }
    }

    announce(message) {
        if (this.hasStatusTarget && message) {
            this.statusTarget.textContent = message;
        }
    }

    escapeRegExp(string) {
        return string.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    }
}
