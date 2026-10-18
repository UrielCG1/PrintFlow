import { Controller } from '@hotwired/stimulus';

const viewableMimeTypes = new Set([
    'application/pdf',
    'image/jpeg',
    'image/png',
    'image/webp',
]);

export default class extends Controller {
    static targets = ['fileInput', 'preview', 'modal', 'modalTitle', 'modalBody', 'modalDownload'];

    disconnect() {
        this.releasePreviewUrl();
    }

    previewFile(event) {
        this.releasePreviewUrl();
        this.previewTarget.replaceChildren();

        const file = event.currentTarget.files?.[0];
        if (!file) {
            this.previewTarget.hidden = true;
            return;
        }

        const url = URL.createObjectURL(file);
        this.previewUrl = url;
        this.previewTarget.dataset.previewUrl = url;
        this.previewTarget.dataset.fileName = file.name;
        this.previewTarget.dataset.fileType = file.type;
        this.previewTarget.hidden = false;
        this.previewTarget.append(this.createCard({
            label: 'Archivo seleccionado',
            name: file.name,
            metadata: `${this.formatBytes(file.size)} · ${this.fileTypeLabel(file.type)}`,
            previewUrl: url,
            fileType: file.type,
        }));
    }

    openSelectedPreview() {
        const url = this.previewTarget.dataset.previewUrl;
        const name = this.previewTarget.dataset.fileName;
        const fileType = this.previewTarget.dataset.fileType;
        if (url && name) {
            this.showPreview(url, name, url, fileType);
        }
    }

    openExistingPreview(event) {
        const button = event.currentTarget;
        this.showPreview(
            button.dataset.previewUrl,
            button.dataset.fileName,
            button.dataset.downloadUrl,
            button.dataset.fileType,
        );
    }

    showPreview(previewUrl, fileName, downloadUrl, fileType) {
        if (!previewUrl || !fileName || !window.bootstrap?.Modal || !this.hasModalTarget) {
            return;
        }

        this.modalTitleTarget.textContent = fileName;
        this.modalBodyTarget.replaceChildren();

        if (fileType === 'application/pdf') {
            const documentPreview = document.createElement('object');
            documentPreview.className = 'quotation-file-modal__document';
            documentPreview.data = previewUrl;
            documentPreview.type = fileType;
            documentPreview.setAttribute('aria-label', `Vista ampliada de ${fileName}`);
            const fallback = document.createElement('p');
            fallback.className = 'quotation-file-modal__unsupported';
            fallback.textContent = 'No fue posible mostrar el PDF en este navegador. Puedes descargar el original.';
            documentPreview.append(fallback);
            this.modalBodyTarget.append(documentPreview);
        } else if (viewableMimeTypes.has(fileType) && fileType.startsWith('image/')) {
            const imagePreview = document.createElement('img');
            imagePreview.className = 'quotation-file-modal__image';
            imagePreview.src = previewUrl;
            imagePreview.alt = `Vista ampliada de ${fileName}`;
            this.modalBodyTarget.append(imagePreview);
        } else {
            const unsupported = document.createElement('p');
            unsupported.className = 'quotation-file-modal__unsupported';
            unsupported.textContent = 'Este formato no se puede mostrar en el visor. Descarga el original para abrirlo en su aplicación.';
            this.modalBodyTarget.append(unsupported);
        }

        this.modalDownloadTarget.href = downloadUrl || previewUrl;
        this.modalDownloadTarget.download = fileName;
        this.modalDownloadTarget.hidden = false;
        this.modalInstance = window.bootstrap.Modal.getOrCreateInstance(this.modalTarget);
        this.modalInstance.show();
    }

    createCard({ label, name, metadata, previewUrl, fileType }) {
        const card = document.createElement('div');
        card.className = 'quotation-file-preview__card';

        const thumbnail = document.createElement('div');
        thumbnail.className = 'quotation-file-preview__thumbnail';

        if (fileType === 'application/pdf') {
            const documentPreview = document.createElement('object');
            documentPreview.className = 'quotation-file-preview__document';
            documentPreview.data = previewUrl;
            documentPreview.type = fileType;
            documentPreview.setAttribute('aria-label', `Vista previa de ${name}`);
            thumbnail.append(documentPreview);
        } else if (viewableMimeTypes.has(fileType) && fileType.startsWith('image/')) {
            const imagePreview = document.createElement('img');
            imagePreview.className = 'quotation-file-preview__image';
            imagePreview.src = previewUrl;
            imagePreview.alt = `Vista previa de ${name}`;
            thumbnail.append(imagePreview);
        } else {
            const icon = document.createElement('i');
            icon.className = 'bi bi-file-earmark-check';
            icon.setAttribute('aria-hidden', 'true');
            thumbnail.append(icon);
        }

        const details = document.createElement('div');
        details.className = 'quotation-file-preview__details';
        const labelElement = document.createElement('span');
        labelElement.className = 'quotation-file-preview__label';
        labelElement.textContent = label;
        const nameElement = document.createElement('strong');
        nameElement.className = 'quotation-file-preview__name';
        nameElement.textContent = name;
        nameElement.title = name;
        const metadataElement = document.createElement('span');
        metadataElement.className = 'quotation-file-preview__metadata';
        metadataElement.textContent = metadata;
        details.append(labelElement, nameElement, metadataElement);

        const openButton = document.createElement('button');
        openButton.type = 'button';
        openButton.className = 'btn pf-button pf-button--subtle pf-button--sm quotation-file-preview__open';
        openButton.dataset.action = `${this.identifier}#openSelectedPreview`;
        openButton.setAttribute('aria-label', `Visualizar ${name} en grande`);
        openButton.title = 'Visualizar archivo';
        const eye = document.createElement('i');
        eye.className = 'bi bi-eye';
        eye.setAttribute('aria-hidden', 'true');
        openButton.append(eye);

        card.append(thumbnail, details, openButton);

        return card;
    }

    releasePreviewUrl() {
        if (this.previewUrl) {
            URL.revokeObjectURL(this.previewUrl);
            this.previewUrl = null;
        }
        if (this.hasPreviewTarget) {
            delete this.previewTarget.dataset.previewUrl;
            delete this.previewTarget.dataset.fileName;
            delete this.previewTarget.dataset.fileType;
        }
    }

    formatBytes(bytes) {
        return bytes < 1024 * 1024
            ? `${Math.max(1, Math.round(bytes / 1024))} KB`
            : `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
    }

    fileTypeLabel(fileType) {
        if (fileType === 'application/pdf') {
            return 'PDF';
        }

        if (fileType.startsWith('image/')) {
            return 'Imagen';
        }

        return fileType || 'Archivo';
    }
}
