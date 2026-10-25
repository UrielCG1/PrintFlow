import { Controller } from '@hotwired/stimulus';
import { startCodeScan } from '../../js/ui/zxing-scanner.js';

const materialQrPattern = /^PF:MAT:1:[a-f0-9]{48}$/i;

export default class extends Controller {
    static targets = ['equipment', 'material', 'equipmentFeedback', 'materialFeedback', 'submit', 'message', 'mode', 'panel', 'video'];
    static values = {
        src: String,
        expectedEquipmentCode: String,
        expectedEquipmentName: String,
        previewUrl: String,
        previewToken: String,
    };

    connect() {
        this.running = false;
        this.cancelled = false;
        this.controls = null;
        this.materialRequestId = 0;
        this.materialPending = false;
        this.materialValid = false;
        this.equipmentValid = false;
        if (this.hasEquipmentTarget) this.checkEquipment();
        if (this.hasMaterialTarget) this.checkMaterial();
    }

    checkEquipment() {
        const code = this.equipmentTarget.value.trim().toUpperCase();
        const expected = this.expectedEquipmentCodeValue.trim().toUpperCase();
        this.equipmentValid = code !== '' && expected !== '' && code === expected;
        if (!code) {
            this.feedback(this.equipmentTarget, this.equipmentFeedbackTarget, '', '');
        } else if (expected.startsWith(code) && !this.equipmentValid) {
            this.feedback(this.equipmentTarget, this.equipmentFeedbackTarget, 'Continúa leyendo el código de la máquina…', 'pending');
        } else if (!this.equipmentValid) {
            this.feedback(this.equipmentTarget, this.equipmentFeedbackTarget,
                `Máquina incorrecta. Escanea ${this.expectedEquipmentNameValue} (${this.expectedEquipmentCodeValue}).`, 'error');
        } else {
            this.feedback(this.equipmentTarget, this.equipmentFeedbackTarget,
                `Máquina correcta: ${this.expectedEquipmentNameValue}.`, 'success');
        }
        this.refreshSubmit();
    }

    checkMaterial() {
        clearTimeout(this.materialTimer);
        this.materialAbort?.abort();
        const requestId = ++this.materialRequestId;
        const code = this.materialTarget.value.trim().toUpperCase();
        this.materialValid = false;
        this.materialPending = false;

        if (!code) {
            this.feedback(this.materialTarget, this.materialFeedbackTarget, '', '');
        } else if (!materialQrPattern.test(code)) {
            this.materialPending = true;
            this.feedback(this.materialTarget, this.materialFeedbackTarget, 'Esperando el código QR completo…', 'pending');
            this.materialTimer = setTimeout(() => {
                if (requestId !== this.materialRequestId) return;
                this.materialPending = false;
                this.feedback(this.materialTarget, this.materialFeedbackTarget,
                    'Este código no es una etiqueta QR de material válida de OOXCorp.', 'error');
                this.refreshSubmit();
            }, 350);
        } else {
            this.materialPending = true;
            this.feedback(this.materialTarget, this.materialFeedbackTarget, 'Comprobando el material escaneado…', 'pending');
            this.materialTimer = setTimeout(() => this.previewMaterial(code, requestId), 200);
        }
        this.refreshSubmit();
    }

    async previewMaterial(code, requestId) {
        this.materialAbort = new AbortController();
        try {
            const response = await fetch(this.previewUrlValue, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json' },
                body: new URLSearchParams({ _token: this.previewTokenValue, material_qr_payload: code }),
                signal: this.materialAbort.signal,
            });
            if (!response.ok) throw new Error('No se pudo consultar el material.');
            const result = await response.json();
            if (requestId !== this.materialRequestId) return;
            this.materialPending = false;
            this.materialValid = result.valid === true;
            this.feedback(this.materialTarget, this.materialFeedbackTarget, result.message,
                this.materialValid ? 'success' : 'error');
        } catch (error) {
            if (error.name === 'AbortError' || requestId !== this.materialRequestId) return;
            this.materialPending = false;
            this.materialValid = true;
            this.feedback(this.materialTarget, this.materialFeedbackTarget,
                'No se pudo comprobar el QR ahora. Al pulsar «Validar escaneos», el servidor lo verificará.', 'warning');
        }
        this.refreshSubmit();
    }

    submit(event) {
        if (this.equipmentValid && this.materialValid && !this.materialPending) return;
        event.preventDefault();
        if (!this.equipmentValid) this.equipmentTarget.focus();
        else this.materialTarget.focus();
    }

    feedback(input, target, message, state) {
        if (state === 'error') target.setAttribute('role', 'alert');
        else target.removeAttribute('role');
        target.dataset.state = state;
        target.textContent = message;
        input.classList.toggle('is-invalid', state === 'error');
        if (state === 'error') input.setAttribute('aria-invalid', 'true');
        else input.removeAttribute('aria-invalid');
        input.setCustomValidity(state === 'error' ? message : '');
    }

    refreshSubmit() {
        if (this.hasSubmitTarget) this.submitTarget.disabled = !this.equipmentValid || !this.materialValid || this.materialPending;
    }

    async scanEquipment() {
        await this.scanInto(this.equipmentTarget, 'máquina', 'BrowserMultiFormatReader');
    }

    async scanMaterial() {
        await this.scanInto(this.materialTarget, 'material', 'BrowserQRCodeReader');
    }

    async scanInto(input, label, readerClass) {
        if (this.running) return;
        if (!navigator.mediaDevices?.getUserMedia) {
            this.show('La cámara requiere HTTPS y permiso del navegador. También puedes usar un lector USB.', 'error');
            return;
        }

        this.running = true;
        this.cancelled = false;
        this.panelTarget.hidden = false;
        this.show(`Apunta la cámara al código de ${label}.`);

        try {
            const controls = await startCodeScan(this.srcValue, this.videoTarget, readerClass, (value, activeControls) => {
                if (!this.running) return;
                const code = value.trim();
                if (readerClass === 'BrowserQRCodeReader' && !materialQrPattern.test(code)) {
                    this.show('Este QR no es una etiqueta de material de OOXCorp.', 'error');
                    return;
                }

                input.value = code.toUpperCase();
                input.dispatchEvent(new Event('input', { bubbles: true }));
                this.modeTarget.value = 'SCANNER';
                this.stopCamera(activeControls);
                this.show(`Código de ${label} leído. Revisa el resultado junto al campo.`);
            });
            this.controls = controls;
            if (this.cancelled) controls.stop();
        } catch (_error) {
            this.stopCamera();
            this.show('No se pudo abrir la cámara o cargar el lector. Revisa el permiso del navegador o usa un lector USB.', 'error');
        }
    }

    stop() {
        this.stopCamera();
        this.show('Lectura cancelada.');
    }

    disconnect() {
        clearTimeout(this.materialTimer);
        this.materialAbort?.abort();
        this.stopCamera();
    }

    stopCamera(controls = this.controls) {
        this.cancelled = true;
        this.running = false;
        this.controls = null;
        try { controls?.stop(); } catch (_error) { /* The browser may already have closed the stream. */ }
        if (!this.hasVideoTarget) return;
        const stream = this.videoTarget.srcObject;
        stream?.getTracks().forEach((track) => track.stop());
        this.videoTarget.srcObject = null;
        if (this.hasPanelTarget) this.panelTarget.hidden = true;
    }

    show(message, state = '') {
        if (state === 'error') this.messageTarget.setAttribute('role', 'alert');
        else this.messageTarget.removeAttribute('role');
        this.messageTarget.dataset.state = state;
        this.messageTarget.textContent = message;
    }
}
