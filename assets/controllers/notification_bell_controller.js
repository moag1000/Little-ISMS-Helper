import { Controller } from '@hotwired/stimulus';

/**
 * notification-bell — Sprint-6b F3 in-app notification bell.
 *
 * Polls /notifications/bell (GET, JSON) every `intervalValue` ms (default 30 000).
 * Updates the badge counter and populates the dropdown list.
 * Clicking outside closes the dropdown (pure CSS-class toggle, no Bootstrap Modal).
 *
 * Values:
 *   pollUrl       string — backend bell endpoint URL
 *   centerUrl     string — link to full notification center
 *   markReadUrl   string — POST URL to mark-read (used by inline form)
 *   interval      number — polling interval in ms (default 30 000)
 */
export default class extends Controller {
    static targets = ['button', 'badge', 'dropdown', 'list', 'markReadForm'];

    static values = {
        pollUrl:     { type: String, default: '' },
        centerUrl:   { type: String, default: '' },
        markReadUrl: { type: String, default: '' },
        interval:    { type: Number, default: 30000 },
    };

    connect() {
        this._timerId = null;
        this._isOpen = false;
        this._boundClose = this._handleOutsideClick.bind(this);

        if (this.pollUrlValue) {
            this._poll();
            this._timerId = setInterval(() => this._poll(), this.intervalValue);
        }
    }

    disconnect() {
        if (this._timerId !== null) {
            clearInterval(this._timerId);
        }
        document.removeEventListener('click', this._boundClose);
    }

    toggleDropdown(event) {
        event.stopPropagation();
        this._isOpen ? this._close() : this._open();
    }

    _open() {
        this._isOpen = true;
        if (this.hasDropdownTarget) {
            this.dropdownTarget.classList.remove('d-none');
        }
        if (this.hasButtonTarget) {
            this.buttonTarget.setAttribute('aria-expanded', 'true');
        }
        document.addEventListener('click', this._boundClose);
    }

    _close() {
        this._isOpen = false;
        if (this.hasDropdownTarget) {
            this.dropdownTarget.classList.add('d-none');
        }
        if (this.hasButtonTarget) {
            this.buttonTarget.setAttribute('aria-expanded', 'false');
        }
        document.removeEventListener('click', this._boundClose);
    }

    _handleOutsideClick(event) {
        if (!this.element.contains(event.target)) {
            this._close();
        }
    }

    async _poll() {
        if (!this.pollUrlValue) {
            return;
        }

        try {
            const response = await fetch(this.pollUrlValue, {
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
            });

            if (!response.ok) {
                return;
            }

            const data = await response.json();
            this._updateBadge(data.count ?? 0);
            this._updateList(data.items ?? []);
        } catch {
            // Network error — fail silently
        }
    }

    _updateBadge(count) {
        if (!this.hasBadgeTarget) {
            return;
        }

        const badge = this.badgeTarget;
        if (count > 0) {
            badge.classList.remove('d-none');
            badge.textContent = count > 99 ? '99+' : String(count);
        } else {
            badge.classList.add('d-none');
            badge.textContent = '0';
        }
    }

    _updateList(items) {
        if (!this.hasListTarget) {
            return;
        }

        const list = this.listTarget;
        list.innerHTML = '';

        if (items.length === 0) {
            const empty = document.createElement('p');
            empty.className = 'text-muted fst-italic small p-2 mb-0';
            empty.textContent = list.dataset.noNewLabel ?? 'No new notifications';
            list.appendChild(empty);
            return;
        }

        items.slice(0, 10).forEach(item => {
            const row = document.createElement('div');
            row.className = `notification-bell-item p-2 border-bottom${item.isUnread ? ' fw-semibold' : ''}`;
            row.innerHTML = `
                <div class="small">${this._escapeHtml(item.rule || item.eventType || '—')}</div>
                <div class="text-muted" style="font-size:0.75rem">${this._escapeHtml(item.attemptedAt ? item.attemptedAt.substring(0, 16).replace('T', ' ') : '')}</div>
            `;
            list.appendChild(row);
        });

        if (items.length > 10 && this.centerUrlValue) {
            const more = document.createElement('a');
            more.href = this.centerUrlValue;
            more.className = 'd-block text-center small p-2';
            more.textContent = `View all ${items.length} notifications`;
            list.appendChild(more);
        }
    }

    _escapeHtml(str) {
        const div = document.createElement('div');
        div.appendChild(document.createTextNode(String(str)));
        return div.innerHTML;
    }
}
