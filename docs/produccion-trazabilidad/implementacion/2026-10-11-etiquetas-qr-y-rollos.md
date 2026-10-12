# Etiquetas QR de salida impresa y rollos de material

**Fecha:** 11 de octubre de 2026
**Estado:** dependencia y generación QR verificadas en local y Hostinger; vista de impresión vertical ajustada el 11 de octubre de 2026.

## Etiqueta que genera hoy PrintFlow

Al cerrar una ejecución de Impresión, PrintFlow registra una `ProductionPrintedOutput` vinculada a la partida, al intento de impresión y a la liberación de Diseño. La etiqueta de salida usa un código `PF-…` y un QR cuyo contenido identifica la orden, la partida y la salida vigente. La ruta para generar o reimprimir la etiqueta no vuelve a cerrar la impresión ni registra de nuevo el consumo.

Antes de agregar la dependencia QR, el cierre de impresión ya quedaba guardado y la etiqueta permanecía pendiente. Después de instalar la dependencia, se debe reintentar desde **Generar e imprimir etiqueta QR** de esa salida pendiente; no se debe volver a completar la impresión.

## Dependencia y verificación

- Se agregó `endroid/qr-code` `^5.1`; `composer.lock` fija la versión `5.1.0` y sus dependencias.
- La versión 5.1.0 admite PHP `^8.1`, por lo que es compatible con el PHP 8.2 usado localmente y requerido por el proyecto. Se usa `SvgWriter` para insertar el QR directamente en la etiqueta HTML.
- La salida SVG se genera sin declaración XML para incrustarla correctamente en HTML.
- Verificaciones locales: `composer validate`, `composer check-platform-reqs --no-dev`, lint de `PrintingLabelRenderer.php` y prueba directa que generó un SVG de QR válido. En Hostinger se confirmó `QR_OK` con `class_exists(\Endroid\QrCode\Writer\SvgWriter::class)`. No se necesita migración de base de datos.

## Diseño vigente de la etiqueta de salida

La etiqueta de salida impresa se presenta en vertical a **70 × 100 mm**. Usa una franja verde de marca, el nombre del producto, orden, partida, cantidad contratada, QR, código `PF-…` en texto legible y versión de salida. El tamaño CSS del SVG queda limitado a 32 mm para evitar que el QR invada los datos; los controles de impresión aparecen en pantalla y se ocultan en el papel.

La cantidad se identifica explícitamente como contratada; no debe confundirse con el consumo real de material capturado al cerrar la impresión. El QR contiene el identificador estructurado de orden, partida y salida, no una URL. Esta etiqueta identifica una salida impresa, no un rollo de materia prima.

## Etiqueta de rollo e inventario

La propuesta visual compartida —marca, proveedor o línea, nombre/código del material, fecha, descripción, QR y número legible del rollo— corresponde a identificar **un rollo físico de materia prima**. Debe conservarse como referencia visual para una futura etiqueta de rollo. El identificador principal visible debe combinar la identidad del material con un consecutivo único del rollo; el QR debe representar ese mismo rollo, no solo la variante genérica del catálogo.

La etiqueta QR implementada ahora corresponde a una **salida impresa de una partida**. No debe presentarse como etiqueta de rollo de inventario: actualmente el escaneo valida una variante consumible, pero no identifica un rollo ni lleva existencias o movimientos por rollo. El control de inventario sigue pospuesto según el FSD. Cuando se autorice ese alcance, primero se requiere modelar la recepción e identidad única de rollos; luego se adapta la propuesta del cliente a esos datos y a su flujo de escaneo.

## Despliegue en Hostinger

Para publicar el ajuste visual, después de subir `src/Service/Production/PrintingLabelRenderer.php` a `main`, ejecutar desde la raíz del proyecto:

```bash
cd /home/u692972268/domains/teramorphosis.com/printflow_app
git pull origin main
php bin/console cache:clear --env=prod --no-debug
php bin/console cache:warmup --env=prod --no-debug
```

No se ejecutan comandos de Composer ni migraciones para este ajuste de plantilla: Endroid ya quedó instalado en producción y se confirmó `QR_OK`. Si en una instalación futura nueva falta la dependencia, desplegar también `composer.json` y `composer.lock` e instalar con el ejecutable Composer disponible en Hostinger antes de limpiar la caché. Al terminar, abrir de nuevo la salida y mandar a imprimir con tamaño real, sin «Ajustar a página» si el diálogo del navegador ofrece escala.
