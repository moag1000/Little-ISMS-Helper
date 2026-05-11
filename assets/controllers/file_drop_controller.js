import { Controller } from '@hotwired/stimulus';

/**
 * FileDrop Controller — Bulk-Import Wizard Step 1 (F2.9)
 *
 * Provides drag-and-drop file selection for the import upload form.
 * Validates MIME type and file size client-side before submit.
 * Emits Alva mood events on valid/invalid selection (F2.12).
 *
 * Targets:
 *   input     — the <input type="file"> element
 *   dropzone  — the clickable drag-drop zone element
 *   preview   — the element where filename + size is rendered after pick
 *
 * Values:
 *   maxSize     (Number)  — max bytes (default 10 MB)
 *   allowedExts (String)  — comma-separated extensions (default "xlsx,xls,csv,ods")
 *
 * Usage:
 *   <div data-controller="file-drop"
 *        data-file-drop-max-size-value="10485760"
 *        data-file-drop-allowed-exts-value="xlsx,xls,csv,ods">
 *     <div data-file-drop-target="dropzone" data-action="click->file-drop#openPicker dragover->file-drop#onDragOver dragleave->file-drop#onDragLeave drop->file-drop#onDrop">
 *       …
 *     </div>
 *     <input type="file" data-file-drop-target="input" data-action="change->file-drop#onSelected" hidden />
 *     <div data-file-drop-target="preview"></div>
 *   </div>
 */
export default class extends Controller {
    static targets = ['input', 'dropzone', 'preview'];
    static values = {
        maxSize: { type: Number, default: 10 * 1024 * 1024 },        // 10 MB
        allowedExts: { type: String, default: 'xlsx,xls,csv,ods' },
    };

    connect() {
        console.log('[file-drop] controller connected');
        this._dragCount = 0;
    }

    // ── Public actions ─────────────────────────────────────────────────────

    openPicker() {
        if (this.hasInputTarget) {
            this.inputTarget.click();
        }
    }

    onDragOver(event) {
        event.preventDefault();
        event.stopPropagation();
        this._dragCount++;
        this.dropzoneTarget.classList.add('file-drop--drag-over');
    }

    onDragLeave(event) {
        event.preventDefault();
        event.stopPropagation();
        this._dragCount = Math.max(0, this._dragCount - 1);
        if (this._dragCount === 0) {
            this.dropzoneTarget.classList.remove('file-drop--drag-over');
        }
    }

    onDrop(event) {
        event.preventDefault();
        event.stopPropagation();
        this._dragCount = 0;
        this.dropzoneTarget.classList.remove('file-drop--drag-over');

        const files = event.dataTransfer?.files;
        if (files && files.length > 0) {
            this._handleFile(files[0]);
        }
    }

    onSelected(event) {
        const files = event.target?.files;
        if (files && files.length > 0) {
            this._handleFile(files[0]);
        }
    }

    // ── Private helpers ────────────────────────────────────────────────────

    _handleFile(file) {
        const error = this._validate(file);

        if (error) {
            this._showPreview({ error, file });
            this._emitAlva('warning');
            return;
        }

        // Transfer to the real input so the form can submit it
        if (this.hasInputTarget && typeof DataTransfer !== 'undefined') {
            const dt = new DataTransfer();
            dt.items.add(file);
            this.inputTarget.files = dt.files;
        }

        this._showPreview({ file });
        this._emitAlva('working');
    }

    _validate(file) {
        const ext = (file.name.split('.').pop() || '').toLowerCase();
        const allowed = this.allowedExtsValue.split(',').map((e) => e.trim().toLowerCase());

        if (!allowed.includes(ext)) {
            return `Ungültiger Dateityp „.${ext}". Erlaubt: ${allowed.join(', ').toUpperCase()}.`;
        }

        if (file.size > this.maxSizeValue) {
            const maxMB = (this.maxSizeValue / (1024 * 1024)).toFixed(0);
            return `Datei ist zu groß (${this._formatSize(file.size)}). Maximal: ${maxMB} MB.`;
        }

        return null;
    }

    _showPreview({ file, error }) {
        if (!this.hasPreviewTarget) return;

        if (error) {
            this.previewTarget.innerHTML =
                `<div class="fa-alert fa-alert--danger mt-2" role="alert">` +
                `<i class="fa-icon fa-icon--status-critical" aria-hidden="true"></i>` +
                `<div class="fa-alert__message">${this._escapeHtml(error)}</div>` +
                `</div>`;
            return;
        }

        const icon = this._fileIcon(file.name);
        this.previewTarget.innerHTML =
            `<div class="file-drop__preview-row mt-2 d-flex align-items-center gap-2">` +
            `<i class="bi bi-${icon} fs-4" aria-hidden="true"></i>` +
            `<div>` +
            `<div class="fw-semibold small">${this._escapeHtml(file.name)}</div>` +
            `<div class="text-muted small">${this._formatSize(file.size)}</div>` +
            `</div>` +
            `</div>`;
    }

    _fileIcon(name) {
        const ext = (name.split('.').pop() || '').toLowerCase();
        const map = { xlsx: 'file-earmark-spreadsheet-fill', xls: 'file-earmark-spreadsheet-fill',
            csv: 'file-earmark-text-fill', ods: 'file-earmark-spreadsheet-fill' };
        return map[ext] || 'file-earmark-fill';
    }

    _formatSize(bytes) {
        if (bytes < 1024) return `${bytes} B`;
        if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
        return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
    }

    _escapeHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    _emitAlva(mood) {
        window.alvaBus?.emit({ mood, reason: 'file-drop' });
    }
}
