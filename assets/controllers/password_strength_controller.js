import { Controller } from '@hotwired/stimulus';
import { faT } from '../js/fa-i18n.js';

/**
 * Password Strength Indicator Controller
 *
 * Provides real-time password strength feedback with visual indicator and
 * helpful suggestions for creating stronger passwords.
 *
 * Strength Criteria:
 * - Length (8+ chars)
 * - Uppercase letters
 * - Lowercase letters
 * - Numbers
 * - Special characters
 *
 * Strength Levels:
 * - Very Weak (0-1 criteria): Red, 20%
 * - Weak (2 criteria): Orange, 40%
 * - Fair (3 criteria): Yellow, 60%
 * - Strong (4 criteria): Light Green, 80%
 * - Very Strong (5 criteria): Dark Green, 100%
 *
 * Usage (already integrated in _macros/form_fields.html.twig):
 * <input type="password"
 *        data-controller="password-strength"
 *        data-action="input->password-strength#check"
 *        data-password-strength-target="input">
 * <div data-password-strength-target="meter">...</div>
 */
export default class extends Controller {
    static targets = ['input', 'meter', 'bar', 'feedback'];

    connect() {
        // Initialize meter visibility
        if (this.hasMeterTarget) {
            this.meterTarget.style.display = 'none';
        }
    }

    /**
     * Check password strength on input
     */
    check(event) {
        const password = this.inputTarget.value;

        // Hide meter if password is empty
        if (!password) {
            if (this.hasMeterTarget) {
                this.meterTarget.style.display = 'none';
            }
            return;
        }

        // Show meter
        if (this.hasMeterTarget) {
            this.meterTarget.style.display = 'block';
        }

        // Calculate strength
        const strength = this.calculateStrength(password);

        // Update visual indicator
        this.updateMeter(strength);
    }

    /**
     * Calculate password strength (0-5)
     */
    calculateStrength(password) {
        let score = 0;
        const checks = {
            length: password.length >= 8,
            uppercase: /[A-Z]/.test(password),
            lowercase: /[a-z]/.test(password),
            numbers: /[0-9]/.test(password),
            special: /[^A-Za-z0-9]/.test(password)
        };

        // Count met criteria
        for (const check in checks) {
            if (checks[check]) score++;
        }

        // Bonus for very long passwords
        if (password.length >= 16) {
            score = Math.min(5, score + 1);
        }

        return {
            score: score,
            checks: checks,
            level: this.getStrengthLevel(score),
            suggestions: this.getSuggestions(checks, password)
        };
    }

    /**
     * Get strength level descriptor
     */
    getStrengthLevel(score) {
        const levels = {
            0: { label: faT('password_strength.very_weak'), color: 'danger', width: 20 },
            1: { label: faT('password_strength.very_weak'), color: 'danger', width: 20 },
            2: { label: faT('password_strength.weak'), color: 'warning', width: 40 },
            3: { label: faT('password_strength.medium'), color: 'info', width: 60 },
            4: { label: faT('password_strength.strong'), color: 'success', width: 80 },
            5: { label: faT('password_strength.very_strong'), color: 'success', width: 100 }
        };

        return levels[score] || levels[0];
    }

    /**
     * Generate suggestions for improving password
     */
    getSuggestions(checks, password) {
        const suggestions = [];

        if (!checks.length) {
            suggestions.push(faT('password_strength.min_length'));
        }
        if (!checks.uppercase) {
            suggestions.push(faT('password_strength.uppercase'));
        }
        if (!checks.lowercase) {
            suggestions.push(faT('password_strength.lowercase'));
        }
        if (!checks.numbers) {
            suggestions.push(faT('password_strength.numbers'));
        }
        if (!checks.special) {
            suggestions.push(faT('password_strength.special'));
        }

        // Warn about common patterns
        if (/^[0-9]+$/.test(password)) {
            suggestions.push(faT('password_strength.avoid_digits_only'));
        }
        if (/(.)\1{2,}/.test(password)) {
            suggestions.push(faT('password_strength.avoid_repeats'));
        }
        if (/^(password|12345|qwerty)/i.test(password)) {
            suggestions.push(faT('password_strength.avoid_common'));
        }

        return suggestions;
    }

    /**
     * Update meter visualization
     */
    updateMeter(strength) {
        if (!this.hasBarTarget || !this.hasFeedbackTarget) return;

        const { level, suggestions } = strength;

        // Update progress bar
        this.barTarget.style.width = `${level.width}%`;
        this.barTarget.className = `progress-bar bg-${level.color}`;
        this.barTarget.setAttribute('aria-valuenow', level.width);
        this.barTarget.setAttribute('aria-valuemin', '0');
        this.barTarget.setAttribute('aria-valuemax', '100');

        // Update feedback text (built as DOM — labels are plain text)
        const strong = document.createElement('strong');
        strong.className = `text-${level.color}`;
        strong.textContent = level.label;
        const detail = document.createElement('small');
        if (suggestions.length > 0) {
            detail.className = 'text-muted';
            detail.textContent = faT('password_strength.missing', { '%items%': suggestions.join(', ') });
        } else {
            detail.className = 'text-success';
            detail.textContent = faT('password_strength.all_met');
        }
        this.feedbackTarget.replaceChildren(strong, document.createElement('br'), detail);

        // Announce to screen readers (debounced)
        this.announceStrength(level.label);
    }

    /**
     * Announce strength to screen readers (debounced to avoid spam)
     */
    announceStrength(label) {
        if (this.announceTimeout) {
            clearTimeout(this.announceTimeout);
        }

        this.announceTimeout = setTimeout(() => {
            let liveRegion = document.getElementById('password-strength-announcement');

            if (!liveRegion) {
                liveRegion = document.createElement('div');
                liveRegion.id = 'password-strength-announcement';
                liveRegion.className = 'sr-only';
                liveRegion.setAttribute('role', 'status');
                liveRegion.setAttribute('aria-live', 'polite');
                document.body.appendChild(liveRegion);
            }

            liveRegion.textContent = faT('password_strength.announce', { '%label%': label });

            // Clear after announcement
            setTimeout(() => {
                liveRegion.textContent = '';
            }, 3000);
        }, 1000); // 1 second debounce
    }
}
