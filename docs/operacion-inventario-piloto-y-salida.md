# PrintFlow — piloto y salida operativa de inventario

**Estado:** procedimiento operativo para ensayar el módulo de inventario en el servidor `dev`. La salida de PuTTY del 13 de octubre de 2026 identifica la base `u692972268_printflow_dev`, checkout `0df50a8` y `62/62` migraciones disponibles/ejecutadas, con última `Version20261011230000`. El código local ya contiene migraciones hasta `Version20261011320000`; por tanto falta publicar la versión nueva del código y aplicar las nueve versiones posteriores antes del piloto. `FETCH_HEAD` y `public/uploads/` aparecen como archivos no rastreados en el servidor: preservarlos y revisar el checkout antes de cualquier cambio de versión. Todavía no existe producción.

Esta guía completa la [estrategia del módulo de inventario](estrategia-modulo-inventario.md) con las actividades que requieren personas, material físico, dispositivos y el ambiente de destino. El [runbook general de despliegue](produccion-trazabilidad/operacion/validacion-y-despliegue-hostinger.md) conserva la secuencia técnica para publicar el código y aplicar migraciones.

## 1. Alcance del piloto

El primer piloto debe incluir un grupo pequeño pero representativo de variantes: una unidad por rollo o envase, una presentación contable, un material que pueda dejar sobrante y, cuando exista una regla aprobada, un sustituto. No limita el alcance futuro del catálogo: sirve para confirmar unidades, conversiones, etiquetas y hábitos de captura antes de activar el resto de la bodega.

El archivo del cliente enumera productos y maquinaria, pero no contiene los saldos ni unidades físicas necesarios para el corte. Usar los [tres casos sintéticos de ensayo](datos-prueba-piloto-inventario.md) para verificar el sistema en `dev`, separados de los datos reales.

Antes de cargarlos, completar el alta operativa de variantes nuevas: el formulario actual de Materiales registra el concepto general, mientras la recepción de inventario requiere una `MaterialVariant` existente. No usar SQL manual para simular un flujo que todavía debe existir en la interfaz.

Definir antes de iniciar:

| Dato | Responsable | Evidencia de cierre |
| --- | --- | --- |
| Variantes piloto, unidad de inventario y de consumo | Administración | Matriz de variantes aprobada; no se infieren metros, área o contenido desde el nombre. |
| Mínimo, punto de reorden y cantidad sugerida | Administración | Valores configurados por variante o decisión explícita de no aplicar. |
| Ubicación física y forma de identificar cada unidad | Bodega / Impresión | Estante o zona y etiqueta QR legible por unidad física. |
| Reglas de sustitución | Administración técnica | Compatibilidad, contexto y conversión aprobados; no se autorizan por similitud de nombre. |
| Personas y dispositivos | Administración | Una cuenta administradora, una de Impresión, lector USB y/o celular probados. |

No convertir el mínimo configurado en existencia inicial. Una variante sin conteo confirmado debe tratarse como **por contar**.

## 2. Prevuelo técnico y de datos

Realizar estas comprobaciones en la base `dev` antes de abrir el piloto. Cuando exista producción, repetirlas sobre su base y su código publicados; el resultado de `dev` no certificará ese ambiente futuro. El valor `--env=prod` de Symfony puede usarse en un servidor de desarrollo optimizado: identifica la configuración de la aplicación, no el nombre ni el propósito de la base. Confirmar ambos con `php bin/console about --no-debug` y la primera consulta de la auditoría.

1. Publicar un commit identificado y revisar que el árbol de trabajo esté limpio.
   La salida recibida del servidor solo conoce `Version20261011230000`. Confirmar allí `git rev-parse HEAD` y `git status --short`; publicar un commit que incluya `Version20261011320000` antes de repetir `migrations:status`. Un mensaje de «Already at the latest version» se refiere únicamente a las migraciones que existen en ese checkout.
2. Ejecutar `doctrine:migrations:status`, `doctrine:migrations:list` y `doctrine:migrations:migrate --dry-run`. Revisar cada sentencia contra las migraciones del commit; no usar `schema:update --force`.
3. Aplicar las migraciones únicamente durante la ventana aprobada y comprobar de nuevo que no quedan versiones pendientes.
4. Ejecutar la revisión de catálogo, que es de solo lectura:

   ```sh
   php bin/console app:inventory:readiness --env=prod --no-debug
   ```

   Corregir o documentar cada observación. Cuando se requiera que no haya ningún pendiente antes del corte, usar `--strict`; el comando devuelve código distinto de cero si todavía hay hallazgos.
   Ejecutar también la [consulta de auditoría del piloto](consulta-inventario-piloto.sql) sobre la base confirmada. Comparar proyección, saldos QR y apartados; las consultas de anomalías deben devolver cero filas antes de formalizar el corte. La consulta no reemplaza el conteo físico.
5. Verificar que Administración puede abrir Inventario, configurar mínimos, atender faltantes, imprimir etiquetas, aprobar conteos y operar compras. Verificar que Impresión puede recibir material, leer QR, capturar conteos y consumos, pero no aprobar sustituciones, autorizaciones parciales, mínimos ni ajustes administrativos.
6. En el servidor `dev`, ejecutar `composer check-platform-reqs --no-dev` y comprobar que el paquete QR del `composer.lock` esté instalado: `php -r 'require "vendor/autoload.php"; echo class_exists("Endroid\\QrCode\\Writer\\SvgWriter") ? "QR OK\n" : "FALTA QR\n";'`. Si falta, instalar dependencias con Composer antes del ensayo; aplicar migraciones por sí solo no instala bibliotecas PHP.
7. Confirmar HTTPS en el dispositivo móvil, lectura continua del lector USB, contraste del QR impreso y que la etiqueta incorpora el logo de OOXCORP. Probar un QR desde una etiqueta recién impresa y otro reimpreso.

Registrar el SHA desplegado, fecha, responsable y resultado de cada paso. El piloto no inicia si una migración, un permiso o una lectura QR falla.

### Registro mínimo de evidencia

Completar esta tabla para `dev`. Cuando se prepare producción, abrir una copia nueva y repetir todas las comprobaciones. No incluir contraseñas ni tokens QR completos en documentos compartidos.

| Comprobación | Resultado / referencia de evidencia | Responsable y fecha |
| --- | --- | --- |
| Ambiente, base y SHA desplegado | `u692972268_printflow_dev`; falta SHA | Pendiente |
| Migraciones del código objetivo | `62/62` hasta `Version20261011230000` en servidor; faltan publicar/aplicar las nueve versiones hasta `Version20261011320000` | Pendiente |
| Revisión de catálogo y unidad real de cada variante piloto | 1 variante activa, 0 listas; `LONA-FRONT-13OZ-DEFAULT` usa presentación legada `PZA` por confirmar | Pendiente |
| Proyección = suma QR; apartados = suma pendiente | Pendiente | Pendiente |
| Conteo físico firmado por unidad | Pendiente | Pendiente |
| Administración e Impresión: permisos y accesos | Pendiente | Pendiente |
| Dependencia QR y requisitos PHP en `dev` | Pendiente | Pendiente |
| Etiqueta impresa y lectura con dispositivos reales | Pendiente | Pendiente |
| Recorridos de la sección 4 con folio y resultado | Pendiente | Pendiente |

## 3. Ensayo del conteo inicial en `dev`

El conteo en `dev` es un ensayo y no se declara inventario oficial. Se puede usar una muestra física identificada como piloto o datos artificiales controlados; registrar cuáles se usaron para no trasladar sus saldos a producción. El corte formal y la firma de existencias reales se repetirán cuando exista el ambiente de producción.

1. Comunicar la ventana y el conjunto de variantes que entran al piloto.
2. Detener temporalmente las entradas, consumos y ajustes de esas variantes, o anotar cada movimiento ocurrido durante el conteo para conciliarlos antes de reabrir la operación.
3. Contar cada unidad física: código, QR, variante, saldo, unidad, lote/caducidad si aplica, ubicación y condición. Identificar por separado abierto, sobrante, dañado, caducado o en revisión.
4. En **Inventario → Recibir material**, elegir **Ya estaba en bodega** y registrar cada unidad física confirmada. Cada rollo, envase, paquete o retazo separado requiere su propia unidad QR; no agrupar saldos de objetos diferentes en una sola etiqueta.
5. Registrar las necesidades pendientes de las órdenes abiertas y generar o revisar sus compromisos después de confirmar el físico. No volver a descontar consumos que sucedieron antes del corte.
6. Revisar disponible, comprometido, bloqueado y faltante por variante. Cualquier diferencia queda como incidencia auditada; no se corrige editando directamente la base.

Antes de continuar con los recorridos en `dev`, Administración revisa el conteo de ensayo y conserva la hoja o evidencia asociada al reporte del sistema.

## 4. Recorridos obligatorios del piloto

Ejecutar cada recorrido con datos de prueba controlados o con trabajo real autorizado. Conservar el identificador de orden, QR, usuario y resultado.

| Recorrido | Pasos | Resultado esperado |
| --- | --- | --- |
| Recepción y etiqueta | Recibir una unidad, confirmar saldo y reimprimir su etiqueta. | Una sola entrada física, QR único, saldo visible y reimpresión sin duplicar stock. |
| Orden con cobertura | Crear/aceptar orden con requisito configurado, escanear unidad y completar consumo. | Compromiso separado del físico; QR correcto habilita la operación y el consumo reduce una sola vez el saldo. |
| Sobrante | Consumir solo parte de una unidad y separar un retazo recuperable. | La unidad abierta conserva su QR y el retazo tiene QR propio con procedencia, sin crear existencia adicional. |
| Faltante | Aceptar una orden con saldo insuficiente y atender el aviso administrativo. | La orden queda visible con faltante; Administración puede autorizar producción parcial, elegir sustituto viable o ponerla en espera de compra. |
| Compra y recepción parcial | Crear solicitud, registrar una recepción parcial y después la restante. | Solicitar no aumenta stock; cada recepción real crea unidades QR y solo resuelve la espera cuando cubre la necesidad vigente. |
| Conteo cíclico | Escanear una unidad, capturar saldo observado y aprobar una diferencia con motivo. | Se conserva la lectura, usuario y movimiento de ajuste; un movimiento posterior obliga a reconteo. |
| Condición de bodega | Bloquear, liberar o retirar una unidad por daño, caducidad o devolución según corresponda. | El saldo físico y la condición quedan trazables; el QR bloqueado no puede usarse en Impresión. |
| Cambio o cancelación de orden | Modificar, dividir, cancelar o retrabajar una orden piloto. | Se recalculan compromisos sin liberar consumo ya realizado ni duplicar necesidades. |

Repetir la validación QR con lector USB y cámara si ambos se usarán. Probar también una etiqueta dañada y una unidad de otra variante: el sistema debe mostrar una causa clara y no iniciar ni descontar material.

## 5. Salida a operación habitual

Durante los primeros días, revisar al inicio y cierre de cada turno:

- panel de faltantes, mínimos, órdenes en espera de compra y solicitudes de compra en seguimiento;
- diferencias de conteo, merma y unidades bloqueadas;
- consumos pendientes de conciliación y compromisos de órdenes abiertas;
- etiquetas pendientes de reimpresión o QR que no se pudieron leer;
- trazabilidad de una muestra de recepción a consumo o sobrante.

Administración revisa los avisos y autorizaciones; Impresión registra los hechos físicos cuando ocurren. Las correcciones se hacen mediante los flujos de conteo, ajuste, condición o devolución para preservar el historial.

Ampliar el piloto al siguiente grupo de materiales únicamente cuando:

1. todas las variantes activadas tienen unidades y conversiones comprobadas;
2. el conteo inicial y sus incidencias están cerrados;
3. los recorridos obligatorios pasan con los dispositivos reales;
4. no existen compromisos sin explicación, saldos negativos ni QR duplicados;
5. los responsables conocen el tratamiento de faltantes, sobrantes, daño y caducidad.

## 6. Incidencias y recuperación

Si aparece una diferencia, QR ilegible, material equivocado, lectura tardía o error de despliegue, detener el recorrido afectado y registrar la evidencia. Mantener el material en condición bloqueada o en revisión cuando no sea seguro usarlo. Resolver mediante una corrección hacia adelante y una operación auditada; no borrar movimientos, no ejecutar migraciones hacia abajo automáticamente y no editar saldos directamente en SQL.

Una compra solicitada, una autorización parcial o un sustituto aprobado no sustituyen la verificación física. Si el problema afecta un material ya activo, puede suspenderse solo esa variante mientras continúan las demás que conservan sus saldos y trazabilidad correctos.
