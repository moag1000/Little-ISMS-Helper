import { Controller } from '@hotwired/stimulus';

/**
 * File Upload Controller with Drag & Drop
 *
 * Features:
 * - Drag & Drop file upload
 * - Visual feedback during drag
 * - File type validation
 * - File size validation
 * - Multiple file support
 * - Preview generation
 * - Progress indication
 */
export default class extends Controller {
    static targets = ['dropzone', 'fileInput', 'fileList', 'uploadButton', 'form'];
    static values = {
        maxFileSize: { type: Number, default: 10485760 }, // 10MB in bytes
        allowedTypes: { type: Array, default: [
            'application/pdf',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'image/jpeg',
            'image/png',
            'image/gif',
            'image/webp',
            'text/plain'
        ]},
        uploadUrl: String
    };

    connect() {
        this.selectedFiles = [];
        this.setupDragAndDrop();
    }

    setupDragAndDrop() {
        if (!this.hasDropzoneTarget) return;

        // Prevent default drag behaviors on the whole document
        ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
            document.body.addEventListener(eventName, this.preventDefaults.bind(this), false);
        });

        // Highlight drop zone when item is dragged over it
        ['dragenter', 'dragover'].forEach(eventName => {
            this.dropzoneTarget.addEventListener(eventName, this.highlight.bind(this), false);
        });

        ['dragleave', 'drop'].forEach(eventName => {
            this.dropzoneTarget.addEventListener(eventName, this.unhighlight.bind(this), false);
        });

        // Handle dropped files
        this.dropzoneTarget.addEventListener('drop', this.handleDrop.bind(this), false);
    }

    preventDefaults(event) {
        event.preventDefault();
        event.stopPropagation();
    }

    highlight(event) {
        this.dropzoneTarget.classList.add('drag-over');
    }

    unhighlight(event) {
        this.dropzoneTarget.classList.remove('drag-over');
    }

    handleDrop(event) {
        const dataTransfer = event.dataTransfer;
        const files = dataTransfer.files;
        this.handleFiles(files);
    }

    // Triggered when user clicks the dropzone
    triggerFileInput() {
        if (this.hasFileInputTarget) {
            this.fileInputTarget.click();
        }
    }

    // Triggered when user selects files via file input
    handleFileInputChange(event) {
        const files = event.target.files;
        this.handleFiles(files);
    }

    handleFiles(files) {
        // Convert FileList to Array
        const filesArray = Array.from(files);

        // Validate and add files
        filesArray.forEach(file => {
            if (this.validateFile(file)) {
                this.selectedFiles.push(file);
            }
        });

        // Update UI
        this.updateFileList();
        this.updateUploadButton();
    }

    validateFile(file) {
        // Check file size
        if (file.size > this.maxFileSizeValue) {
            this.showError(`Datei "${file.name}" ist zu groß. Maximum: ${this.formatFileSize(this.maxFileSizeValue)}`);
            return false;
        }

        // Check file type
        if (this.allowedTypesValue.length > 0 && !this.allowedTypesValue.includes(file.type)) {
            // Also check by extension for common types
            const extension = file.name.split('.').pop().toLowerCase();
            const allowedExtensions = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'txt'];

            if (!allowedExtensions.includes(extension)) {
                this.showError(`Datei "${file.name}" hat einen ungültigen Dateityp.`);
                return false;
            }
        }

        return true;
    }

    updateFileList() {
        if (!this.hasFileListTarget) return;

        this.fileListTarget.innerHTML = '';

        if (this.selectedFiles.length === 0) {
            this.fileListTarget.innerHTML = '<p class="text-muted">Keine Dateien ausgewählt</p>';
            return;
        }

        const list = document.createElement('div');
        list.className = 'selected-files-list';

        this.selectedFiles.forEach((file, index) => {
            const fileItem = this.createFileItem(file, index);
            list.appendChild(fileItem);
        });

        this.fileListTarget.appendChild(list);
    }

    createFileItem(file, index) {
        const item = document.createElement('div');
        item.className = 'file-item';
        item.dataset.index = index;

        // File icon
        const icon = this.getFileIcon(file.type, file.name);

        // File info
        const fileName = document.createElement('span');
        fileName.className = 'file-name';
        fileName.textContent = file.name;

        const fileSize = document.createElement('span');
        fileSize.className = 'file-size text-muted';
        fileSize.textContent = this.formatFileSize(file.size);

        // Remove button
        const removeBtn = document.createElement('button');
        removeBtn.type = 'button';
        removeBtn.className = 'btn btn-sm btn-outline-danger';
        removeBtn.innerHTML = '<i class="bi-x-lg"></i>';
        removeBtn.addEventListener('click', () => this.removeFile(index));

        // Assemble item
        const iconElement = document.createElement('span');
        iconElement.innerHTML = icon;
        iconElement.className = 'file-icon me-3';

        const infoDiv = document.createElement('div');
        infoDiv.className = 'file-info flex-grow-1';
        infoDiv.appendChild(fileName);
        infoDiv.appendChild(document.createElement('br'));
        infoDiv.appendChild(fileSize);

        item.appendChild(iconElement);
        item.appendChild(infoDiv);
        item.appendChild(removeBtn);

        return item;
    }

    getFileIcon(mimeType, fileName) {
        const extension = fileName.split('.').pop().toLowerCase();

        if (mimeType === 'application/pdf' || extension === 'pdf') {
            return '<i class="bi-file-pdf-fill text-danger" style="font-size: 2rem;"></i>';
        } else if (mimeType.startsWith('image/') || ['jpg', 'jpeg', 'png', 'gif', 'webp'].includes(extension)) {
            return '<i class="bi-file-image-fill text-primary" style="font-size: 2rem;"></i>';
        } else if (mimeType.includes('spreadsheet') || ['xls', 'xlsx'].includes(extension)) {
            return '<i class="bi-file-earmark-spreadsheet-fill text-success" style="font-size: 2rem;"></i>';
        } else if (mimeType.includes('word') || mimeType.includes('document') || ['doc', 'docx'].includes(extension)) {
            return '<i class="bi-file-word-fill text-info" style="font-size: 2rem;"></i>';
        } else if (mimeType === 'text/plain' || extension === 'txt') {
            return '<i class="bi-file-text-fill text-secondary" style="font-size: 2rem;"></i>';
        } else {
            return '<i class="bi-file-earmark-fill text-secondary" style="font-size: 2rem;"></i>';
        }
    }

    removeFile(index) {
        this.selectedFiles.splice(index, 1);
        this.updateFileList();
        this.updateUploadButton();

        // Clear file input
        if (this.hasFileInputTarget) {
            this.fileInputTarget.value = '';
        }
    }

    updateUploadButton() {
        if (!this.hasUploadButtonTarget) return;

        if (this.selectedFiles.length > 0) {
            this.uploadButtonTarget.disabled = false;
            this.uploadButtonTarget.textContent = `${this.selectedFiles.length} ${this.selectedFiles.length === 1 ? 'Datei' : 'Dateien'} hochladen`;
        } else {
            this.uploadButtonTarget.disabled = true;
            this.uploadButtonTarget.textContent = 'Dateien hochladen';
        }
    }

    formatFileSize(bytes) {
        if (bytes === 0) return '0 Bytes';

        const k = 1024;
        const sizes = ['Bytes', 'KB', 'MB', 'GB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));

        return Math.round((bytes / Math.pow(k, i)) * 100) / 100 + ' ' + sizes[i];
    }

    showError(message) {
        // Create toast notification
        const toast = document.createElement('div');
        toast.className = 'alert alert-danger alert-dismissible fade show';
        toast.style.position = 'fixed';
        toast.style.top = '20px';
        toast.style.right = '20px';
        toast.style.zIndex = '9999';
        toast.innerHTML = `
            ${message}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        `;

        document.body.appendChild(toast);

        // Auto-remove after 5 seconds
        setTimeout(() => {
            toast.remove();
        }, 5000);
    }

    // Upload files (if using AJAX)
    async uploadFiles() {
        if (this.selectedFiles.length === 0) return;

        // If uploadUrl is provided, use AJAX upload
        if (this.hasUploadUrlValue) {
            await this.uploadViaAjax();
        } else {
            // Otherwise, use standard form submission with DataTransfer
            this.uploadViaForm();
        }
    }

    async uploadViaAjax() {
        const formData = new FormData();

        // Add files
        this.selectedFiles.forEach((file, index) => {
            formData.append(`files[${index}]`, file);
        });

        try {
            const response = await fetch(this.uploadUrlValue, {
                method: 'POST',
                body: formData,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });

            if (response.ok) {
                const result = await response.json();
                this.showSuccess('Dateien erfolgreich hochgeladen!');

                // Reload or redirect
                setTimeout(() => {
                    window.location.reload();
                }, 1500);
            } else {
                throw new Error('Upload failed');
            }
        } catch (error) {
            this.showError('Fehler beim Hochladen der Dateien.');
        }
    }

    uploadViaForm() {
        // Create a DataTransfer object to set files on the file input
        if (this.hasFileInputTarget && this.hasFormTarget) {
            const dataTransfer = new DataTransfer();

            this.selectedFiles.forEach(file => {
                dataTransfer.items.add(file);
            });

            this.fileInputTarget.files = dataTransfer.files;

            // Submit the form
            this.formTarget.submit();
        }
    }

    showSuccess(message) {
        const toast = document.createElement('div');
        toast.className = 'alert alert-success alert-dismissible fade show';
        toast.style.position = 'fixed';
        toast.style.top = '20px';
        toast.style.right = '20px';
        toast.style.zIndex = '9999';
        toast.innerHTML = `
            ${message}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        `;

        document.body.appendChild(toast);

        setTimeout(() => {
            toast.remove();
        }, 3000);
    }
}
