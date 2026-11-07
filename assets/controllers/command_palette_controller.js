import { Controller } from '@hotwired/stimulus';
import { faT } from '../js/fa-i18n.js';

/**
 * Search + command overlay (UI wave G) — the one overlay behind ⌘K, ⌘P,
 * Ctrl+K, Ctrl+P, "/" (via keyboard_shortcuts) and every `[data-command-palette-open]` trigger.
 *
 * Empty query: the commands from the template (already gated by module and
 * role), admin commands first on /admin pages. From `minChars` characters on:
 * matching commands at once, then records and pages from the search API
 * (debounced). One flat keyboard list over all groups.
 *
 * Usage: templates/_components/_command_palette.html.twig
 */

// Search API result keys → label key (ui.js.search.category.*) and icon.
const RECORD_CATEGORIES = [
    ['assets', 'asset-server'], ['risks', 'status-warning'], ['controls', 'nav-shield-check'],
    ['incidents', 'status-critical'], ['trainings', 'nav-mortarboard'], ['documents', 'nav-document'],
    ['suppliers', 'nav-truck'], ['processing_activities', 'nav-shield-lock'], ['dpias', 'nav-shield-lock'],
    ['data_breaches', 'nav-shield-lock'], ['audit_findings', 'nav-clipboard-check'],
    ['corrective_actions', 'nav-clipboard-check'], ['change_requests', 'nav-change'],
    ['internal_audits', 'nav-clipboard-check'], ['business_processes', 'nav-flow'], ['bc_plans', 'nav-flow'],
    ['bc_exercises', 'nav-flow'], ['crisis_teams', 'nav-flow'], ['management_reviews', 'nav-reports'],
    ['objectives', 'nav-target'], ['vulnerabilities', 'status-critical'], ['patches', 'nav-wrench'],
    ['threat_intelligence', 'status-warning'], ['persons', 'nav-people'], ['interested_parties', 'nav-people'],
    ['consents', 'nav-shield-lock'], ['data_subject_requests', 'nav-shield-lock'],
    ['compliance_frameworks', 'compliance-shield'], ['compliance_requirements', 'compliance-shield'],
];

export default class extends Controller {
    static targets = ['modal', 'input', 'results', 'noResults'];

    static values = {
        commands: { type: Array, default: [] },
        searchUrl: { type: String, default: '' },
        minChars: { type: Number, default: 2 },
        debounceDelay: { type: Number, default: 250 },
    };

    connect() {
        this.items = [];
        this.selectedIndex = 0;
        this.records = [];
        this.query = '';
        this.requestId = 0;
        this.previouslyFocusedElement = null;

        this.onGlobalKeydown = this.handleGlobalKeydown.bind(this);
        this.onTriggerClick = this.handleTriggerClick.bind(this);
        this.onModalKeydown = this.handleModalKeydown.bind(this);
        this.onBackdropClick = this.handleBackdropClick.bind(this);
        document.addEventListener('keydown', this.onGlobalKeydown);
        document.addEventListener('click', this.onTriggerClick);
        this.modalTarget.addEventListener('keydown', this.onModalKeydown);
        this.modalTarget.addEventListener('click', this.onBackdropClick);
    }

    disconnect() {
        document.removeEventListener('keydown', this.onGlobalKeydown);
        document.removeEventListener('click', this.onTriggerClick);
        this.modalTarget.removeEventListener('keydown', this.onModalKeydown);
        this.modalTarget.removeEventListener('click', this.onBackdropClick);
        clearTimeout(this.searchTimeout);
    }

    get isOpen() {
        return this.modalTarget.classList.contains('command-palette-open');
    }

    handleGlobalKeydown(event) {
        const key = (event.key || '').toLowerCase();
        if ((event.metaKey || event.ctrlKey) && (key === 'k' || key === 'p')) {
            event.preventDefault();
            this.open();
        }
        // "/" is wired in keyboard_shortcuts_controller (cheatsheet) and clicks the trigger.
    }

    handleTriggerClick(event) {
        const trigger = event.target.closest?.('[data-command-palette-open]');
        if (!trigger) return;
        event.preventDefault();
        this.open();
    }

    handleModalKeydown(event) {
        switch (event.key) {
            case 'Escape':
                event.preventDefault();
                this.close();
                break;
            case 'ArrowDown':
                event.preventDefault();
                this.move(1);
                break;
            case 'ArrowUp':
                event.preventDefault();
                this.move(-1);
                break;
            case 'Enter':
                event.preventDefault();
                this.activate(this.items[this.selectedIndex]);
                break;
            case 'Tab':
                // Focus stays in the overlay; the list is driven by the arrow keys.
                event.preventDefault();
                this.inputTarget.focus();
                break;
            default:
                break;
        }
    }

    handleBackdropClick(event) {
        if (event.target === this.modalTarget) {
            this.close();
        }
    }

    open() {
        if (this.isOpen) {
            this.inputTarget.focus();
            return;
        }
        this.previouslyFocusedElement = document.activeElement;
        this.modalTarget.style.display = '';
        this.modalTarget.classList.remove('d-none');
        this.modalTarget.classList.add('command-palette-open');
        this.inputTarget.value = '';
        this.query = '';
        this.records = [];
        this.selectedIndex = 0;
        this.render();
        this.inputTarget.focus();
    }

    close() {
        this.modalTarget.classList.remove('command-palette-open');
        clearTimeout(this.searchTimeout);
        this.requestId++;
        if (this.previouslyFocusedElement && typeof this.previouslyFocusedElement.focus === 'function') {
            this.previouslyFocusedElement.focus();
        }
        this.previouslyFocusedElement = null;
    }

    search() {
        this.query = this.inputTarget.value.trim();
        this.records = [];
        this.selectedIndex = 0;
        clearTimeout(this.searchTimeout);
        this.render();
        if (this.query.length < this.minCharsValue || !this.searchUrlValue) return;

        const query = this.query;
        this.searchTimeout = setTimeout(() => this.fetchRecords(query), this.debounceDelayValue);
    }

    async fetchRecords(query) {
        const requestId = ++this.requestId;
        try {
            const response = await fetch(`${this.searchUrlValue}?q=${encodeURIComponent(query)}`, {
                headers: { Accept: 'application/json' },
            });
            if (!response.ok) throw new Error(String(response.status));
            const data = await response.json();
            if (requestId !== this.requestId || query !== this.query) return;
            this.records = this.recordGroups(data);
        } catch (error) {
            if (requestId !== this.requestId) return;
            this.records = [{ label: faT('search.error'), items: [], error: true }];
        }
        this.render();
    }

    /** Pages (navigation results) first, then one group per record type. */
    recordGroups(data) {
        const groups = [];
        const toItem = (item, icon) => ({
            label: item.title,
            description: item.description || '',
            badge: item.badge || '',
            url: item.url,
            icon: item.icon ? String(item.icon).replace(/^fa-icon--/, '') : icon,
        });
        if (Array.isArray(data?.navigation) && data.navigation.length > 0) {
            groups.push({ label: faT('search.category.navigation'), items: data.navigation.map((i) => toItem(i, 'util-arrow-right')) });
        }
        for (const [key, icon] of RECORD_CATEGORIES) {
            if (Array.isArray(data?.[key]) && data[key].length > 0) {
                groups.push({ label: faT(`search.category.${key}`), items: data[key].map((i) => toItem(i, icon)) });
            }
        }
        return groups;
    }

    commandGroups() {
        const query = this.query.toLowerCase();
        let commands = this.commandsValue;
        if (query) {
            commands = commands.filter((cmd) => `${cmd.label} ${cmd.category} ${(cmd.keywords || []).join(' ')}`
                .toLowerCase().includes(query));
        } else if (this.isOnAdminPage()) {
            const isAdmin = (cmd) => (cmd.id || '').startsWith('admin-');
            commands = [...commands.filter(isAdmin), ...commands.filter((c) => !isAdmin(c))];
        }
        const groups = new Map();
        for (const cmd of commands) {
            if (!groups.has(cmd.category)) groups.set(cmd.category, []);
            groups.get(cmd.category).push(cmd);
        }
        return [...groups].map(([label, items]) => ({ label, items }));
    }

    isOnAdminPage() {
        return /^\/(?:[a-z]{2}\/)?admin(?:\/|$)/.test(window.location.pathname);
    }

    render() {
        const groups = [...this.records, ...this.commandGroups()];
        this.items = groups.flatMap((g) => g.items);
        if (this.selectedIndex >= this.items.length) this.selectedIndex = 0;

        const hasError = groups.some((g) => g.error);
        if (this.items.length === 0 && !hasError) {
            this.resultsTarget.innerHTML = '';
            this.noResultsTarget.classList.remove('hidden');
            return;
        }
        this.noResultsTarget.classList.add('hidden');

        let index = 0;
        let html = '';
        for (const group of groups) {
            html += `<div class="command-category" role="group" aria-label="${this.escape(group.label)}">`;
            html += `<div class="command-category-label">${this.escape(group.label)}</div>`;
            if (group.error) {
                html += `<div class="command-item-description">${this.escape(faT('search.error_hint'))}</div>`;
            }
            for (const item of group.items) {
                const selected = index === this.selectedIndex;
                html += `
                    <div class="command-item ${selected ? 'selected' : ''}" role="option"
                         aria-selected="${selected}" data-index="${index}"
                         data-action="click->command-palette#execute">
                        <span class="command-item-icon"><i class="fa-icon fa-icon--${this.escape(item.icon || 'util-arrow-right')}" aria-hidden="true"></i></span>
                        <div class="command-item-content">
                            <span class="command-item-label">${this.highlight(item.label)}</span>
                            ${item.description ? `<span class="command-item-description">${this.highlight(item.description)}</span>` : ''}
                        </div>
                        ${item.badge ? `<span class="fa-status-pill fa-status-pill--neutral">${this.escape(item.badge)}</span>` : ''}
                    </div>`;
                index++;
            }
            html += '</div>';
        }
        this.resultsTarget.innerHTML = html;
    }

    move(step) {
        if (this.items.length === 0) return;
        this.selectedIndex = (this.selectedIndex + step + this.items.length) % this.items.length;
        this.render();
        const selected = this.resultsTarget.querySelector('.command-item.selected');
        if (selected && typeof selected.scrollIntoView === 'function') {
            selected.scrollIntoView({ block: 'nearest' });
        }
    }

    execute(event) {
        this.activate(this.items[Number(event.currentTarget.dataset.index)]);
    }

    activate(item) {
        if (!item?.url) return;
        this.close();
        if (item.download || !window.Turbo || typeof window.Turbo.visit !== 'function') {
            window.location.href = item.url;
            return;
        }
        window.Turbo.visit(item.url);
    }

    highlight(text) {
        const safe = this.escape(text);
        const needle = this.escape(this.query).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        if (!needle) return safe;
        return safe.replace(new RegExp(`(${needle})`, 'gi'), '<mark>$1</mark>');
    }

    escape(value) {
        const div = document.createElement('div');
        div.textContent = value == null ? '' : String(value);
        return div.innerHTML;
    }
}
