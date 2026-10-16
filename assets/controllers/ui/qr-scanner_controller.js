import { Controller } from '@hotwired/stimulus';
import { startCodeScan } from '../../js/ui/zxing-scanner.js';

export default class extends Controller {
    static targets = ['input', 'message', 'panel', 'video'];
    static values = { src: String };

    connect() {
        this.running = false;
        this.cancelled = false;
        this.controls = null;
    }

    async scan() {
        if (this.running) return;
        if (!navigator.mediaDevices?.getUserMedia) {
            this.show('La cámara requiere HTTPS y permiso del navegador. También puedes usar un lector USB.');
            return;
        }

        this.running = true;
        this.cancelled = false;
        this.panelTarget.hidden = false;
        this.show('Apunta la cámara al QR del material.');

        try {
            const controls = await startCodeScan(this.srcValue, this.videoTarget, 'BrowserQRCodeReader', (value, activeControls) => {
                if (!this.running) return;
                const payload = value.trim();
                if (!/^PF:MAT:1:[a-f0-9]{48}$/i.test(payload)) {
                    this.show('Este QR no es una etiqueta de material de OOXCorp.');
                    return;
                }

                this.inputTarget.value = payload;
                this.inputTarget.dispatchEvent(new Event('input', { bubbles: true }));
                this.stopCamera(activeControls);
                this.show('QR leído. Revisa los datos y continúa.');
                this.inputTarget.focus();
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
