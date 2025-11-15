import { Controller } from '@hotwired/stimulus';

/**
 * Sidebar Dropdown Controller
 *
 * Provides expandable/collapsible submenu functionality for sidebar navigation.
 * Remembers user preference using localStorage.
 */
export default class extends Controller {
    static targets = ['submenu', 'icon', 'toggle'];
    static values = {
        key: String,  // localStorage key for remembering state
    };

    connect() {
        // Restore state from localStorage
        if (this.hasKeyValue) {
            const isExpanded = localStorage.getItem(this.keyValue) === 'true';
            if (isExpanded) {
                this.expand(false);
            }
        }

        // Auto-expand if current page is in submenu
        if (this.hasSubmenuTarget) {
            const hasActivePage = this.submenuTarget.querySelector('.active');
            if (hasActivePage) {
                this.expand(false);
            }
        }
    }

    toggle(event) {
        event.preventDefault();
        event.stopPropagation();

        if (this.isExpanded()) {
            this.collapse();
        } else {
            this.expand();
        }
    }

    expand(animate = true) {
        if (this.hasSubmenuTarget) {
            this.submenuTarget.classList.add('expanded');
            if (animate) {
                this.submenuTarget.style.maxHeight = this.submenuTarget.scrollHeight + 'px';
            } else {
                this.submenuTarget.style.maxHeight = 'none';
            }
        }

        if (this.hasIconTarget) {
            this.iconTarget.classList.add('rotated');
        }

        if (this.hasToggleTarget) {
            this.toggleTarget.setAttribute('aria-expanded', 'true');
        }

        // Save state
        if (this.hasKeyValue) {
            localStorage.setItem(this.keyValue, 'true');
        }
    }

    collapse() {
        if (this.hasSubmenuTarget) {
            this.submenuTarget.classList.remove('expanded');
            this.submenuTarget.style.maxHeight = '0';
        }

        if (this.hasIconTarget) {
            this.iconTarget.classList.remove('rotated');
        }

        if (this.hasToggleTarget) {
            this.toggleTarget.setAttribute('aria-expanded', 'false');
        }

        // Save state
        if (this.hasKeyValue) {
            localStorage.setItem(this.keyValue, 'false');
        }
    }

    isExpanded() {
        return this.hasSubmenuTarget && this.submenuTarget.classList.contains('expanded');
    }
}
