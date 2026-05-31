// fa-glossary-tooltip Stimulus controller (Aurora v4 Welle 2, 2026-05-27)
// Handles keyboard + touch support for glossary tooltip popovers.
// Lazy-loads definition from /api/glossary/{acronym} if not in markup.
import { Controller } from '@hotwired/stimulus'

export default class extends Controller {
    static targets = ['indicator', 'popover', 'def']
    static values  = { acronym: String }

    connect () {
        this._loaded   = false
        this._open     = false
    }

    indicatorTargetConnected (el) {
        el.addEventListener('keydown', this._onKeyDown.bind(this))
        el.addEventListener('focus',   () => this.show())
        el.addEventListener('blur',    () => this.hide())
    }

    _onKeyDown (e) {
        if (e.key === 'Escape') { this.hide(); return }
        if (e.key === 'Enter') {
            const link = this.popoverTarget.querySelector('.fa-glossary-tooltip__link')
            if (link) link.click()
        }
    }

    mouseenter () { this.show() }
    mouseleave () { this.hide() }

    touchend (e) {
        if (!this._open) {
            e.preventDefault()
            this.show()
        }
    }

    show () {
        this._open = true
        this.element.classList.add('is-open')
        if (!this._loaded) this._load()
    }

    hide () {
        this._open = false
        this.element.classList.remove('is-open')
    }

    async _load () {
        if (!this.hasDefTarget) return
        if (this.defTarget.dataset.loaded) return
        this._loaded = true
        try {
            const locale = (document.documentElement.lang || 'de').slice(0, 2)
            const res  = await fetch(`/api/glossary/${encodeURIComponent(this.acronymValue)}?locale=${locale}`, {
                headers: { Accept: 'application/json' },
            })
            if (!res.ok) return
            const data = await res.json()
            this.defTarget.textContent     = data.definition ?? ''
            this.defTarget.dataset.loaded  = '1'
        } catch {
            // Silently ignore — abbr title="" is the accessible fallback
        }
    }
}
