# PrintFlow — piloto y salida operativa de inventario

**Estado:** procedimiento operativo para ensayar el módulo de inventario en el servidor `dev`; todavía no existe producción. El repositorio contiene migraciones hasta `Version20261011320000` y los flujos de presentaciones, recepción y QR. La salida de PuTTY del 13 de octubre de 2026 (`0df50a8`, 62/62 migraciones hasta `Version20261011230000`) es histórica y no certifica el estado actual. Antes del ensayo hay que registrar de nuevo el SHA desplegado, las migraciones disponibles y ejecutadas, el diagnóstico del catálogo y el nombre de la base. Preservar `FETCH_HEAD` y `public/uploads/` si siguen presentes como archivos no rastreados.

Esta guía completa la [estrategia del módulo de inventario](estrategia-modulo-inventario.md) con las actividades que requieren personas, material físico, dispositivos y el ambiente de destino. El [runbook general de despliegue](produccion-trazabilidad/operacion/validacion-y-despliegue-hostinger.md) conserva la secuencia técnica para publicar el código y aplicar migraciones.

## 1. Alcance del piloto

El primer piloto debe incluir un grupo pequeño pero representativo de variantes: una unidad por rollo o envase, una presentación contable, un material que pueda dejar sobrante y, cuando exista una regla aprobada, un sustituto. No limita el alcance futuro del catálogo: sirve para confirmar unidades, conversiones, etiquetas y hábitos de captura antes de activar el resto de la bodega.

El archivo del cliente enumera productos y maquinaria, pero no contiene los saldos ni unidades físicas necesarios para el corte. Usar los [tres casos sintéticos de ensayo](datos-prueba-piloto-inventario.md) para verificar el sistema en `dev`, separados de los datos reales.

Para un material nuevo, primero crea el concepto general en **Materiales** y después define su presentación en **Inventario → Nueva presentación**. Indica el objeto que llevará el QR, las unidades reales de compra, saldo y consumo, y sus equivalencias; para rollos con saldo lineal y consumo por área usa **Configurar anchos**. Finalmente registra cada unidad física en **Recibir material**. No uses SQL manual para crear existencias.

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

1. Verificar que el servidor tenga el commit objetivo y revisar `git rev-parse HEAD` y `git status --short`. Confirmar que ese checkout incluya `Version20261011320000` antes de repetir `migrations:status`. Un mensaje de «Already at the latest version» se refiere únicamente a las migraciones que existen en el checkout desplegado.
2. Ejecutar `doctrine:migrations:status`, `doctrine:migrations:list` y `doctrine:migrations:migrate --dry-run`. Revisar cada sentencia contra las migraciones del commit; no usar `schema:update --force`.
3. Aplicar las migraciones únicamente durante la ventana aprobada y comprobar de nuevo que no quedan versiones pendientes.
4. Ejecutar la revisión de catálogo, que es de solo lectura:

   ```sh
   php bin/console app:inventory:readiness --env=prod --no-debug
   ```

   Corregir o documentar cada observación. Cuando se requiera que no haya ningún pendiente antes del corte, usar `--strict`; el comando devuelve código distinto de cero si todavía hay hallazgos.
   Ejecutar también la [consulta de auditoría del piloto](consulta-inventario-piloto.sql) sobre la base confirmada. Comparar proyección, saldos QR y apartados; las consultas de anomalías deben devolver cero filas antes de formalizar el corte. La consulta no reemplaza el conteo físico.
5. Verificar que Administración puede abrir Inventario, configurar mínimos, atender faltantes, imprimir etiquetas, aprobar conteos y operar compras. Verificar que Impresión puede recibir material, leer QR, capturar conteos y consumos, pero no aprobar sustituciones, autorizaciones parciales, mínimos ni ajustes administrativos.
6. En el servidor `dev`, ejecutar `composer check-platform-reqs --no-dev` y comprobar que la biblioteca QR esté instalada con `composer show endroid/qr-code --no-ansi`. Si falta, instalar dependencias con Composer antes del ensayo; aplicar migraciones por sí solo no instala bibliotecas PHP.
7. Confirmar HTTPS en el dispositivo móvil, lectura continua del lector USB, contraste del QR impreso y que la etiqueta incorpora el logo de OOXCORP. En **Inventario físico → Consultar unidad por QR**, usar la cámara integrada o lector USB con una etiqueta recién impresa y otra reimpresa; la consulta debe mostrar la unidad correcta sin crear conteos ni movimientos.

Registrar el SHA desplegado, fecha, responsable y resultado de cada paso. El piloto no inicia si una migración, un permiso o una lectura QR falla.

### Registro mínimo de evidencia

Completar esta tabla para `dev`. Cuando se prepare producción, abrir una copia nueva y repetir todas las comprobaciones. No incluir contraseñas ni tokens QR completos en documentos compartidos.

| Comprobación | Resultado / referencia de evidencia | Responsable y fecha |
| --- | --- | --- |
| Ambiente, base y SHA desplegado | Base `u692972268_printflow_dev`; `git pull --ff-only origin main` avanzó de `2d87d8f` a `5af994e` y `git rev-parse --short HEAD` confirmó `5af994e`. | Salida de PuTTY compartida el 14/10/2026 |
| Migraciones del código objetivo | 71 ejecutadas / 71 disponibles, última `Version20261011320000`, verificadas antes del `git pull`. `5af994e` no añade migraciones; falta repetir `migrations:status` en el checkout actualizado. | Salida de PuTTY compartida el 14/10/2026 |
| Revisión de catálogo y unidad real de cada variante piloto | El último diagnóstico compartido, anterior al alta `QA-`, mostró 3 variantes activas controladas y 0 sin observaciones: `COLD_LAMINATE-ANCHO-1P5200M` y `LONA-FRONT-13OZ-ANCHO-1P5200M` requieren política de mínimos; `LONA-FRONT-13OZ-DEFAULT` conserva presentación legada `PZA` y no se usa para cargar metros. La etiqueta posterior acredita que `QA-LONA-13OZ-ANCHO-1P5000M` ya fue configurada; falta repetir el diagnóstico. | Diagnóstico y captura compartidos el 14/10/2026 |
| Proyección = suma QR; apartados = suma pendiente | Pendiente | Pendiente |
| Conteo físico firmado por unidad | La captura de 10 ML para `IMU-4ECB6E2978E4` fue solo una prueba del lector, según confirmó el usuario. Sigue pendiente que Administración la rechace con el motivo «Lectura de prueba; sin conteo físico». No constituye un conteo físico firmado. | Usuario, 15/10/2026 |
| Administración e Impresión: permisos y accesos | Pendiente | Pendiente |
| Dependencia QR y requisitos PHP en `dev` | `composer show endroid/qr-code --no-ansi` confirmó Endroid QR Code 5.1.0 instalado. Falta `composer check-platform-reqs --no-dev` y comprobar la generación y lectura reales de una etiqueta. | Salida de PuTTY compartida el 14/10/2026 |
| Etiqueta impresa y lectura con dispositivos reales | Vista de etiqueta generada para `REC-20261015-46E1A69A`, unidad `IMU-4ECB6E2978E4`; muestra material `QA-LONA-13OZ`, ancho 1.50 m, saldo inicial 10 ML y ubicación `QA-RACK-01`. El usuario confirmó el 15/10/2026 que la cámara integrada funciona en `dev`; falta registrar si se probó la etiqueta física/reimpresa, el saldo consultado y la hora corregida. | Captura compartida el 14/10/2026 y confirmación verbal del usuario el 15/10/2026 |
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

Para escanear y consumir en Impresión, la orden de prueba debe tener una operación de Impresión planificada, máquina compatible, liberación vigente de Diseño, **Material previsto** en la pantalla de esa operación y un apartado actualizado. Si el material se configura antes de planificar la orden, el apartado se calcula al planificarla; si se configura después, se recalcula al guardar. Documentar esos preparativos junto al folio; un rechazo por faltar alguno de ellos no demuestra un fallo del QR.

| Recorrido | Pasos | Resultado esperado |
| --- | --- | --- |
| Recepción y etiqueta | Recibir una unidad, confirmar saldo y reimprimir su etiqueta. | Una sola entrada física, QR único, saldo visible y reimpresión sin duplicar stock. |
| Orden con cobertura | Crear/aceptar orden con requisito configurado, escanear unidad y completar consumo. | Compromiso separado del físico; QR correcto habilita la operación y el consumo reduce una sola vez el saldo. |
| Sobrante | Consumir solo parte de una unidad y separar un retazo recuperable. | La unidad abierta conserva su QR y el retazo tiene QR propio con procedencia, sin crear existencia adicional. |
| Faltante | Aceptar una orden, registrar la cantidad en **Impresión → operación → Material previsto** y planificarla, o guardar la previsión si ya estaba planificada; atender el aviso administrativo. | La orden queda visible con faltante cuando la demanda está cuantificada; Administración puede autorizar producción parcial, elegir sustituto viable o ponerla en espera de compra. |
| Compra y recepción parcial | Crear solicitud, registrar una recepción parcial y después la restante. | Solicitar no aumenta stock; cada recepción real crea unidades QR y solo resuelve la espera cuando cubre la necesidad vigente. |
| Conteo cíclico | Escanear una unidad solo cuando se vaya a comprobar físicamente su saldo; capturar lo medido y enviar a revisión administrativa. | La captura queda pendiente hasta que Administración confirma o rechaza. Solo una diferencia aprobada cambia existencias; un movimiento posterior obliga a reconteo. |
| Condición de bodega | Bloquear, liberar o retirar una unidad por daño, caducidad o devolución según corresponda. | El saldo físico y la condición quedan trazables; el QR bloqueado no puede usarse en Impresión. |
| Cambio o cancelación de orden | Modificar, dividir, cancelar o retrabajar una orden piloto. | Se recalculan compromisos sin liberar consumo ya realizado ni duplicar necesidades. |

Repetir la validación QR con lector USB y cámara si ambos se usarán. Probar también una etiqueta dañada y una unidad de otra variante: el sistema debe mostrar una causa clara y no iniciar ni descontar material.

**Consultar unidad por QR** es la vía para identificar material, presentación, saldo y ubicación sin alterar nada. **Conteo cíclico** es una auditoría física: siempre requiere revisión administrativa, incluso con diferencia cero. Si se envió un conteo solo para probar el lector y no se midió la unidad, rechazarlo con ese motivo; no confirmarlo como inventario físico verificado.

### Registro de los tres casos `QA-`

Anotar los códigos visibles `IMU-` de las unidades, sin copiar el token interno del QR. Cada caso se contrasta con los valores de [datos de ensayo](datos-prueba-piloto-inventario.md); una diferencia se registra como incidencia antes de continuar con el siguiente.

| Caso | Folio de orden | Unidades `IMU-` y referencia de recepción | Saldo, apartado y resultado de escaneo observados | Estado, responsable y fecha |
| --- | --- | --- | --- | --- |
| Rollo: 10 ML → 6 M2 consumidos; sobrante separado pendiente | `OS-2026-000013`, partida 1 | `IMU-4ECB6E2978E4`; recepción `REC-20261015-46E1A69A` | Etiqueta de la unidad generada con 10 ML iniciales y ancho 1.50 m. Cámara integrada y validación de máquina/material funcionales. Material previsto sintético: 6 M2, equivalentes a 4 ML apartados. Tras cerrar Impresión, el usuario observó **6 ML** en la unidad; en Cobertura: **4 ML** de necesidad, **0 ML** apartados, **4 ML** consumidos, **0 ML** faltantes y estado **Conciliado**. No se reportó un QR hijo; la división de sobrante y la reimpresión siguen pendientes de validar. Confirmar también el rechazo del conteo hecho solo para probar el lector. | Consumo y conciliación informados el 17/10/2026 (Ciudad de México) |
| Faltante de 6 M2 y sustituto autorizado | Pendiente | Pendiente | Pendiente | Pendiente |
| Paquetes: espera de 100 HOJA y recepciones 50 + 50 | Pendiente | Pendiente | Pendiente | Pendiente |

El 17/10/2026 el usuario informó que pudo cerrar el primer trabajo de Impresión con el formulario anterior, que pedía el total manualmente. El saldo y el apartado observados concilian. La propuesta automática del paso 5 aplica a nuevos cierres después de desplegar el ajuste; el primer cierre no la valida.

### Siguiente recorrido: rollo `QA-LONA-13OZ`

Antes de continuar el ensayo, en **Inventario → Conteo cíclico → Pendientes de revisión**, localizar `IMU-4ECB6E2978E4` y pulsar **Rechazar** con el motivo «Lectura de prueba; sin conteo físico». El usuario confirmó que no midió el rollo. Rechazar este conteo no modifica la recepción de 10 ML.

1. Consultar el QR en **Inventario físico → Consultar unidad por QR** y anotar el saldo de la unidad (**10 ML**). Reimprimir la etiqueta de la misma unidad y comprobar que el saldo y el código `IMU-` permanecen iguales. Una reimpresión no crea otra recepción.
2. Crear una orden de ensayo identificada `QA-` desde una cotización de prueba, con una operación de Impresión en su ruta. Abrir **Impresión** en el menú lateral (`/admin/impresion`), buscar el folio, pulsar **Abrir trabajo** y, dentro de **Requerimientos de producción**, desplegar **Ajustar requerimientos**. Seleccionar la presentación del rollo de 1.50 m, una máquina compatible e introducir **6 M2** como cantidad prevista. Guardar y planificar la orden si todavía no estaba planificada. Debe existir liberación vigente de Diseño antes de validar y empezar Impresión. Si no aparece el folio en la cola, revisar en la orden que la ruta aplicada incluya una operación de Impresión. Si el control **Ajustar requerimientos** no aparece dentro del trabajo, comprobar que la cuenta tenga permiso de asignación y que esa operación no haya empezado ni terminado.
3. En la orden, revisar **Cobertura de materiales**: necesidad y apartado pendiente de **4 ML**, faltante **0 ML**; estos valores ya fueron informados para `OS-2026-000013`. En **Inventario físico**, el saldo de la unidad debe seguir en **10 ML**; por tanto, el disponible calculado es **6 ML**. Los **6 M2 de material previsto** sobre **1 M2 a producir** son un dato sintético aprobado para este ensayo; no usar esta proporción para planear trabajos reales. Si aparece otro saldo, detener el recorrido y revisar variante, ancho y unidad de consumo antes de escanear en Impresión.
4. Imprimir la etiqueta QR de la máquina desde **Equipos → Imprimir etiqueta** o desde el enlace junto a **Máquina asignada** en el trabajo de Impresión, una vez desplegada esa función; el QR contiene el código de equipo que espera el escáner. Escanear la máquina y el QR de `IMU-4ECB6E2978E4` con la cámara integrada y pulsar **Validar escaneos**. Confirmar que acepta el material previsto. Este paso no debe descontar el físico.
5. Iniciar y cerrar la impresión de ensayo. La pantalla propone automáticamente **6 M2** desde **Material previsto**, equivalentes a **4 ML** del rollo de 1.50 m; confirmar que el trabajo realmente usó ese total y capturar **Merma: 0 M2**. Si el total real fue distinto, abrir **Registrar un consumo distinto al previsto**, capturar el total medido y explicar el cambio. La merma forma parte del total y no se suma dos veces. Si se separa físicamente un remanente reutilizable, registrar **1.5 ML** en **Separar sobrante reutilizable** durante el cierre. El resultado esperado es 4.5 ML en el QR original, 1.5 ML en el QR hijo, 6 ML físicos en total y ningún compromiso pendiente de esa operación. Si no se separa un remanente real, cerrar sin sobrante y esperar 6 ML en el QR original.

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
