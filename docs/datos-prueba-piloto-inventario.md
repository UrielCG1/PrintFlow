# Datos de ensayo del inventario en `dev`

**Origen revisado:** `base de datos.xlsx`, proporcionado por el cliente el 13 de octubre de 2026. Estos ejemplos son **sintéticos** y no representan existencias, precios, medidas ni equivalencias aprobadas por el cliente. No cargarlos en producción ni mezclarlos con un conteo físico oficial.

## Qué aporta el archivo

El libro enumera cinco máquinas, cuatro reglas generales de costeo y 18 nombres de productos: cuatro lonas y catorce viniles. La hoja de proveedores no contiene registros y la de clientes solo tiene encabezados. Algunos productos muestran cifras en la columna `ANCHO`, pero el archivo no indica su unidad ni la longitud de cada rollo. Tampoco contiene cantidades disponibles, unidades de compra/consumo, costos, lotes, ubicaciones, mínimos, necesidades por orden ni reglas técnicas de sustitución.

Por ello, **no convertir** cifras como `61`, `80`, `135` o `160` a centímetros o pulgadas por inferencia. No utilizar la variante legada `LONA-FRONT-13OZ-DEFAULT` para cargar metros: conserva `PZA` y una marca de migración que impide recibirla hasta configurar una presentación real.

Los códigos `QA-` siguientes son exclusivos del ensayo. Usa una categoría activa del catálogo o crea una categoría `QA-PILOTO` si no hay una adecuada. Configura las unidades y factores indicados desde la interfaz; son **supuestos de prueba**, no equivalencias aprobadas para los materiales del cliente. Cada unidad física recibida tiene un QR distinto. Comprueba primero que no existan registros `QA-` de un intento anterior para no duplicar saldos.

## Caso 1 — recepción, reserva, consumo y sobrante

Material de prueba inspirado en «LONA 13 OZ BTE» del libro, sin usar el código real del cliente.

| Dato de ensayo | Valor |
| --- | --- |
| Material / presentación | `QA-LONA-13OZ`; crear en **Configurar anchos** un rollo de **1.50 m** de ancho. El sistema genera el código de presentación. |
| Alta en Materiales | Código `QA-LONA-13OZ`, nombre `Lona de ensayo QA`, categoría `QA-PILOTO` y unidad general `ML`. |
| Unidades y conversión | Objeto físico `ROLLO`; compra y saldo en `ML`, consumo en `M2`; **1 ML = 1.50 M2** por el ancho configurado. La longitud de cada rollo se mide al recibirlo. |
| Unidad física recibida | Un rollo de **10 ML**, QR propio, referencia `QA-ENT-001`. |
| Mínimos de prueba | Mínimo **2 ML**, punto de reabasto **3 ML** y cantidad sugerida **10 ML**. |
| Orden de ensayo | Crear una operación de Impresión y, en **Impresión → operación → Material previsto**, indicar **6 M2** de área y **4 ML** de longitud prevista para este caso de ancho completo; planificar la orden para generar el apartado. |
| Consumo confirmado | **6 M2**, equivalentes a **4 ML** descontados del QR. |
| Sobrante separado | **1.5 ML** con QR hijo y procedencia del rollo original. |

Resultado esperado: al recibir hay **10 ML** físicos y disponibles; al apartar **4 ML** siguen existiendo 10 ML físicos y quedan 6 ML disponibles. Al cerrar, capturar **4 ML reales alimentados**; el área de 6 M2 solo documenta Producción y no se convierte automáticamente. El físico queda en **6 ML** y el apartado pendiente en cero. Al separar 1.5 ML, el QR original conserva **4.5 ML** y el QR hijo **1.5 ML**; el total continúa en 6 ML. Reimprimir cualquiera de las etiquetas no aumenta el saldo.

## Caso 2 — faltante y sustituto autorizado

Dos variantes **ficticias** inspiradas en la familia de viniles brillantes. Su compatibilidad es un supuesto del ensayo, no una recomendación técnica para los viniles reales del cliente.

| Dato de ensayo | Valor |
| --- | --- |
| Requisito original | Material `QA-VINIL-BRILLANTE-A`, presentación `QA-VINIL-BRILLANTE-A-M2`, **6 M2**. |
| Alta en Materiales | Crear `QA-VINIL-BRILLANTE-A` y `QA-VINIL-BRILLANTE-B` en categoría `QA-PILOTO`, con unidad general `M2`. |
| Existencia del original | **0 M2**. |
| Sustituto disponible | Material `QA-VINIL-BRILLANTE-B`, presentación `QA-VINIL-BRILLANTE-B-M2`, una unidad QR de **8 M2**. |
| Configuración de presentaciones | Crear ambas en **Nueva presentación**, con objeto físico `PIEZA` y unidades de compra, saldo y consumo `M2` (factor 1:1 solo para estas muestras medidas). |
| Mínimos de prueba | Para ambas presentaciones: mínimo **1 M2**, punto de reabasto **2 M2** y cantidad sugerida **6 M2**. |
| Regla de ensayo | En **Sustituciones de material**, configurar **1 M2** sustituto por **1 M2** original, solo para estas variantes `QA-`. |
| Orden de ensayo | Crear/aceptar la orden con operación de Impresión; registrar **6 M2** de `A` en **Impresión → operación → Material previsto** y planificar la orden. Si ya estaba planificada, guardar la previsión recalcula el apartado. |
| Decisión | Administración autoriza usar el sustituto para esta orden. |

Resultado esperado: cuando queda registrada la necesidad de 6 M2 y se genera o recalcula el apartado, aparece un faltante de **6 M2** en Avisos de inventario del dashboard administrativo. Los administradores activos reciben un correo con enlace a esa fila de **Cobertura de materiales**; requiere que el servidor tenga configurado `MAILER_DSN` y remitente reales. En la cola y el trabajo de Impresión aparece **Autorización pendiente** y no se permite validar el QR ni iniciar hasta registrar la decisión. La aceptación comercial sola puede no aportar el metraje. Antes de autorizar, el QR `B` no satisface el requisito `A`. Tras la sustitución, la cobertura muestra **Sustituto autorizado** de `A` por `B`, se apartan 6 M2 de `B`, quedan 2 M2 disponibles de `B` y el trabajo de Impresión indica escanear el QR `B`. Registrar quién autorizó y por qué; no configurar esta regla para los materiales reales por similitud de nombre.

## Caso 3 — paquete, espera de compra y recepción parcial

Material contable ficticio que permite probar una presentación distinta del rollo y dos recepciones físicas parciales.

| Dato de ensayo | Valor |
| --- | --- |
| Material / presentación | `QA-PAPEL` / `QA-PAPEL-PAQ50`. Crear en **Nueva presentación** con objeto físico `PAQUETE`. |
| Alta en Materiales | Código `QA-PAPEL`, nombre `Papel de ensayo QA`, categoría `QA-PILOTO` y unidad general `HOJA`. |
| Unidades y conversión | Compra `PAQ`, saldo y consumo `HOJA`; **1 PAQ = 50 HOJA**. Registrar el contenido real de cada paquete en `HOJA`. |
| Mínimos de prueba | Mínimo **20 HOJA**, punto de reabasto **30 HOJA** y cantidad sugerida **100 HOJA**. |
| Existencia inicial | **0 HOJA**. |
| Orden de ensayo | Crear/aceptar la orden con operación de Impresión, registrar **100 HOJA** en **Impresión → operación → Material previsto**, planificarla para generar el faltante y ponerla en espera de compra. |
| Solicitud interna | **100 HOJA**, referencia de prueba `QA-COMPRA-001`. |
| Primera recepción | Un paquete de **50 HOJA**, QR individual y referencia `QA-ENT-002`. |
| Segunda recepción | Otro paquete de **50 HOJA**, otro QR y referencia `QA-ENT-003`. |

Resultado esperado: crear o seguir la compra no aumenta el físico. La primera recepción deja **50 HOJA** físicas apartadas y faltan **50 HOJA**; la orden continúa en espera. La segunda deja **100 HOJA** físicas apartadas, faltante cero y permite revisar la salida de espera sin iniciar producción automáticamente. Cada paquete conserva su QR y movimiento propios.

## Preparación y cierre del ensayo

1. Confirmar en `dev` el SHA desplegado y que las migraciones del checkout están completas antes de cargar datos. No asumirlo por la salida antigua del 13 de octubre.
2. Crear cada material `QA-` en **Materiales** y luego su presentación en **Inventario**, sin sobrescribir `LONA-FRONT-13OZ-DEFAULT`. Configurar mínimos por presentación. Crear las órdenes desde cotizaciones y un cliente de ensayo identificados como `QA-`, sin involucrar pedidos reales. Para estos recorridos de impresión, capturar la necesidad en **Impresión → operación → Material previsto**: si la orden ya está planificada, el apartado se recalcula al guardar; si no, planificarla después. No insertar saldos directamente en SQL.
3. Usar cuentas de Administración e Impresión y conservar folios, códigos de unidad, resultado de lectura QR y capturas de saldos. No compartir tokens QR completos ni datos de clientes reales en el reporte.
4. Comparar los resultados con la [auditoría de solo lectura](consulta-inventario-piloto.sql) y con la [guía de salida operativa](operacion-inventario-piloto-y-salida.md). Conservar los datos `QA-` identificados como ensayo; no borrarlos mediante SQL ni trasladarlos a una base productiva.
