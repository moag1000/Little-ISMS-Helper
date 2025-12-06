import { Controller } from '@hotwired/stimulus';

/**
 * Notifications Controller - Notification Center
 *
 * Features:
 * - Display in-app notifications
 * - Notification history
 * - Mark as read
 * - Clear all
 * - Persist in localStorage
 */
export default class extends Controller {
    static targets = [
        'panel',
        'list',
        'badge',
        'empty'
    ];

    static values = {
        storageKey: { type: String, default: 'notifications' },
        maxNotifications: { type: Number, default: 50 }
    };

    connect() {
        // Load notifications from localStorage
        this.loadNotifications();

        // Render notifications
        this.render();

        // Listen for new notifications
        this.boundHandleNewNotification = this.handleNewNotification.bind(this);
        window.addEventListener('new-notification', this.boundHandleNewNotification);

        // ESC to close panel
        this.boundHandleKeydown = this.handleKeydown.bind(this);
        document.addEventListener('keydown', this.boundHandleKeydown);
    }

    disconnect() {
        window.removeEventListener('new-notification', this.boundHandleNewNotification);
        document.removeEventListener('keydown', this.boundHandleKeydown);
    }

    handleKeydown(event) {
        if (event.key === 'Escape' && this.hasPanelTarget && this.panelTarget.classList.contains('show')) {
            this.close();
        }
    }

    loadNotifications() {
        const saved = localStorage.getItem(this.storageKeyValue);

        if (saved) {
            try {
                this.notifications = JSON.parse(saved);
            } catch (e) {
                this.notifications = [];
            }
        } else {
            this.notifications = [];
        }

        // Sort by timestamp (newest first)
        this.notifications.sort((a, b) => b.timestamp - a.timestamp);
    }

    saveNotifications() {
        // Keep only max notifications
        if (this.notifications.length > this.maxNotificationsValue) {
            this.notifications = this.notifications.slice(0, this.maxNotificationsValue);
        }

        localStorage.setItem(this.storageKeyValue, JSON.stringify(this.notifications));
    }

    toggle() {
        if (this.hasPanelTarget) {
            const isOpen = this.panelTarget.classList.contains('show');

            if (isOpen) {
                this.close();
            } else {
                this.open();
            }
        }
    }

    open() {
        if (this.hasPanelTarget) {
            // Remove inline style that modal manager might have set
            this.panelTarget.style.display = '';

            this.panelTarget.classList.add('show');
            this.panelTarget.classList.remove('d-none');

            // Mark notifications as seen (not necessarily read)
            this.markAllAsSeen();
        }
    }

    close() {
        if (this.hasPanelTarget) {
            this.panelTarget.classList.remove('show');
            setTimeout(() => {
                this.panelTarget.classList.add('d-none');
            }, 300);
        }
    }

    handleBackdropClick(event) {
        if (event.target.classList.contains('notification-panel-backdrop')) {
            this.close();
        }
    }

    handleNewNotification(event) {
        const { type, title, message, link } = event.detail;

        this.addNotification({
            id: Date.now(),
            type: type || 'info', // success, info, warning, danger
            title: title,
            message: message,
            link: link || null,
            timestamp: Date.now(),
            read: false,
            seen: false
        });
    }

    addNotification(notification) {
        // Add to beginning of array
        this.notifications.unshift(notification);

        // Save and render
        this.saveNotifications();
        this.render();

        // Update badge
        this.updateBadge();
    }

    markAsRead(event) {
        const notificationId = parseInt(event.params.id);
        const notification = this.notifications.find(n => n.id === notificationId);

        if (notification) {
            notification.read = true;
            this.saveNotifications();
            this.render();
            this.updateBadge();
        }
    }

    markAllAsRead() {
        this.notifications.forEach(n => n.read = true);
        this.saveNotifications();
        this.render();
        this.updateBadge();
    }

    markAllAsSeen() {
        let changed = false;

        this.notifications.forEach(n => {
            if (!n.seen) {
                n.seen = true;
                changed = true;
            }
        });

        if (changed) {
            this.saveNotifications();
            this.updateBadge();
        }
    }

    deleteNotification(event) {
        const notificationId = parseInt(event.params.id);
        this.notifications = this.notifications.filter(n => n.id !== notificationId);
        this.saveNotifications();
        this.render();
        this.updateBadge();
    }

    clearAll() {
        if (confirm(window.translations?.notifications?.confirm_clear_all || 'Do you really want to delete all notifications?')) {
            this.notifications = [];
            this.saveNotifications();
            this.render();
            this.updateBadge();
        }
    }

    render() {
        if (!this.hasListTarget) return;

        if (this.notifications.length === 0) {
            this.renderEmpty();
            return;
        }

        const html = this.notifications.map(n => this.renderNotification(n)).join('');
        this.listTarget.innerHTML = html;
    }

    renderNotification(notification) {
        const timeAgo = this.getTimeAgo(notification.timestamp);
        const iconClass = this.getIconClass(notification.type);
        const colorClass = this.getColorClass(notification.type);

        return `
            <div class="notification-item ${notification.read ? 'read' : 'unread'}"
                 data-action="click->notifications#markAsRead"
                 data-notifications-id-param="${notification.id}">
                <div class="notification-icon ${colorClass}">
                    <i class="${iconClass}"></i>
                </div>
                <div class="notification-content">
                    <div class="notification-header">
                        <strong class="notification-title">${notification.title}</strong>
                        <span class="notification-time">${timeAgo}</span>
                    </div>
                    <div class="notification-message">${notification.message}</div>
                    ${notification.link ? `<a href="${notification.link}" class="notification-link">Details anzeigen</a>` : ''}
                </div>
                <button class="notification-delete"
                        data-action="click->notifications#deleteNotification:stop"
                        data-notifications-id-param="${notification.id}"
                        title="Löschen">
                    <i class="bi-x"></i>
                </button>
            </div>
        `;
    }

    renderEmpty() {
        this.listTarget.innerHTML = `
            <div class="notification-empty">
                <i class="bi-bell-slash" style="font-size: 3rem; color: #ccc;"></i>
                <p class="mt-3 mb-0">Keine Benachrichtigungen</p>
                <p class="text-muted small">Sie haben alle Benachrichtigungen gelesen</p>
            </div>
        `;
    }

    updateBadge() {
        if (!this.hasBadgeTarget) return;

        const unreadCount = this.notifications.filter(n => !n.read).length;

        if (unreadCount > 0) {
            this.badgeTarget.textContent = unreadCount > 99 ? '99+' : unreadCount;
            this.badgeTarget.classList.remove('d-none');
        } else {
            this.badgeTarget.classList.add('d-none');
        }
    }

    getIconClass(type) {
        const icons = {
            success: 'bi-check-circle-fill',
            info: 'bi-info-circle-fill',
            warning: 'bi-exclamation-triangle-fill',
            danger: 'bi-x-circle-fill'
        };
        return icons[type] || icons.info;
    }

    getColorClass(type) {
        const colors = {
            success: 'text-success',
            info: 'text-info',
            warning: 'text-warning',
            danger: 'text-danger'
        };
        return colors[type] || colors.info;
    }

    getTimeAgo(timestamp) {
        const seconds = Math.floor((Date.now() - timestamp) / 1000);

        if (seconds < 60) return 'Gerade eben';
        if (seconds < 3600) return `vor ${Math.floor(seconds / 60)} Min.`;
        if (seconds < 86400) return `vor ${Math.floor(seconds / 3600)} Std.`;
        if (seconds < 604800) return `vor ${Math.floor(seconds / 86400)} Tag(en)`;

        return new Date(timestamp).toLocaleDateString('de-DE');
    }

    // Utility method to trigger notifications from other controllers
    static notify({ type, title, message, link }) {
        window.dispatchEvent(new CustomEvent('new-notification', {
            detail: { type, title, message, link }
        }));
    }
}
