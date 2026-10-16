let loading;

export async function loadZxing(scriptUrl) {
    if (window.ZXingBrowser?.BrowserQRCodeReader) {
        return window.ZXingBrowser;
    }

    if (!loading) {
        loading = new Promise((resolve, reject) => {
            const script = document.createElement('script');
            script.src = scriptUrl;
            script.async = true;
            script.onload = () => {
                if (window.ZXingBrowser?.BrowserQRCodeReader) {
                    resolve(window.ZXingBrowser);
                } else {
                    reject(new Error('El lector QR no se inicializó.'));
                }
            };
            script.onerror = () => {
                script.remove();
                reject(new Error('No se pudo cargar el lector QR.'));
            };
            document.head.appendChild(script);
        }).catch((error) => {
            loading = null;
            throw error;
        });
    }

    return loading;
}

export async function startCodeScan(scriptUrl, video, readerClass, onResult) {
    const zxing = await loadZxing(scriptUrl);
    const Reader = zxing[readerClass];
    if (!Reader) {
        throw new Error('El lector solicitado no está disponible.');
    }

    const reader = new Reader();
    return reader.decodeFromConstraints(
        { video: { facingMode: { ideal: 'environment' } } },
        video,
        (result, _error, controls) => {
            if (result) {
                onResult(result.getText(), controls);
            }
        },
    );
}
