import { Controller } from '@hotwired/stimulus';

/**
 * Command Palette Controller (⌘K / Ctrl+K)
 * Modern command interface for power users
 *
 * Usage:
 * <div data-controller="command-palette">
 *   <div data-command-palette-target="modal">...</div>
 * </div>
 */
export default class extends Controller {
    static targets = ['modal', 'input', 'results', 'noResults'];

    static values = {
        // Commands passed from template as JSON array
        commands: { type: Array, default: [] }
    };

    // Default commands (fallback if not provided via values)
    defaultCommands = [
        // Navigation
        { id: 'goto-dashboard', label: 'Dashboard', category: 'Navigation', icon: 'bi-speedometer2', url: '/dashboard', keywords: ['home', 'start'] },
        { id: 'goto-assets', label: 'Assets', category: 'Navigation', icon: 'bi-server', url: '/asset', keywords: ['asset', 'inventory'] },
        { id: 'goto-risks', label: 'Risks', category: 'Navigation', icon: 'bi-exclamation-triangle', url: '/risk', keywords: ['risk'] },
        { id: 'goto-incidents', label: 'Incidents', category: 'Navigation', icon: 'bi-bug', url: '/incident', keywords: ['incident'] },
        { id: 'goto-soa', label: 'SoA', category: 'Navigation', icon: 'bi-shield-check', url: '/soa', keywords: ['soa', 'controls'] },
    ];

    filteredCommands = [];
    selectedIndex = 0;
    previouslyFocusedElement = null;

    connect() {
        // Global keyboard shortcut: Cmd/Ctrl + P
        this.boundHandleGlobalShortcut = this.handleGlobalShortcut.bind(this);
        document.addEventListener('keydown', this.boundHandleGlobalShortcut);

        // ESC to close
        this.boundHandleModalKeydown = this.handleModalKeydown.bind(this);
        this.modalTarget.addEventListener('keydown', this.boundHandleModalKeydown);

        // Click outside to close
        this.boundHandleBackdropClick = this.handleBackdropClick.bind(this);
        this.modalTarget.addEventListener('click', this.boundHandleBackdropClick);

        // Use commands from value or fallback to defaults
        this.commands = this.commandsValue.length > 0 ? this.commandsValue : this.defaultCommands;
        this.filteredCommands = this.commands;
    }

    disconnect() {
        document.removeEventListener('keydown', this.boundHandleGlobalShortcut);
        if (this.hasModalTarget) {
            this.modalTarget.removeEventListener('keydown', this.boundHandleModalKeydown);
            this.modalTarget.removeEventListener('click', this.boundHandleBackdropClick);
        }
    }

    handleGlobalShortcut(event) {
        // Cmd+P (Mac) or Ctrl+P (Windows/Linux) - like VS Code
        if ((event.metaKey || event.ctrlKey) && event.key === 'p') {
            event.preventDefault();
            this.open();
        }
    }

    handleModalKeydown(event) {
        if (event.key === 'Escape') {
            this.close();
        } else if (event.key === 'Tab') {
            // Focus trap - keep focus within command palette
            this.handleTabKey(event);
        } else if (event.key === 'ArrowDown') {
            event.preventDefault();
            this.selectNext();
        } else if (event.key === 'ArrowUp') {
            event.preventDefault();
            this.selectPrevious();
        } else if (event.key === 'Enter') {
            event.preventDefault();
            this.executeSelected();
        }
    }

    handleTabKey(event) {
        const focusableElements = this.getFocusableElements();
        if (focusableElements.length === 0) return;

        const firstElement = focusableElements[0];
        const lastElement = focusableElements[focusableElements.length - 1];

        if (event.shiftKey) {
            // Shift+Tab: if on first element, go to last
            if (document.activeElement === firstElement) {
                event.preventDefault();
                lastElement.focus();
            }
        } else {
            // Tab: if on last element, go to first
            if (document.activeElement === lastElement) {
                event.preventDefault();
                firstElement.focus();
            }
        }
    }

    getFocusableElements() {
        const selector = 'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])';
        return Array.from(this.modalTarget.querySelectorAll(selector)).filter(
            el => !el.disabled && el.offsetParent !== null
        );
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
            activeElement.isContentEditable
        );
    }

    open() {
        // Save currently focused element to restore on close
        this.previouslyFocusedElement = document.activeElement;

        // Remove inline style that modal manager might have set
        this.modalTarget.style.display = '';
        this.modalTarget.classList.remove('d-none');

        this.modalTarget.classList.add('command-palette-open');
        this.inputTarget.value = '';
        this.inputTarget.focus();
        this.filteredCommands = this.commands;
        this.selectedIndex = 0;
        this.render();
    }

    close() {
        this.modalTarget.classList.remove('command-palette-open');

        // Restore focus to previously focused element
        if (this.previouslyFocusedElement && typeof this.previouslyFocusedElement.focus === 'function') {
            this.previouslyFocusedElement.focus();
        }
        this.previouslyFocusedElement = null;
    }

    search(event) {
        const query = event.target.value.toLowerCase().trim();

        if (!query) {
            this.filteredCommands = this.commands;
        } else {
            this.filteredCommands = this.commands.filter(cmd => {
                const searchStr = `${cmd.label} ${cmd.category} ${cmd.keywords.join(' ')}`.toLowerCase();
                return searchStr.includes(query);
            });
        }

        this.selectedIndex = 0;
        this.render();
    }

    render() {
        if (this.filteredCommands.length === 0) {
            this.resultsTarget.innerHTML = '';
            this.noResultsTarget.classList.remove('hidden');
            return;
        }

        this.noResultsTarget.classList.add('hidden');

        // Group by category
        const grouped = this.groupByCategory(this.filteredCommands);

        let html = '';
        for (const [category, commands] of Object.entries(grouped)) {
            html += `<div class="command-category">`;
            html += `<div class="command-category-label">${category}</div>`;

            commands.forEach((cmd, index) => {
                const globalIndex = this.filteredCommands.indexOf(cmd);
                const isSelected = globalIndex === this.selectedIndex;

                html += `
                    <div class="command-item ${isSelected ? 'selected' : ''}"
                         data-command-id="${cmd.id}"
                         data-action="click->command-palette#execute"
                         data-index="${globalIndex}">
                        <div class="command-item-content">
                            <i class="bi ${cmd.icon} command-item-icon"></i>
                            <span class="command-item-label">${cmd.label}</span>
                        </div>
                        ${this.getShortcutBadge(cmd)}
                    </div>
                `;
            });

            html += `</div>`;
        }

        this.resultsTarget.innerHTML = html;
    }

    groupByCategory(commands) {
        return commands.reduce((acc, cmd) => {
            if (!acc[cmd.category]) {
                acc[cmd.category] = [];
            }
            acc[cmd.category].push(cmd);
            return acc;
        }, {});
    }

    getShortcutBadge(cmd) {
        // Could be extended with specific shortcuts per command
        return '';
    }

    selectNext() {
        if (this.selectedIndex < this.filteredCommands.length - 1) {
            this.selectedIndex++;
            this.render();
            this.scrollToSelected();
        }
    }

    selectPrevious() {
        if (this.selectedIndex > 0) {
            this.selectedIndex--;
            this.render();
            this.scrollToSelected();
        }
    }

    scrollToSelected() {
        const selected = this.resultsTarget.querySelector('.command-item.selected');
        if (selected) {
            selected.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
        }
    }

    execute(event) {
        const commandId = event.currentTarget.dataset.commandId;
        const command = this.commands.find(cmd => cmd.id === commandId);

        if (command) {
            this.executeCommand(command);
        }
    }

    executeSelected() {
        if (this.filteredCommands.length > 0) {
            const command = this.filteredCommands[this.selectedIndex];
            this.executeCommand(command);
        }
    }

    executeCommand(command) {
        this.close();

        // Navigate using Turbo with locale prefix
        if (command.url) {
            const url = this.addLocalePrefix(command.url);
            window.Turbo.visit(url);
        }
    }

    /**
     * Add locale prefix to URL if not already present
     * @param {string} url - URL path (e.g., '/dashboard')
     * @returns {string} URL with locale prefix (e.g., '/de/dashboard')
     */
    addLocalePrefix(url) {
        const locale = this.getCurrentLocale();
        // Check if URL already has locale prefix
        if (url.match(/^\/[a-z]{2}\//)) {
            return url;
        }
        return `/${locale}${url}`;
    }

    /**
     * Get current locale from URL path
     * @returns {string} Current locale (default: 'de')
     */
    getCurrentLocale() {
        const pathParts = window.location.pathname.split('/');
        const possibleLocale = pathParts[1];
        // Check if it's a valid locale (de, en, etc.)
        if (possibleLocale && /^[a-z]{2}$/.test(possibleLocale)) {
            return possibleLocale;
        }
        return 'de';
    }
}
