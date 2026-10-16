ZXing Browser 0.2.1, MIT license (`LICENSE`). The UMD build includes ZXing Library, Apache-2.0 license (`LICENSE-zxing-library`, copied from the 0.23.0 package).

Source: https://github.com/zxing-js/browser
Package: https://registry.npmjs.org/@zxing/browser/-/browser-0.2.1.tgz
Bundled library: https://registry.npmjs.org/@zxing/library/-/library-0.23.0.tgz

`zxing-browser.min.js` is the unmodified UMD distribution from the package. It is loaded only when a user opens the in-app camera scanner, so QR scanning does not depend on a runtime CDN request or on the browser's `BarcodeDetector` API.
