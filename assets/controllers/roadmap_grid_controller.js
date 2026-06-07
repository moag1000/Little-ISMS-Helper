import { Controller } from '@hotwired/stimulus';

/*
 * Roadmap capacity grid — staged-save UX.
 *
 * Tracks edited PT cells and reveals the floating fa-savebar with a live count
 * of changed cells. Progressive enhancement only: the form also has a plain
 * always-visible Save button, so the grid works with JS disabled.
 *
 * Targets:
 *   cell    — the per-week <input> fields
 *   savebar — the floating fa-savebar container (starts [hidden])
 *   count   — the "<n> changed" detail element
 */
export default class extends Controller {
    static targets = ['cell', 'savebar', 'count'];

    connect() {
        this.initial = new Map();
        this.cellTargets.forEach((c) => this.initial.set(c.name, c.value));
    }

    dirty() {
        const changed = this.cellTargets.filter((c) => this.initial.get(c.name) !== c.value).length;
        if (this.hasCountTarget) {
            this.countTarget.textContent = String(changed);
        }
        if (this.hasSavebarTarget) {
            this.savebarTarget.hidden = changed === 0;
        }
    }

    discard() {
        this.cellTargets.forEach((c) => {
            if (this.initial.has(c.name)) {
                c.value = this.initial.get(c.name);
            }
        });
        if (this.hasSavebarTarget) {
            this.savebarTarget.hidden = true;
        }
    }
}
