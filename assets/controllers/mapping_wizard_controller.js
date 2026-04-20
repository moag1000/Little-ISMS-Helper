import { Controller } from '@hotwired/stimulus';

/**
 * Mapping-Wizard Client-Filter (Sprint 4 / M1).
 *
 * Serverseitig kommt `requirements_by_framework` als JSON in einem
 * `data-value` — der Controller filtert die Requirement-Dropdowns
 * auf das im Schritt-1 gewählte Framework. Step 2 bleibt verborgen
 * bis beide Frameworks gewählt sind.
 *
 * Type-Auswahl (Schritt 3) setzt die Prozent-Auto-Default und wählt
 * eine sinnvolle Confidence vor. Benutzer kann beides überschreiben.
 */
export default class extends Controller {
    static targets = ['sourceFramework', 'targetFramework',
                      'sourceRequirement', 'targetRequirement',
                      'step2', 'percentage'];
    static values = { frameworks: Object };

    connect() {
        this.refreshVisibility();
    }

    onFrameworkChange(event) {
        const side = event.currentTarget.dataset.side;
        const fwId = event.currentTarget.value;
        const target = side === 'source' ? this.sourceRequirementTarget : this.targetRequirementTarget;
        this.populateRequirements(target, fwId);
        this.refreshVisibility();
    }

    onTypeChange(event) {
        const defaultPct = parseInt(event.currentTarget.dataset.defaultPct, 10);
        if (!Number.isNaN(defaultPct) && this.hasPercentageTarget) {
            this.percentageTarget.value = defaultPct;
        }
    }

    populateRequirements(selectEl, fwId) {
        const keepPlaceholder = selectEl.querySelector('option[value=""]');
        selectEl.innerHTML = '';
        if (keepPlaceholder) selectEl.appendChild(keepPlaceholder.cloneNode(true));
        if (!fwId) return;
        const items = this.frameworksValue[fwId] || [];
        for (const r of items) {
            const opt = document.createElement('option');
            opt.value = r.id;
            opt.textContent = `${r.code} — ${r.title}`;
            selectEl.appendChild(opt);
        }
    }

    refreshVisibility() {
        const both = this.sourceFrameworkTarget.value && this.targetFrameworkTarget.value;
        if (this.hasStep2Target) this.step2Target.hidden = !both;
    }
}
