# Etiquetas QR de salida impresa y rollos de material

**Fecha:** 11 de octubre de 2026
**Estado:** dependencia y generación QR verificadas en local; instalación en Hostinger pendiente.

## Etiqueta que genera hoy PrintFlow

Al cerrar una ejecución de Impresión, PrintFlow registra una `ProductionPrintedOutput` vinculada a la partida, al intento de impresión y a la liberación de Diseño. La etiqueta de salida usa un código `PF-…` y un QR cuyo contenido identifica la orden, la partida y la salida vigente. La ruta para generar o reimprimir la etiqueta no vuelve a cerrar la impresión ni registra de nuevo el consumo.

Antes de agregar la dependencia QR, el cierre de impresión ya quedaba guardado y la etiqueta permanecía pendiente. Después de instalar la dependencia, se debe reintentar desde **Generar e imprimir etiqueta QR** de esa salida pendiente; no se debe volver a completar la impresión.

## Dependencia y verificación local

- Se agregó `endroid/qr-code` `^5.1`; `composer.lock` fija la versión `5.1.0` y sus dependencias.
- La versión 5.1.0 admite PHP `^8.1`, por lo que es compatible con el PHP 8.2 usado localmente y requerido por el proyecto. Se usa `SvgWriter` para insertar el QR directamente en la etiqueta HTML.
- La salida SVG se genera sin declaración XML para incrustarla correctamente en HTML.
- Verificaciones locales: `composer validate`, `composer check-platform-reqs --no-dev`, lint de `PrintingLabelRenderer.php` y prueba directa que generó un SVG de QR válido. No se necesita migración de base de datos.
- Falta ejecutar `composer install --no-dev` en Hostinger y generar una etiqueta pendiente en el sitio para completar la comprobación productiva.

## Etiqueta de rollo e inventario

La propuesta visual compartida —marca, proveedor o línea, nombre/código del material, fecha, descripción, QR y número legible del rollo— corresponde a identificar **un rollo físico de materia prima**. Debe conservarse como referencia visual para una futura etiqueta de rollo. El identificador principal visible debe combinar la identidad del material con un consecutivo único del rollo; el QR debe representar ese mismo rollo, no solo la variante genérica del catálogo.

La etiqueta QR implementada ahora corresponde a una **salida impresa de una partida**. No debe presentarse como etiqueta de rollo de inventario: actualmente el escaneo valida una variante consumible, pero no identifica un rollo ni lleva existencias o movimientos por rollo. El control de inventario sigue pospuesto según el FSD. Cuando se autorice ese alcance, primero se requiere modelar la recepción e identidad única de rollos; luego se adapta la propuesta del cliente a esos datos y a su flujo de escaneo.

## Despliegue en Hostinger

Después de subir a `main` `composer.json`, `composer.lock` y `src/Service/Production/PrintingLabelRenderer.php`, ejecutar desde la raíz del proyecto:

```bash
cd /home/u692972268/domains/teramorphosis.com/printflow_app
php -v
composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
composer check-platform-reqs --no-dev
php bin/console cache:clear --env=prod --no-debug
php bin/console cache:warmup --env=prod --no-debug
php -r 'require "vendor/autoload.php"; echo class_exists(\Endroid\QrCode\Writer\SvgWriter::class) ? "QR_OK\n" : "QR_MISSING\n";'
```

El PHP de consola debe ser 8.2 o superior y Composer debe poder descargar los paquetes indicados en `composer.lock`. Si el comando `composer` no existe, localizar el ejecutable configurado en Hostinger y usarlo en su lugar; no ejecutar `composer require` en producción. Tras `QR_OK`, abrir de nuevo la salida pendiente e imprimir su etiqueta QR. No se ejecuta SQL ni una migración para esta dependencia.
