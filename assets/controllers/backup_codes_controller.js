import { Controller } from '@hotwired/stimulus';
import { faT } from '../js/fa-i18n.js';

/**
 * Backup Codes Controller
 *
 * Handles MFA backup codes operations: copy, print, download.
 *
 * Usage:
 * <div data-controller="backup-codes"
 *      data-backup-codes-codes-value='["code1", "code2"]'
 *      data-backup-codes-user-email-value="user@example.com"
 *      data-backup-codes-device-name-value="My Device">
 *     <button data-action="backup-codes#copy">Copy</button>
 *     <button data-action="backup-codes#print">Print</button>
 *     <button data-action="backup-codes#download">Download</button>
 * </div>
 */
export default class extends Controller {
    static targets = ['codesContainer'];

    static values = {
        codes: Array,
        filename: { type: String, default: 'backup-codes.txt' },
        userEmail: { type: String, default: '' },
        deviceName: { type: String, default: '' },
        // Labels (translated in the template; empty → faT('backup_codes.*'))
        titleText: { type: String, default: '' },
        userLabel: { type: String, default: '' },
        deviceLabel: { type: String, default: '' },
        generatedLabel: { type: String, default: '' },
        warningTitle: { type: String, default: '' },
        warningText: { type: String, default: '' },
        codesLabel: { type: String, default: '' },
        usageTitle: { type: String, default: '' },
        usage1: { type: String, default: '' },
        usage2: { type: String, default: '' },
        usage3: { type: String, default: '' },
        usage4: { type: String, default: '' },
        copiedText: { type: String, default: '' },
        downloadedText: { type: String, default: '' },
        copyFailedText: { type: String, default: '' }
    };

    label(name) {
        const fallback = {
            titleText: () => faT('backup_codes.title'),
            userLabel: () => faT('backup_codes.user'),
            deviceLabel: () => faT('backup_codes.device'),
            generatedLabel: () => faT('backup_codes.generated'),
            warningTitle: () => faT('backup_codes.warning_title'),
            warningText: () => faT('backup_codes.warning_text'),
            codesLabel: () => faT('backup_codes.codes'),
            usageTitle: () => faT('backup_codes.usage_title'),
            usage1: () => faT('backup_codes.usage_1'),
            usage2: () => faT('backup_codes.usage_2'),
            usage3: () => faT('backup_codes.usage_3'),
            usage4: () => faT('backup_codes.usage_4'),
            copiedText: () => faT('backup_codes.copied'),
            downloadedText: () => faT('backup_codes.downloaded'),
            copyFailedText: () => faT('backup_codes.copy_failed'),
        };
        return this[`${name}Value`] || fallback[name]();
    }

    /**
     * Copy all backup codes to clipboard
     */
    async copy(event) {
        event.preventDefault();

        const codes = this.getCodesText();
        const button = event.currentTarget;

        if (navigator.clipboard) {
            try {
                await navigator.clipboard.writeText(codes);
                this.showFeedback(button, 'success', this.label('copiedText'));
            } catch (err) {
                this.fallbackCopy(codes, button);
            }
        } else {
            this.fallbackCopy(codes, button);
        }
    }

    /**
     * Print backup codes with formatted layout
     */
    print(event) {
        event.preventDefault();

        const printWindow = window.open('', '', 'width=600,height=800');
        if (!printWindow) {
            window.faToast(faT('backup_codes.allow_popups'), 'warning');
            return;
        }

        const codes = this.getCodesArray();
        const userEmail = this.userEmailValue;
        const deviceName = this.deviceNameValue;
        const generatedDate = new Date().toLocaleString();

        const html = `
            <!DOCTYPE html>
            <html>
            <head>
                <title>${this.label('titleText')} - ${userEmail}</title>
                <style>
                    body {
                        font-family: Arial, sans-serif;
                        padding: 20px;
                        max-width: 800px;
                        margin: 0 auto;
                    }
                    h1 {
                        font-size: 24px;
                        border-bottom: 2px solid #333;
                        padding-bottom: 10px;
                    }
                    .info {
                        background: #f8f9fa;
                        padding: 15px;
                        border-left: 4px solid #007bff;
                        margin: 20px 0;
                    }
                    .warning {
                        background: #fff3cd;
                        padding: 15px;
                        border: 2px solid #ffc107;
                        margin: 20px 0;
                    }
                    .codes {
                        display: grid;
                        grid-template-columns: 1fr 1fr;
                        gap: 10px;
                        margin: 20px 0;
                    }
                    .code-item {
                        font-family: monospace;
                        font-size: 18px;
                        padding: 10px;
                        background: #f8f9fa;
                        border: 1px solid #dee2e6;
                        border-radius: 4px;
                    }
                    .code-number {
                        display: inline-block;
                        background: #6c757d;
                        color: white;
                        padding: 2px 8px;
                        border-radius: 3px;
                        font-size: 12px;
                        margin-right: 10px;
                    }
                    @media print {
                        body { padding: 0; }
                        .no-print { display: none; }
                    }
                </style>
            </head>
            <body>
                <h1>${this.label('titleText')}</h1>

                <div class="info">
                    <p><strong>${this.label('userLabel')}:</strong> ${userEmail}</p>
                    <p><strong>${this.label('deviceLabel')}:</strong> ${deviceName}</p>
                    <p><strong>${this.label('generatedLabel')}:</strong> ${generatedDate}</p>
                </div>

                <div class="warning">
                    <strong>⚠️ ${this.label('warningTitle')}</strong><br>
                    ${this.label('warningText')}
                </div>

                <h2>${this.label('codesLabel')} (${codes.length})</h2>
                <div class="codes">
                    ${codes.map((code, i) =>
                        '<div class="code-item"><span class="code-number">' + (i+1) + '</span>' + code + '</div>'
                    ).join('')}
                </div>

                <div class="info">
                    <h3>${this.label('usageTitle')}</h3>
                    <ul>
                        <li>${this.label('usage1')}</li>
                        <li>${this.label('usage2')}</li>
                        <li>${this.label('usage3')}</li>
                        <li>${this.label('usage4')}</li>
                    </ul>
                </div>
            </body>
            </html>
        `;

        printWindow.document.write(html);
        printWindow.document.close();
        printWindow.focus();
        setTimeout(() => printWindow.print(), 250);
    }

    /**
     * Download backup codes as text file
     */
    download(event) {
        event.preventDefault();

        const button = event.currentTarget;
        const codes = this.getCodesArray();
        const userEmail = this.userEmailValue;
        const deviceName = this.deviceNameValue;
        const timestamp = new Date().toISOString().replace(/[:.]/g, '-').slice(0, -5);
        const filename = `mfa-backup-codes-${userEmail}-${timestamp}.txt`;

        let content = '='.repeat(60) + '\n';
        content += `${this.label('titleText').toUpperCase()}\n`;
        content += '='.repeat(60) + '\n\n';
        content += `${this.label('userLabel')}: ${userEmail}\n`;
        content += `${this.label('deviceLabel')}: ${deviceName}\n`;
        content += `${this.label('generatedLabel')}: ${new Date().toLocaleString()}\n\n`;
        content += `⚠️  ${this.label('warningTitle')}\n`;
        content += `${this.label('warningText')}\n\n`;
        content += '='.repeat(60) + '\n';
        content += `${this.label('codesLabel')} (${codes.length})\n`;
        content += '='.repeat(60) + '\n\n';

        codes.forEach((code, i) => {
            content += `${(i+1).toString().padStart(2, '0')}. ${code}\n`;
        });

        content += '\n' + '='.repeat(60) + '\n';
        content += `${this.label('usageTitle')}\n`;
        content += '='.repeat(60) + '\n';
        content += `1. ${this.label('usage1')}\n`;
        content += `2. ${this.label('usage2')}\n`;
        content += `3. ${this.label('usage3')}\n`;
        content += `4. ${this.label('usage4')}\n`;

        const blob = new Blob([content], { type: 'text/plain' });
        const url = window.URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = filename;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        window.URL.revokeObjectURL(url);

        this.showFeedback(button, 'success', this.label('downloadedText'));
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
    fallbackCopy(text, button) {
        const textarea = document.createElement('textarea');
        textarea.value = text;
        textarea.style.position = 'fixed';
        textarea.style.opacity = '0';
        document.body.appendChild(textarea);
        textarea.select();

        try {
            document.execCommand('copy');
            this.showFeedback(button, 'success', this.label('copiedText'));
        } catch (err) {
            window.faToast(this.label('copyFailedText'), 'danger');
        }

        document.body.removeChild(textarea);
    }

    /**
     * Show visual feedback on button
     */
    showFeedback(button, type, text) {
        const originalHTML = button.innerHTML;
        const originalClasses = [...button.classList];

        button.innerHTML = `<i class="fa-icon fa-icon--ui-check" aria-hidden="true"></i> ${text}`;
        button.classList.remove('btn-primary', 'btn-outline-primary', 'btn-outline-secondary');
        button.classList.add('btn-success');

        setTimeout(() => {
            button.innerHTML = originalHTML;
            button.classList.remove('btn-success');
            // Restore original button classes
            originalClasses.forEach(cls => {
                if (cls.startsWith('btn-')) {
                    button.classList.add(cls);
                }
            });
        }, 2000);
    }
}
