# Registro técnico — Ventas, rutas y creación de orden

> Registro cronológico de una entrega; no corresponde a la Fase 2 del roadmap de producto. Consulta [fases y criterios vigentes](../roadmap/fases-y-historias.md).

Fecha: 10 de octubre de 2026  
Estado: implementada en código y migrada en la base de desarrollo.

Esta fase convierte una cotización aceptada en una orden ejecutable sin perder el contexto que necesita Diseño. La orden sigue pudiendo crearse sin fecha, equipo o reserva de planificación; esos pendientes son visibles y no se rellenan con datos supuestos.

## Reglas implementadas

### Todas las partidas inician en Diseño

Una ruta publicada para un Producto solo puede publicarse cuando su primera operación es obligatoria, sin condición, y corresponde a `PRE-DISENO` dentro del área `PREPRESS`.

Al crear la orden se vuelve a comprobar la versión publicada. Una ruta histórica o inconsistente no genera operaciones ejecutables: la partida queda con el bloqueo explícito **«La ruta publicada no inicia con Diseño obligatorio»**. Así no se puede enviar una partida directamente a Impresión.

Diseño siempre recibe la partida, aun cuando el cliente adjuntó un archivo. En la siguiente fase Diseño podrá marcar ese original como viable para impresión o preparar una versión nueva; la captura de referencia seguirá siendo obligatoria antes de liberar Impresión.

### Snapshot de la orden

Al crear una orden desde una cotización aceptada se congelan:

- folio de cotización, aceptación, origen de la solicitud y fecha de entrega solicitada;
- cliente, contacto de entrega, modalidad (`pickup`, `shipping` o `undefined`) y domicilio de entrega seleccionado;
- Producto, cantidad, especificaciones comerciales y especificaciones de producción de cada partida;
- petición original depurada de cada partida (`request_details`), versión de ruta y sus pasos aplicados;
- referencia del archivo original: ruta, nombre original y estado `CLIENT_FILE_RECEIVED`, o la indicación explícita `DESIGN_PREPARATION_REQUIRED` cuando Diseño debe prepararlo o solicitarlo;
- código estable de partida: `<folio de orden>-P<renglón>`, por ejemplo `OS-2026-000001-P002`.

La referencia de archivo se conserva como dato histórico. Esta fase no mueve ni expone el adjunto público existente; la protección, descarga autorizada, hash y versionado se implementan en la fase de Diseño.

Si una partida se divide antes de iniciar producción, la partida original conserva su código estable y las nuevas subpartidas reciben un sufijo `-S02`, `-S03`, etc. También heredan los snapshots de petición y archivo.

### Contacto y entrega

Ventas puede elegir la modalidad de entrega en el formulario de cotización:

| Modalidad | Requisito al crear orden | Bloqueo visible |
| --- | --- | --- |
| Recoger en sucursal | Contacto válido | Ninguno por destino. |
| Envío a domicilio | Contacto válido; el domicilio puede completarse después. | Falta domicilio de entrega. |
| Por definir | Contacto válido. | La modalidad debe ser definida por Ventas. |

Para una cotización interna, la creación exige que Ventas haya elegido un contacto comercial. Para una solicitud pública se congela el contacto con el que se registró la solicitud. Una modalidad o domicilio pendiente no bloquea Diseño ni la planificación, pero queda persistido como `delivery_preparation_issue` y deberá impedir la liberación logística en la fase de Entrega.

## Datos existentes y migración

Archivos: [Version20261010170000.php](../../../migrations/Version20261010170000.php) y [Version20261010171000.php](../../../migrations/Version20261010171000.php)

La migración agrega a `service_orders` la modalidad, el snapshot de contacto y el bloqueo de preparación de entrega. A `service_order_items` agrega el código estable y los snapshots de petición y archivo.

La segunda migración detectó una ruta publicada de prueba en la que Diseño era condicional y opcional. Conservó la versión 1 como historia y publicó la versión 2, con `PRE-DISENO` / `PREPRESS` obligatorio como primer paso. Solo las órdenes futuras usan la nueva versión.

Las órdenes ya existentes no reciben contacto, archivo o solicitud ficticios. En la migración quedaron identificadas con:

```text
delivery_contact_snapshot.source = LEGACY_ORDER
delivery_contact_snapshot.status = HISTORICAL_DATA_NOT_AVAILABLE
fulfillment_method = undefined
delivery_preparation_issue = La modalidad de entrega sigue pendiente de definición por Ventas.
```

Sus partidas recibieron un código derivado de su folio y renglón. Los snapshots de petición y archivo permanecen nulos hasta que un responsable complete la información real; no se fabrican registros de Diseño ni eventos retroactivos.

La reversión se bloquea si ya existen snapshots creados por órdenes nuevas, para no eliminar evidencia de solicitud, archivo o contacto congelado.

## Presentación por perfil

El detalle operativo muestra los tres ejes independientes de la orden: planificación, producción y entrega. Muestra también modalidad, contacto y el bloqueo de entrega sin revelar importes a perfiles sin permiso comercial.

Cada partida enseña su código de rastreo y si Diseño tiene un original registrado o debe preparar/solicitar un archivo. En esta fase no ofrece enlaces al adjunto de la solicitud; el acceso protegido se habilitará junto con la cola de Diseño.

## Validación ejecutada

- Migración simulada con Doctrine: correcta, 8 sentencias SQL.
- Migraciones aplicadas en desarrollo como `DoctrineMigrations\Version20261010170000` y `DoctrineMigrations\Version20261010171000`.
- Estado posterior: 47 migraciones aplicadas, ninguna pendiente.
- Mapeo Doctrine: correcto.
- Verificación histórica: la orden existente quedó como `undefined` con bloqueo de entrega y sus partidas recibieron `OS-2026-000001-P001` y `OS-2026-000001-P002`; sus snapshots de petición/archivo permanecen vacíos.
- Verificación de rutas: `QA-LONA-13OZ-TEST` usa la versión 2 y su primer paso es `PRE-DISENO` en `PREPRESS`, condición `ALWAYS`, obligatorio.
- Pruebas focalizadas de ruta, orden y partida: 14 pruebas y 45 aserciones correctas.
- Suite completa: 105 pruebas y 313 aserciones correctas; 6 pruebas omitidas porque este equipo no tiene el controlador PDO SQLite.
- Sintaxis PHP, contenedor Symfony y plantillas Twig modificadas: correctos.

## Siguiente fase

La fase 3 implementará la bandeja de Diseño, el almacenamiento protegido de archivos, la decisión «original viable» o «archivo preparado», la captura de referencia obligatoria y las instrucciones que habilitan la transición a Impresión.
