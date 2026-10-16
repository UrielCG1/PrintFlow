import { Controller } from '@hotwired/stimulus';
import { startCodeScan } from '../../js/ui/zxing-scanner.js';

export default class extends Controller {
    static targets = ['equipment', 'material', 'message', 'mode', 'panel', 'video'];
    static values = { src: String };

    connect() {
        this.running = false;
        this.cancelled = false;
        this.controls = null;
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
            this.show('La cámara requiere HTTPS y permiso del navegador. También puedes usar un lector USB.');
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
                if (readerClass === 'BrowserQRCodeReader' && !/^PF:MAT:1:[a-f0-9]{48}$/i.test(code)) {
                    this.show('Este QR no es una etiqueta de material de OOXCorp.');
                    return;
                }

                input.value = code.toUpperCase();
                input.dispatchEvent(new Event('input', { bubbles: true }));
                this.modeTarget.value = 'SCANNER';
                this.stopCamera(activeControls);
                this.show(`Código de ${label} leído.`);
            });
            this.controls = controls;
            if (this.cancelled) controls.stop();
        } catch (_error) {
            this.stopCamera();
            this.show('No se pudo abrir la cámara o cargar el lector. Revisa el permiso del navegador o usa un lector USB.');
        }
    }

    stop() {
        this.stopCamera();
        this.show('Lectura cancelada.');
    }

    disconnect() {
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

    show(message) {
        this.messageTarget.textContent = message;
    }
}
