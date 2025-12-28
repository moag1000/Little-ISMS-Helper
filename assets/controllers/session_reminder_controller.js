import { Controller } from '@hotwired/stimulus';

/**
 * Session Reminder Controller
 *
 * Shows a subtle fairy glow around the screen when the user
 * has been inactive for a while, reminding them the session
 * may expire soon.
 *
 * Usage:
 * <body data-controller="session-reminder"
 *       data-session-reminder-warning-value="3300000"
 *       data-session-reminder-timeout-value="3600000">
 */
export default class extends Controller {
    static values = {
        warning: { type: Number, default: 3300000 },  // 55 minutes in ms
        timeout: { type: Number, default: 3600000 }   // 60 minutes in ms
    };

    connect() {
        this.lastActivity = Date.now();
        this.isWarningShown = false;
        this.overlay = null;

        // Track user activity
        this.boundResetTimer = this.resetTimer.bind(this);
        ['mousemove', 'keydown', 'click', 'scroll', 'touchstart'].forEach(event => {
            document.addEventListener(event, this.boundResetTimer, { passive: true });
        });

        // Start checking
        this.checkInterval = setInterval(() => this.checkInactivity(), 30000); // Check every 30s
    }

    disconnect() {
        ['mousemove', 'keydown', 'click', 'scroll', 'touchstart'].forEach(event => {
            document.removeEventListener(event, this.boundResetTimer);
        });

        if (this.checkInterval) {
            clearInterval(this.checkInterval);
        }

        this.hideWarning();
    }

    resetTimer() {
        this.lastActivity = Date.now();

        // If warning is shown, hide it on activity
        if (this.isWarningShown) {
            this.hideWarning();
        }
    }

    checkInactivity() {
        const inactiveTime = Date.now() - this.lastActivity;

        if (inactiveTime >= this.warningValue && !this.isWarningShown) {
            this.showWarning();
        }
    }

    showWarning() {
        if (this.isWarningShown) return;

        // Respect reduced motion preference
        if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
            return;
        }

        this.isWarningShown = true;

        // Create subtle overlay glow
        this.overlay = document.createElement('div');
        this.overlay.className = 'fairy-session-warning';
        this.overlay.setAttribute('aria-hidden', 'true');
        document.body.appendChild(this.overlay);

        // Trigger animation
        requestAnimationFrame(() => {
            this.overlay.classList.add('fairy-session-warning-active');
        });
    }

    hideWarning() {
        if (!this.isWarningShown || !this.overlay) return;

        this.overlay.classList.remove('fairy-session-warning-active');
        this.overlay.classList.add('fairy-session-warning-fade');

        setTimeout(() => {
            if (this.overlay && this.overlay.parentNode) {
                this.overlay.remove();
            }
            this.overlay = null;
            this.isWarningShown = false;
        }, 500);
    }
}
