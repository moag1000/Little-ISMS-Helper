import { Controller } from '@hotwired/stimulus'

/**
 * Policy-Wizard W4-B — IndustryPresetBundle picker (Step 1).
 *
 * Listens for change events on the bundle <select>, fetches the bundle
 * preview JSON from /policy-wizard/preset-preview/{key}, then:
 *   - tick the standards checkboxes that match the bundle's
 *     preselectedStandards (untouched bundle = no change)
 *   - render a textual document-count + regulatory-references preview
 *     under the select for transparency
 *
 * The controller is purely additive — picking the empty option clears
 * the preview and leaves the user's manual checkbox state intact.
 *
 * Targets:
 *   - select   : the <select> element (bundle picker)
 *   - standard : every standards-checkbox <input>
 *   - preview  : the inline preview <div>
 *
 * Values:
 *   - previewUrl: route template containing the literal `__KEY__`
 *                 placeholder which gets substituted at fetch time.
 */
export default class extends Controller {
    static targets = ['select', 'standard', 'preview']
    static values = { previewUrl: String }

    onChange(event) {
        const key = (event.target.value || '').trim()
        if (key === '') {
            this.clearPreview()
            return
        }
        if (!this.previewUrlValue || this.previewUrlValue === '') {
            return
        }

        const url = this.previewUrlValue.replace('__KEY__', encodeURIComponent(key))
        fetch(url, {
            method: 'GET',
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
        })
            .then((response) => {
                if (!response.ok) {
                    throw new Error('preset_preview_failed')
                }
                return response.json()
            })
            .then((data) => this.applyPreview(data))
            .catch(() => this.clearPreview())
    }

    applyPreview(data) {
        const preselected = Array.isArray(data.preselected_standards)
            ? data.preselected_standards
            : []
        if (this.hasStandardTarget) {
            this.standardTargets.forEach((checkbox) => {
                if (preselected.includes(checkbox.value)) {
                    checkbox.checked = true
                }
            })
        }
        if (this.hasPreviewTarget) {
            const count = typeof data.estimated_document_count === 'number'
                ? data.estimated_document_count
                : (preselected.length * 4)
            const refs = Array.isArray(data.regulatory_references)
                ? data.regulatory_references.join(', ')
                : ''
            const lines = []
            lines.push(`${count} ${this.previewTarget.dataset.documentsLabel || 'documents'}`)
            if (refs !== '') {
                lines.push(refs)
            }
            this.previewTarget.textContent = lines.join(' — ')
            this.previewTarget.classList.remove('d-none')
        }
    }

    clearPreview() {
        if (this.hasPreviewTarget) {
            this.previewTarget.textContent = ''
            this.previewTarget.classList.add('d-none')
        }
    }
}
