# PrintFlow — piloto y salida operativa de inventario

**Estado:** procedimiento operativo para liberar el módulo de inventario de forma controlada. No acredita que una base ni una bodega ya hayan sido migradas.

Esta guía completa la [estrategia del módulo de inventario](estrategia-modulo-inventario.md) con las actividades que requieren personas, material físico, dispositivos y el ambiente de destino. El [runbook general de despliegue](produccion-trazabilidad/operacion/validacion-y-despliegue-hostinger.md) conserva la secuencia técnica para publicar el código y aplicar migraciones.

## 1. Alcance del piloto

El primer piloto debe incluir un grupo pequeño pero representativo de variantes: una unidad por rollo o envase, una presentación contable, un material que pueda dejar sobrante y, cuando exista una regla aprobada, un sustituto. No limita el alcance futuro del catálogo: sirve para confirmar unidades, conversiones, etiquetas y hábitos de captura antes de activar el resto de la bodega.

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

Realizar estas comprobaciones primero en una copia aislada de la base y repetir las lecturas en el destino antes de abrir el piloto.

1. Publicar un commit identificado y revisar que el árbol de trabajo esté limpio.
2. Ejecutar `doctrine:migrations:status`, `doctrine:migrations:list` y `doctrine:migrations:migrate --dry-run`. Revisar cada sentencia contra las migraciones del commit; no usar `schema:update --force`.
3. Aplicar las migraciones únicamente durante la ventana aprobada y comprobar de nuevo que no quedan versiones pendientes.
4. Ejecutar la revisión de catálogo, que es de solo lectura:

   ```sh
   php bin/console app:inventory:readiness --env=prod --no-debug
   ```

   Corregir o documentar cada observación. Cuando se requiera que no haya ningún pendiente antes del corte, usar `--strict`; el comando devuelve código distinto de cero si todavía hay hallazgos.
5. Verificar que Administración puede abrir Inventario, configurar mínimos, atender faltantes, imprimir etiquetas, aprobar conteos y operar compras. Verificar que Impresión puede recibir material, leer QR, capturar conteos y consumos, pero no aprobar sustituciones, autorizaciones parciales, mínimos ni ajustes administrativos.
6. Confirmar HTTPS en el dispositivo móvil, lectura continua del lector USB, contraste del QR impreso y que la etiqueta incorpora el logo de OOXCORP. Probar un QR desde una etiqueta recién impresa y otro reimpreso.

Registrar el SHA desplegado, fecha, responsable y resultado de cada paso. El piloto no inicia si una migración, un permiso o una lectura QR falla.

## 3. Corte e inventario inicial

1. Comunicar la ventana y el conjunto de variantes que entran al piloto.
2. Detener temporalmente las entradas, consumos y ajustes de esas variantes, o anotar cada movimiento ocurrido durante el conteo para conciliarlos antes de reabrir la operación.
3. Contar cada unidad física: código, QR, variante, saldo, unidad, lote/caducidad si aplica, ubicación y condición. Identificar por separado abierto, sobrante, dañado, caducado o en revisión.
4. Registrar el físico confirmado por el flujo de recepción o conteo inicial. Cada rollo, envase, paquete o retazo separado requiere su propia unidad QR; no agrupar saldos de objetos diferentes en una sola etiqueta.
5. Registrar las necesidades pendientes de las órdenes abiertas y generar o revisar sus compromisos después de confirmar el físico. No volver a descontar consumos que sucedieron antes del corte.
6. Revisar disponible, comprometido, bloqueado y faltante por variante. Cualquier diferencia queda como incidencia auditada; no se corrige editando directamente la base.

Antes de reabrir las operaciones, Administración firma el conteo y conserva la hoja o evidencia física asociada al reporte del sistema.

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
