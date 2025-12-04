import { Controller } from '@hotwired/stimulus';

/**
 * Backup Codes Controller
 *
 * Handles MFA backup codes operations: copy, print, download.
 *
 * Usage:
 * <div data-controller="backup-codes" data-backup-codes-codes-value='["code1", "code2"]'>
 *     <button data-action="backup-codes#copy">Copy</button>
 *     <button data-action="backup-codes#print">Print</button>
 *     <button data-action="backup-codes#download">Download</button>
 * </div>
 */
export default class extends Controller {
    static targets = ['codesContainer'];

    static values = {
        codes: Array,
        filename: { type: String, default: 'backup-codes.txt' }
    };

    /**
     * Copy all backup codes to clipboard
     */
    async copy(event) {
        event.preventDefault();

        const codes = this.getCodesText();

        if (navigator.clipboard) {
            try {
                await navigator.clipboard.writeText(codes);
                this.showFeedback(event.currentTarget, 'success', 'bi-check');
            } catch (err) {
                this.fallbackCopy(codes);
            }
        } else {
            this.fallbackCopy(codes);
        }
    }

    /**
     * Print backup codes
     */
    print(event) {
        event.preventDefault();

        const printWindow = window.open('', '_blank');
        if (!printWindow) {
            alert('Please allow popups to print backup codes');
            return;
        }

        const codes = this.getCodesArray();
        const html = `
            <!DOCTYPE html>
            <html>
            <head>
                <title>MFA Backup Codes</title>
                <style>
                    body { font-family: monospace; padding: 20px; }
                    h1 { font-size: 18px; margin-bottom: 20px; }
                    .code { padding: 8px; margin: 4px 0; background: #f5f5f5; border-radius: 4px; }
                    .warning { color: #dc3545; margin-top: 20px; font-size: 12px; }
                    @media print { .no-print { display: none; } }
                </style>
            </head>
            <body>
                <h1>MFA Backup Codes</h1>
                <p>Store these codes in a safe place. Each code can only be used once.</p>
                ${codes.map(code => `<div class="code">${code}</div>`).join('')}
                <p class="warning">⚠️ Keep these codes secure. Anyone with these codes can access your account.</p>
                <script>window.print(); window.close();</script>
            </body>
            </html>
        `;

        printWindow.document.write(html);
        printWindow.document.close();
    }

    /**
     * Download backup codes as text file
     */
    download(event) {
        event.preventDefault();

        const codes = this.getCodesText();
        const header = "MFA Backup Codes\n" +
                      "================\n" +
                      "Store these codes in a safe place.\n" +
                      "Each code can only be used once.\n\n";
        const footer = "\n\n⚠️ Keep these codes secure!";

        const content = header + codes + footer;
        const blob = new Blob([content], { type: 'text/plain' });
        const url = URL.createObjectURL(blob);

        const link = document.createElement('a');
        link.href = url;
        link.download = this.filenameValue;
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        URL.revokeObjectURL(url);

        this.showFeedback(event.currentTarget, 'success', 'bi-check');
    }

    /**
     * Get codes as array
     */
    getCodesArray() {
        if (this.hasCodesValue && this.codesValue.length > 0) {
            return this.codesValue;
        }

        // Fallback: extract from DOM
        if (this.hasCodesContainerTarget) {
            const codeElements = this.codesContainerTarget.querySelectorAll('.font-monospace');
            return Array.from(codeElements).map(el => el.textContent.trim());
        }

        return [];
    }

    /**
     * Get codes as text string
     */
    getCodesText() {
        return this.getCodesArray().join('\n');
    }

    /**
     * Fallback copy method for older browsers
     */
    fallbackCopy(text) {
        const textarea = document.createElement('textarea');
        textarea.value = text;
        textarea.style.position = 'fixed';
        textarea.style.opacity = '0';
        document.body.appendChild(textarea);
        textarea.select();

        try {
            document.execCommand('copy');
        } catch (err) {
            alert('Failed to copy. Please copy manually.');
        }

        document.body.removeChild(textarea);
    }

    /**
     * Show visual feedback on button
     */
    showFeedback(button, type, iconClass) {
        const originalHTML = button.innerHTML;
        const originalIcon = button.querySelector('i');

        if (originalIcon) {
            originalIcon.className = iconClass;
        }

        setTimeout(() => {
            button.innerHTML = originalHTML;
        }, 1500);
    }
}
