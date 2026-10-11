import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['equipment', 'material', 'message', 'mode'];

    async scanEquipment() {
        await this.scanInto(this.equipmentTarget, 'máquina');
    }

    async scanMaterial() {
        await this.scanInto(this.materialTarget, 'material');
    }

    async scanInto(input, label) {
        if (!('BarcodeDetector' in window) || !navigator.mediaDevices?.getUserMedia) {
            this.show('La cámara no está disponible en este navegador. Usa el lector externo o solicita captura manual autorizada.');
            return;
        }
        let stream;
        try {
            const detector = new window.BarcodeDetector({ formats: ['code_128', 'code_39', 'ean_13', 'ean_8', 'qr_code'] });
            stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: { ideal: 'environment' } } });
            const video = document.createElement('video');
            video.srcObject = stream;
            video.playsInline = true;
            await video.play();
            this.show(`Apunta la cámara al código de ${label}.`);
            const deadline = Date.now() + 30000;
            while (Date.now() < deadline) {
                const codes = await detector.detect(video);
                if (codes.length > 0 && codes[0].rawValue) {
                    input.value = codes[0].rawValue.trim().toUpperCase();
                    this.modeTarget.value = 'SCANNER';
                    this.show(`Código de ${label} leído.`);
                    return;
                }
                await new Promise((resolve) => window.setTimeout(resolve, 250));
            }
            this.show('No se detectó un código. Intenta de nuevo con mejor iluminación o usa el lector externo.');
        } catch (error) {
            this.show('No se pudo abrir la cámara. Verifica el permiso del navegador o usa el lector externo.');
        } finally {
            stream?.getTracks().forEach((track) => track.stop());
        }
    }

    show(message) {
        this.messageTarget.textContent = message;
    }
}
