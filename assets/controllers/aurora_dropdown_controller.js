import { Controller } from '@hotwired/stimulus';

/**
 * FairyAurora Aurora-Dropdown Controller
 *
 * Steuert Aurora-Dropdown-Panels (Bell, ⌘K, User-Menu, Tenant-Switch).
 * Generisch: erwartet Trigger + [data-aurora-dropdown-target="panel"].
 *
 * Features:
 * - Toggle via Click
 * - ESC schließt
 * - Outside-Click schließt
 * - Focus-Trap beim Öffnen (erstes fokussierbares Element)
 * - aria-expanded auf Trigger sync
 */
export default class extends Controller {
    static targets = ['panel'];

    connect() {
        this.isOpen = false;
        this.boundOutsideClick = this.handleOutsideClick.bind(this);
        this.boundKeydown = this.handleKeydown.bind(this);
    }

    disconnect() {
        document.removeEventListener('click', this.boundOutsideClick);
        document.removeEventListener('keydown', this.boundKeydown);
    }

    toggle(event) {
        if (event) event.stopPropagation();
        this.isOpen ? this.close() : this.open();
    }

    open() {
        if (!this.hasPanelTarget) return;
        this.panelTarget.hidden = false;
        this.isOpen = true;
        const trigger = this.element.querySelector('[aria-haspopup="true"]');
        if (trigger) trigger.setAttribute('aria-expanded', 'true');
        // Focus first menuitem
        const first = this.panelTarget.querySelector('a, button');
        if (first) window.setTimeout(() => first.focus(), 10);
        document.addEventListener('click', this.boundOutsideClick);
        document.addEventListener('keydown', this.boundKeydown);
    }

    close() {
        if (!this.hasPanelTarget) return;
        this.panelTarget.hidden = true;
        this.isOpen = false;
        const trigger = this.element.querySelector('[aria-haspopup="true"]');
        if (trigger) trigger.setAttribute('aria-expanded', 'false');
        document.removeEventListener('click', this.boundOutsideClick);
        document.removeEventListener('keydown', this.boundKeydown);
    }

    handleOutsideClick(event) {
        if (!this.element.contains(event.target)) this.close();
    }

    handleKeydown(event) {
        if (event.key === 'Escape') {
            this.close();
            const trigger = this.element.querySelector('[aria-haspopup="true"]');
            if (trigger) trigger.focus();
        }
    }
}
