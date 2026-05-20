import { Controller } from '@hotwired/stimulus';

/**
 * FormLayout Controller — Scrollspy + Progress + Collapse + Jumplink + Auto-save indicator
 *
 * Responsibilities:
 *   1. Scrollspy: detect most-visible section, highlight outline-item and section
 *   2. Progress counter: watch inputs/selects/textareas, update count badges + global bar
 *   3. Section collapse toggle: head click → toggle collapsed/open, chevron rotation
 *   4. Jumplink + smooth-scroll: outline click → scroll to section, open if collapsed
 *   5. Auto-save indicator: listen to turbo:submit-end / form-layout:saved, update relative time
 *
 * Targets:
 *   outlineItem   (multiple) — outline-rail <a> entries
 *   section       (multiple) — .fa-form-section cards
 *   progressFill  (single)   — top progress-bar inner div
 *   progressText  (single)   — "X von Y · Z%" text
 *   draftState    (single)   — "Entwurf gespeichert vor X s" element
 *
 * Values:
 *   sectionsState  Object  — { sectionId: { fields: int, filled: int, status: string } }
 *   autoSaveUrl    String  — optional URL for explicit autosave POST
 *   draftSavedAt   String  — ISO timestamp of last save
 */
export default class extends Controller {
    static targets = [
        'outlineItem',
        'section',
        'progressFill',
        'progressText',
        'draftState',
    ];

    static values = {
        sectionsState: { type: Object, default: {} },
        autoSaveUrl: { type: String, default: '' },
        draftSavedAt: { type: String, default: '' },
    };

    // ── Lifecycle ──────────────────────────────────────────────────────────

    connect() {
        this._boundOnSaved = this._onSaved.bind(this);
        this._boundInputChange = this._onInputChange.bind(this);
        this._draftTimerHandle = null;

        // Listen for save events (Turbo form submit or custom event)
        this.element.addEventListener('turbo:submit-end', this._boundOnSaved);
        this.element.addEventListener('form-layout:saved', this._boundOnSaved);

        // Wire change listeners on all form inputs within sections
        this._attachInputListeners();

        // Start draft-state relative-time refresher
        if (this.hasDraftStateTarget && this.draftSavedAtValue) {
            this._startDraftTimer();
        }

        // Initial scrollspy sync
        this._syncScrollspy();
    }

    disconnect() {
        this.element.removeEventListener('turbo:submit-end', this._boundOnSaved);
        this.element.removeEventListener('form-layout:saved', this._boundOnSaved);
        this._detachInputListeners();
        this._stopDraftTimer();
    }

    // ── Scrollspy ─────────────────────────────────────────────────────────

    onScroll() {
        this._syncScrollspy();
    }

    _syncScrollspy() {
        const sectionsEl = this.element.querySelector('.fa-form-layout__sections');
        if (!sectionsEl || !this.hasSectionTarget) return;

        const containerTop = sectionsEl.getBoundingClientRect().top;
        const containerHeight = sectionsEl.clientHeight;

        let bestSection = null;
        let bestVisibility = -1;

        for (const section of this.sectionTargets) {
            const rect = section.getBoundingClientRect();
            const visibleTop = Math.max(rect.top, containerTop);
            const visibleBottom = Math.min(rect.bottom, containerTop + containerHeight);
            const visible = Math.max(0, visibleBottom - visibleTop);
            if (visible > bestVisibility) {
                bestVisibility = visible;
                bestSection = section;
            }
        }

        if (!bestSection) return;

        const activeSectionId = bestSection.dataset.sectionId;

        // Update outline items
        for (const item of this.outlineItemTargets) {
            const isActive = item.dataset.sectionId === activeSectionId;
            item.classList.toggle('fa-form-layout__outline-item--active', isActive);
        }
    }

    // ── Section collapse toggle ────────────────────────────────────────────

    toggleSection(event) {
        const head = event.currentTarget;
        const section = head.closest('.fa-form-section');
        if (!section) return;

        const isCollapsed = section.classList.contains('fa-form-section--collapsed');

        if (isCollapsed) {
            this._openSection(section);
        } else {
            this._collapseSection(section);
        }
    }

    _openSection(section) {
        section.classList.remove('fa-form-section--collapsed');
        section.classList.add('fa-form-section--open');
    }

    _collapseSection(section) {
        section.classList.remove('fa-form-section--open');
        section.classList.add('fa-form-section--collapsed');
    }

    // ── Jumplink + smooth-scroll ──────────────────────────────────────────

    jumpToSection(event) {
        event.preventDefault();
        const item = event.currentTarget;
        const sectionId = item.dataset.sectionId;
        if (!sectionId) return;

        const targetSection = this.sectionTargets.find(
            (s) => s.dataset.sectionId === sectionId,
        );
        if (!targetSection) return;

        // Open if collapsed
        if (targetSection.classList.contains('fa-form-section--collapsed')) {
            this._openSection(targetSection);
        }

        // Smooth-scroll to section
        targetSection.scrollIntoView({ behavior: 'smooth', block: 'start' });

        // Focus first focusable input after a short delay (animation)
        setTimeout(() => {
            const firstInput = targetSection.querySelector(
                'input:not([type="hidden"]):not([disabled]), select:not([disabled]), textarea:not([disabled])',
            );
            if (firstInput) firstInput.focus();
        }, 300);

        // Update active outline state immediately
        for (const outlineItem of this.outlineItemTargets) {
            outlineItem.classList.toggle(
                'fa-form-layout__outline-item--active',
                outlineItem.dataset.sectionId === sectionId,
            );
        }
    }

    // ── Next section button ────────────────────────────────────────────────

    nextSection() {
        const openSection = this.sectionTargets.find(
            (s) => s.classList.contains('fa-form-section--open'),
        );
        if (!openSection) {
            // Open first non-done section
            const first = this.sectionTargets.find(
                (s) => !s.classList.contains('fa-form-section--done'),
            );
            if (first) this._openAndScroll(first);
            return;
        }

        const idx = this.sectionTargets.indexOf(openSection);
        const next = this.sectionTargets[idx + 1];
        if (!next) return;

        this._collapseSection(openSection);
        this._openAndScroll(next);
    }

    _openAndScroll(section) {
        this._openSection(section);
        section.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    // ── Progress counter ──────────────────────────────────────────────────

    _attachInputListeners() {
        for (const section of this.sectionTargets) {
            const inputs = section.querySelectorAll('input, select, textarea');
            for (const input of inputs) {
                input.addEventListener('change', this._boundInputChange);
                input.addEventListener('input', this._boundInputChange);
            }
        }
    }

    _detachInputListeners() {
        for (const section of this.sectionTargets) {
            const inputs = section.querySelectorAll('input, select, textarea');
            for (const input of inputs) {
                input.removeEventListener('change', this._boundInputChange);
                input.removeEventListener('input', this._boundInputChange);
            }
        }
    }

    _onInputChange(event) {
        const input = event.target;
        const section = input.closest('.fa-form-section');
        if (!section) return;

        this._recountSection(section);
        this._updateGlobalProgress();
    }

    _recountSection(section) {
        const sectionId = section.dataset.sectionId;
        const allInputs = Array.from(
            section.querySelectorAll(
                'input:not([type="hidden"]):not([type="submit"]):not([type="button"]), select, textarea',
            ),
        );

        const totalFields = allInputs.length;
        const filledFields = allInputs.filter((input) => this._isFieldFilled(input)).length;

        // Update outline count badge
        const countEl = this.element.querySelector(`[data-section-count="${sectionId}"]`);
        if (countEl) {
            countEl.textContent = `${filledFields}/${totalFields}`;
        }

        // Update section meta text
        const metaEl = this.element.querySelector(`[data-section-meta="${sectionId}"]`);
        if (metaEl && !section.classList.contains('fa-form-section--error')) {
            const pending = totalFields - filledFields;
            if (filledFields === totalFields && totalFields > 0) {
                metaEl.textContent = `${totalFields} Felder · alle ausgefüllt`;
            } else {
                metaEl.textContent = `${totalFields} Felder · ${filledFields} ausgefüllt · ${pending} ausstehend`;
            }
        }

        // Transition to done if all filled
        if (filledFields === totalFields && totalFields > 0) {
            this._markSectionDone(section, sectionId);
        }

        // Update internal state
        const state = this.sectionsStateValue;
        state[sectionId] = { fields: totalFields, filled: filledFields, status: section.dataset.sectionStatus || 'current' };
        this.sectionsStateValue = state;
    }

    _isFieldFilled(input) {
        if (input.type === 'checkbox' || input.type === 'radio') {
            return input.checked;
        }
        return input.value !== null && String(input.value).trim() !== '';
    }

    _markSectionDone(section, sectionId) {
        if (section.classList.contains('fa-form-section--done')) return;

        section.classList.remove('fa-form-section--current', 'fa-form-section--error', 'fa-form-section--pending');
        section.classList.add('fa-form-section--done');

        // Update the section num indicator to checkmark
        const numEl = section.querySelector('.fa-form-section__num');
        if (numEl) numEl.innerHTML = '<i class="bi bi-check"></i>';

        // Update matching outline item
        const outlineItem = this.outlineItemTargets.find(
            (item) => item.dataset.sectionId === sectionId,
        );
        if (outlineItem) {
            outlineItem.classList.remove(
                'fa-form-layout__outline-item--current',
                'fa-form-layout__outline-item--error',
            );
            outlineItem.classList.add('fa-form-layout__outline-item--done');
            const stateEl = outlineItem.querySelector('.fa-form-layout__outline-state');
            if (stateEl) stateEl.innerHTML = '<i class="bi bi-check"></i>';
        }
    }

    _updateGlobalProgress() {
        if (!this.hasProgressFillTarget && !this.hasProgressTextTarget) return;

        const total = this.sectionTargets.length;
        if (total === 0) return;

        const done = this.sectionTargets.filter((s) =>
            s.classList.contains('fa-form-section--done'),
        ).length;

        const percent = Math.round((done / total) * 100);

        if (this.hasProgressFillTarget) {
            this.progressFillTarget.style.width = `${percent}%`;
        }

        if (this.hasProgressTextTarget) {
            this.progressTextTarget.innerHTML =
                `<strong>${done}</strong> von <strong>${total}</strong> Abschnitten · ${percent}%`;
        }
    }

    // ── Auto-save indicator ────────────────────────────────────────────────

    _onSaved(event) {
        const now = new Date().toISOString();
        this.draftSavedAtValue = now;

        if (this.hasDraftStateTarget) {
            this._updateDraftStateText();
            this._startDraftTimer();
        }
    }

    _startDraftTimer() {
        this._stopDraftTimer();
        this._draftTimerHandle = setInterval(() => {
            this._updateDraftStateText();
        }, 60000); // refresh every 60 s
        this._updateDraftStateText();
    }

    _stopDraftTimer() {
        if (this._draftTimerHandle !== null) {
            clearInterval(this._draftTimerHandle);
            this._draftTimerHandle = null;
        }
    }

    _updateDraftStateText() {
        if (!this.hasDraftStateTarget || !this.draftSavedAtValue) return;

        const savedAt = new Date(this.draftSavedAtValue);
        const diffMs = Date.now() - savedAt.getTime();
        const diffSec = Math.round(diffMs / 1000);

        let label;
        if (diffSec < 60) {
            label = `Entwurf gespeichert vor ${diffSec} s`;
        } else if (diffSec < 3600) {
            label = `Entwurf gespeichert vor ${Math.round(diffSec / 60)} min`;
        } else {
            label = `Entwurf gespeichert vor ${Math.round(diffSec / 3600)} h`;
        }

        this.draftStateTarget.innerHTML = `<i class="bi bi-check-circle-fill"></i> ${label}`;
    }
}
