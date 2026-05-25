import { Controller } from '@hotwired/stimulus'

/**
 * S18 B2 — Asset Sub-Type dependent dropdown.
 *
 * Wires the Top-Level Asset-Type <select> to the Sub-Type <select>:
 *
 *   1. On change of the top-type select: filter visible <option> elements
 *      in the sub-type select by matching their `data-top-type` attribute.
 *   2. If no options match (= no sub-types defined for this top-type),
 *      show a small inline hint encouraging the user to apply a preset.
 *   3. The sub-type is OPTIONAL — clearing it is always allowed.
 *
 * The form already ships ALL tenant sub-types (filtered by tenant on the
 * server) — purely client-side filtering keeps the network footprint zero
 * and keeps the form working even if the JSON endpoint is unreachable.
 *
 * The form-mapping uses canonical top-types (Hardware/Software/Datenbank/
 * Personal/Standort/Dienstleistung), while the Asset.assetType <select>
 * uses legacy labels (Hardware/Software/Service/Personnel/Physical/
 * Information/ai_agent). The map below translates legacy -> canonical.
 */
export default class extends Controller {
    static targets = ['topType', 'subType']

    // legacy assetType value → canonical AssetSubType.topType
    static legacyToCanonical = {
        Hardware: 'Hardware',
        Software: 'Software',
        Service: 'Dienstleistung',
        Personnel: 'Personal',
        Physical: 'Standort',
        Information: 'Datenbank',
        ai_agent: 'Software',
    }

    connect () {
        // Initial filter pass — keeps the dropdown coherent on edit-page load.
        if (this.hasTopTypeTarget && this.hasSubTypeTarget) {
            this.applyFilter()
        }
    }

    topTypeChanged () {
        this.applyFilter()
    }

    applyFilter () {
        const legacyValue = this.topTypeTarget.value || ''
        const canonical = this.constructor.legacyToCanonical[legacyValue] || legacyValue
        const select = this.subTypeTarget

        let visibleCount = 0
        let currentStillVisible = false

        for (const opt of Array.from(select.options)) {
            if (opt.value === '') {
                // Placeholder option — always visible
                opt.hidden = false
                continue
            }
            const optTop = opt.dataset.topType || ''
            const matches = optTop === canonical
            opt.hidden = !matches
            opt.disabled = !matches
            if (matches) {
                visibleCount += 1
                if (opt.value === select.value) {
                    currentStillVisible = true
                }
            }
        }

        // If the previously-selected sub-type no longer matches → reset to placeholder
        if (!currentStillVisible && select.value !== '') {
            select.value = ''
        }

        this.toggleHint(visibleCount === 0 && canonical !== '')
    }

    toggleHint (show) {
        let hint = this.element.querySelector('[data-asset-sub-type-hint]')
        if (show && !hint) {
            hint = document.createElement('small')
            hint.dataset.assetSubTypeHint = '1'
            hint.className = 'text-muted d-block mt-1'
            hint.textContent = this.subTypeTarget.dataset.emptyHint
                || 'Keine Sub-Typen für diesen Top-Level. Tipp: Branchen-Preset im Admin-Bereich anwenden.'
            this.subTypeTarget.parentElement?.appendChild(hint)
        } else if (!show && hint) {
            hint.remove()
        }
    }
}
