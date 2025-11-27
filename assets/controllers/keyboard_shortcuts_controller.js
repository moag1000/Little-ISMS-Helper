import { Controller } from '@hotwired/stimulus';

/**
 * Keyboard Shortcuts Controller
 * Global keyboard navigation and actions
 *
 * Usage:
 * <div data-controller="keyboard-shortcuts">
 *   <div data-keyboard-shortcuts-target="modal">...</div>
 * </div>
 *
 * @version 1.0.1
 */
export default class extends Controller {
    static targets = ['modal'];

    shortcuts = [
        // Global Navigation (paths without locale - will be prefixed automatically)
        { keys: ['g', 'd'], description: 'Go to Dashboard', action: () => this.navigateWithLocale('/dashboard'), category: 'Navigation' },
        { keys: ['g', 'a'], description: 'Go to Assets', action: () => this.navigateWithLocale('/asset'), category: 'Navigation' },
        { keys: ['g', 'r'], description: 'Go to Risks', action: () => this.navigateWithLocale('/risk'), category: 'Navigation' },
        { keys: ['g', 'i'], description: 'Go to Incidents', action: () => this.navigateWithLocale('/incident'), category: 'Navigation' },
        { keys: ['g', 's'], description: 'Go to SoA', action: () => this.navigateWithLocale('/soa'), category: 'Navigation' },
        { keys: ['g', 't'], description: 'Go to Trainings', action: () => this.navigateWithLocale('/training'), category: 'Navigation' },
        { keys: ['g', 'c'], description: 'Go to Compliance', action: () => this.navigateWithLocale('/compliance'), category: 'Navigation' },

        // Actions
        { keys: ['c'], description: 'Create new (context-aware)', action: () => this.contextCreate(), category: 'Actions' },
        { keys: ['e'], description: 'Edit current item', action: () => this.contextEdit(), category: 'Actions' },
        { keys: ['/'], description: 'Focus search', action: () => this.focusSearch(), category: 'Actions' },

        // Special
        { keys: ['?'], description: 'Show this help', action: () => this.show(), category: 'Help' },
        { keys: ['Escape'], description: 'Close modals/dialogs', action: () => this.closeModals(), category: 'Help' },
    ];

    keySequence = [];
    sequenceTimeout = null;

    connect() {
        this.boundHandleKeydown = this.handleKeydown.bind(this);
        document.addEventListener('keydown', this.boundHandleKeydown);

        // Close modal on ESC
        if (this.hasModalTarget) {
            this.boundHandleBackdropClick = this.handleBackdropClick.bind(this);
            this.modalTarget.addEventListener('click', this.boundHandleBackdropClick);
        }
    }

    disconnect() {
        document.removeEventListener('keydown', this.boundHandleKeydown);
        if (this.hasModalTarget && this.boundHandleBackdropClick) {
            this.modalTarget.removeEventListener('click', this.boundHandleBackdropClick);
        }
    }

    handleKeydown(event) {
        // Ignore if user is typing in input field
        if (this.isInputFocused() && event.key !== 'Escape' && event.key !== '?') {
            return;
        }

        // Ignore if modifier keys are pressed (except for ?)
        if ((event.metaKey || event.ctrlKey || event.altKey) && event.key !== '?') {
            return;
        }

        // Handle Escape (handled by ModalManager, so we can skip this)
        if (event.key === 'Escape') {
            return;  // Let ModalManager handle it
        }

        // Single key shortcuts (when not in input)
        if (!this.isInputFocused()) {
            const singleKeyShortcut = this.shortcuts.find(s =>
                s.keys.length === 1 && s.keys[0] === event.key
            );

            if (singleKeyShortcut) {
                event.preventDefault();
                singleKeyShortcut.action();
                return;
            }
        }

        // Sequence shortcuts (like 'g' then 'd')
        if (!this.isInputFocused() && event.key.length === 1 && !event.metaKey && !event.ctrlKey) {
            this.keySequence.push(event.key);

            // Clear sequence after 1 second
            clearTimeout(this.sequenceTimeout);
            this.sequenceTimeout = setTimeout(() => {
                this.keySequence = [];
            }, 1000);

            // Check if sequence matches any shortcut
            const matchingShortcut = this.shortcuts.find(s =>
                s.keys.length > 1 &&
                this.arraysEqual(s.keys, this.keySequence)
            );

            if (matchingShortcut) {
                event.preventDefault();
                matchingShortcut.action();
                this.keySequence = [];
            }
        }
    }

    handleBackdropClick(event) {
        if (event.target === this.modalTarget) {
            this.close();
        }
    }

    isInputFocused() {
        const activeElement = document.activeElement;
        return activeElement && (
            activeElement.tagName === 'INPUT' ||
            activeElement.tagName === 'TEXTAREA' ||
            activeElement.tagName === 'SELECT' ||
            activeElement.isContentEditable
        );
    }

    arraysEqual(a, b) {
        return a.length === b.length && a.every((val, index) => val === b[index]);
    }

    // Actions
    navigate(url) {
        window.Turbo.visit(url);
    }

    /**
     * Navigate to a URL with the current locale prefix
     * @param {string} path - Path without locale (e.g., '/dashboard')
     */
    navigateWithLocale(path) {
        const locale = this.getCurrentLocale();
        const url = `/${locale}${path}`;
        window.Turbo.visit(url);
    }

    /**
     * Get current locale from URL path
     * @returns {string} Current locale (default: 'de')
     */
    getCurrentLocale() {
        const pathParts = window.location.pathname.split('/');
        // URL format: /{locale}/... - locale is the first non-empty part
        const possibleLocale = pathParts[1];
        // Check if it's a valid locale (de, en, etc.)
        if (possibleLocale && /^[a-z]{2}$/.test(possibleLocale)) {
            return possibleLocale;
        }
        // Default to German
        return 'de';
    }

    contextCreate() {
        // Determine what to create based on current page
        const path = window.location.pathname;

        if (path.includes('/asset')) {
            this.navigateWithLocale('/asset/new');
        } else if (path.includes('/risk')) {
            this.navigateWithLocale('/risk/new');
        } else if (path.includes('/incident')) {
            this.navigateWithLocale('/incident/new');
        } else if (path.includes('/training')) {
            this.navigateWithLocale('/training/new');
        } else if (path.includes('/audit')) {
            this.navigateWithLocale('/audit/new');
        } else {
            // Fallback: show command palette
            document.dispatchEvent(new KeyboardEvent('keydown', {
                key: 'k',
                metaKey: true
            }));
        }
    }

    contextEdit() {
        // Find edit button on page and click it
        const editButton = document.querySelector('a[href*="/edit"], button[data-action*="edit"]');
        if (editButton) {
            editButton.click();
        }
    }

    focusSearch() {
        // Focus search input if exists
        const searchInput = document.querySelector('input[type="search"], input[placeholder*="Suche"], input[placeholder*="Search"]');
        if (searchInput) {
            searchInput.focus();
            searchInput.select();
        }
    }

    closeModals() {
        // Close any open modals
        const openModals = document.querySelectorAll('.modal.show, [data-command-palette-target="modal"].command-palette-open');
        openModals.forEach(modal => {
            modal.classList.remove('show', 'command-palette-open');
        });

        // Also hide keyboard shortcuts help
        if (this.hasModalTarget && this.modalTarget.classList.contains('keyboard-shortcuts-open')) {
            this.close();
        }
    }

    // Show/Hide Help Modal
    show() {
        if (this.hasModalTarget) {
            // Remove d-none class added by modal manager (has !important)
            this.modalTarget.classList.remove('d-none');
            // Override modal manager's display:none with display:flex
            this.modalTarget.style.display = 'flex';
            this.modalTarget.classList.add('keyboard-shortcuts-open');
            this.renderShortcuts();
        }
    }

    close() {
        if (this.hasModalTarget) {
            this.modalTarget.style.display = 'none';
            this.modalTarget.classList.add('d-none');
            this.modalTarget.classList.remove('keyboard-shortcuts-open');
        }
    }

    renderShortcuts() {
        const content = this.modalTarget.querySelector('.keyboard-shortcuts-content');
        if (!content) return;

        // Group shortcuts by category
        const grouped = this.groupByCategory(this.shortcuts);

        let html = '<div class="keyboard-shortcuts-grid">';

        for (const [category, shortcuts] of Object.entries(grouped)) {
            html += `
                <div class="keyboard-shortcuts-category">
                    <h4>${category}</h4>
                    <dl>
            `;

            shortcuts.forEach(shortcut => {
                html += `
                    <div class="keyboard-shortcuts-item">
                        <dt>
                            ${shortcut.keys.map(k => `<kbd>${this.formatKey(k)}</kbd>`).join(' <span class="then">then</span> ')}
                        </dt>
                        <dd>${shortcut.description}</dd>
                    </div>
                `;
            });

            html += `
                    </dl>
                </div>
            `;
        }

        html += '</div>';
        content.innerHTML = html;
    }

    groupByCategory(shortcuts) {
        return shortcuts.reduce((acc, shortcut) => {
            if (!acc[shortcut.category]) {
                acc[shortcut.category] = [];
            }
            acc[shortcut.category].push(shortcut);
            return acc;
        }, {});
    }

    formatKey(key) {
        const specialKeys = {
            'Escape': 'ESC',
            'Enter': '↵',
            'ArrowUp': '↑',
            'ArrowDown': '↓',
            'ArrowLeft': '←',
            'ArrowRight': '→',
        };
        return specialKeys[key] || key.toUpperCase();
    }
}
