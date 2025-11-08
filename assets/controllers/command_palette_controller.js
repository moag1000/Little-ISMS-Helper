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

    commands = [
        // Navigation
        { id: 'goto-dashboard', label: 'Dashboard öffnen', category: 'Navigation', icon: 'bi-speedometer2', url: '/dashboard', keywords: ['home', 'start', 'übersicht'] },
        { id: 'goto-assets', label: 'Assets verwalten', category: 'Navigation', icon: 'bi-server', url: '/assets', keywords: ['asset', 'inventory', 'bestände'] },
        { id: 'goto-risks', label: 'Risiken anzeigen', category: 'Navigation', icon: 'bi-exclamation-triangle', url: '/risks', keywords: ['risk', 'risiko', 'gefahr'] },
        { id: 'goto-incidents', label: 'Vorfälle anzeigen', category: 'Navigation', icon: 'bi-bug', url: '/incidents', keywords: ['incident', 'vorfall', 'security'] },
        { id: 'goto-soa', label: 'Statement of Applicability', category: 'Navigation', icon: 'bi-shield-check', url: '/soa', keywords: ['soa', 'controls', 'iso', '27001'] },
        { id: 'goto-trainings', label: 'Schulungen verwalten', category: 'Navigation', icon: 'bi-mortarboard', url: '/training', keywords: ['training', 'schulung', 'awareness'] },
        { id: 'goto-audits', label: 'Audits anzeigen', category: 'Navigation', icon: 'bi-clipboard-check', url: '/audit', keywords: ['audit', 'prüfung', 'review'] },
        { id: 'goto-compliance', label: 'Compliance Frameworks', category: 'Navigation', icon: 'bi-patch-check', url: '/compliance', keywords: ['compliance', 'tisax', 'dora'] },
        { id: 'goto-bcm', label: 'Business Continuity', category: 'Navigation', icon: 'bi-graph-up', url: '/business-process', keywords: ['bcm', 'bia', 'continuity', 'rto', 'rpo'] },

        // Actions
        { id: 'create-asset', label: 'Neues Asset erstellen', category: 'Erstellen', icon: 'bi-plus-circle', url: '/asset/new', keywords: ['new', 'create', 'neu', 'anlegen'] },
        { id: 'create-risk', label: 'Neues Risiko erfassen', category: 'Erstellen', icon: 'bi-plus-circle', url: '/risk/new', keywords: ['new', 'create', 'neu', 'risiko'] },
        { id: 'create-incident', label: 'Vorfall melden', category: 'Erstellen', icon: 'bi-plus-circle', url: '/incident/new', keywords: ['new', 'create', 'incident', 'melden'] },
        { id: 'create-training', label: 'Schulung planen', category: 'Erstellen', icon: 'bi-plus-circle', url: '/training/new', keywords: ['new', 'training', 'schulung'] },
        { id: 'create-audit', label: 'Audit anlegen', category: 'Erstellen', icon: 'bi-plus-circle', url: '/audit/new', keywords: ['new', 'audit', 'prüfung'] },

        // Reports
        { id: 'export-dashboard', label: 'Dashboard exportieren', category: 'Export', icon: 'bi-download', url: '/reports/dashboard/export', keywords: ['export', 'pdf', 'report'] },
        { id: 'export-soa', label: 'SoA exportieren', category: 'Export', icon: 'bi-download', url: '/soa/export', keywords: ['export', 'soa', 'pdf'] },
        { id: 'export-risks', label: 'Risikoregister exportieren', category: 'Export', icon: 'bi-download', url: '/reports/risks/export', keywords: ['export', 'risks', 'pdf'] },

        // Settings
        { id: 'goto-users', label: 'Benutzerverwaltung', category: 'Administration', icon: 'bi-people', url: '/user-management', keywords: ['user', 'benutzer', 'admin', 'rolle'] },
        { id: 'goto-audit-log', label: 'Audit Log anzeigen', category: 'Administration', icon: 'bi-clock-history', url: '/audit-log', keywords: ['log', 'history', 'änderungen'] },
    ];

    filteredCommands = [];
    selectedIndex = 0;

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
        this.modalTarget.classList.add('command-palette-open');
        this.inputTarget.value = '';
        this.inputTarget.focus();
        this.filteredCommands = this.commands;
        this.selectedIndex = 0;
        this.render();
    }

    close() {
        this.modalTarget.classList.remove('command-palette-open');
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

        // Navigate using Turbo
        if (command.url) {
            window.Turbo.visit(command.url);
        }
    }
}
