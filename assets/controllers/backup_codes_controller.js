import { Controller } from '@hotwired/stimulus';

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
        // Translation strings
        titleText: { type: String, default: 'MFA Backup Codes' },
        userLabel: { type: String, default: 'User' },
        deviceLabel: { type: String, default: 'Device' },
        generatedLabel: { type: String, default: 'Generated' },
        warningTitle: { type: String, default: 'Important Security Notice!' },
        warningText: { type: String, default: 'Keep these codes secure. Anyone with these codes can access your account.' },
        codesLabel: { type: String, default: 'Backup Codes' },
        usageTitle: { type: String, default: 'Usage Instructions' },
        usage1: { type: String, default: 'Use backup codes when you cannot access your authenticator' },
        usage2: { type: String, default: 'Each code can only be used once' },
        usage3: { type: String, default: 'Generate new codes before running out' },
        usage4: { type: String, default: 'Store these codes in a secure location' },
        copiedText: { type: String, default: 'Copied!' },
        downloadedText: { type: String, default: 'Downloaded!' },
        copyFailedText: { type: String, default: 'Copy failed' }
    };

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
                this.showFeedback(button, 'success', this.copiedTextValue);
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
            alert('Please allow popups to print backup codes');
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
                <title>${this.titleTextValue} - ${userEmail}</title>
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
                <h1>${this.titleTextValue}</h1>

                <div class="info">
                    <p><strong>${this.userLabelValue}:</strong> ${userEmail}</p>
                    <p><strong>${this.deviceLabelValue}:</strong> ${deviceName}</p>
                    <p><strong>${this.generatedLabelValue}:</strong> ${generatedDate}</p>
                </div>

                <div class="warning">
                    <strong>⚠️ ${this.warningTitleValue}</strong><br>
                    ${this.warningTextValue}
                </div>

                <h2>${this.codesLabelValue} (${codes.length})</h2>
                <div class="codes">
                    ${codes.map((code, i) =>
                        '<div class="code-item"><span class="code-number">' + (i+1) + '</span>' + code + '</div>'
                    ).join('')}
                </div>

                <div class="info">
                    <h3>${this.usageTitleValue}</h3>
                    <ul>
                        <li>${this.usage1Value}</li>
                        <li>${this.usage2Value}</li>
                        <li>${this.usage3Value}</li>
                        <li>${this.usage4Value}</li>
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
        content += 'MFA BACKUP CODES\n';
        content += '='.repeat(60) + '\n\n';
        content += `${this.userLabelValue}: ${userEmail}\n`;
        content += `${this.deviceLabelValue}: ${deviceName}\n`;
        content += `${this.generatedLabelValue}: ${new Date().toLocaleString()}\n\n`;
        content += `⚠️  ${this.warningTitleValue}\n`;
        content += `${this.warningTextValue}\n\n`;
        content += '='.repeat(60) + '\n';
        content += `${this.codesLabelValue} (${codes.length})\n`;
        content += '='.repeat(60) + '\n\n';

        codes.forEach((code, i) => {
            content += `${(i+1).toString().padStart(2, '0')}. ${code}\n`;
        });

        content += '\n' + '='.repeat(60) + '\n';
        content += `${this.usageTitleValue}\n`;
        content += '='.repeat(60) + '\n';
        content += `1. ${this.usage1Value}\n`;
        content += `2. ${this.usage2Value}\n`;
        content += `3. ${this.usage3Value}\n`;
        content += `4. ${this.usage4Value}\n`;

        const blob = new Blob([content], { type: 'text/plain' });
        const url = window.URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = filename;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        window.URL.revokeObjectURL(url);

        this.showFeedback(button, 'success', this.downloadedTextValue);
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
            this.showFeedback(button, 'success', this.copiedTextValue);
        } catch (err) {
            alert(this.copyFailedTextValue);
        }

        document.body.removeChild(textarea);
    }

    /**
     * Show visual feedback on button
     */
    showFeedback(button, type, text) {
        const originalHTML = button.innerHTML;
        const originalClasses = [...button.classList];

        button.innerHTML = `<i class="bi bi-check" aria-hidden="true"></i> ${text}`;
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
