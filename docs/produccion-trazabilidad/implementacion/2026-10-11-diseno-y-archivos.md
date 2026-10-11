# Registro técnico — Diseño y archivos protegidos

> Registro cronológico de una entrega; no corresponde a la Fase 3 del roadmap de producto. Consulta [fases y criterios vigentes](../roadmap/fases-y-historias.md).

Fecha: 11 de octubre de 2026  
Estado: implementada en código y migrada en la base de desarrollo.

Esta fase convierte Diseño en la puerta obligatoria entre la orden planificada y las etapas posteriores. El seguimiento sigue siendo por partida completa: una orden con varias partidas puede tener revisiones de Diseño independientes y cada una conserva su propio archivo autorizado, captura e historial.

## Flujo de Diseño

La bandeja **Operación / Diseño** muestra únicamente partidas pendientes, en revisión o bloqueadas. No muestra importes comerciales. El detalle enseña el folio, el código de rastreo, la solicitud, las especificaciones congeladas y los archivos autorizados para el perfil que tiene permiso de descarga.

La bandeja de Diseño permite que el perfil con `production.design.review` inicie y libere la revisión sin exigir que una diseñadora esté asignada como operadora. La regla general de asignación sigue aplicando a las demás operaciones productivas.

Una vez iniciada la revisión, Diseño puede elegir una de estas alternativas:

| Caso | Archivo autorizado para Impresión | Evidencia obligatoria |
| --- | --- | --- |
| El archivo del cliente es correcto | El mismo original del cliente, sin volver a subirlo. | Captura final y, opcionalmente, instrucciones. |
| El archivo requiere ajustes o no existe | Un arte preparado cargado por Diseño. | Arte preparado, captura final e instrucciones opcionales. |

La captura de referencia no es opcional en ningún caso. Al liberar una partida se genera una versión de liberación con el tipo de autorización, usuario, fecha, hash SHA-256 del arte y de la captura, e instrucciones. La versión anterior queda marcada como sustituida, sin eliminarse.

Una vez que una etapa posterior ha iniciado, el arte no puede cambiarse desde Diseño. El mensaje exige registrar el retrabajo autorizado, para no reemplazar silenciosamente la referencia que ya utilizó Producción.

## Bloqueo antes de Impresión

`ProductionExecutionManager` rechaza cualquier operación distinta de `PRE-DISENO` mientras la partida no esté en `READY_FOR_PRINT`. Además, la secuencia normal de operaciones exige completar Diseño antes de iniciar el siguiente paso. Por ello una partida no puede llegar a Impresión sin una liberación interna que contenga arte y captura.

La fase registra los eventos `design.review_started` y `design.artwork_authorized` en la línea de tiempo de trazabilidad y en la bitácora administrativa. La evidencia usa el mismo folio de orden y código de partida que se generó en la fase comercial.

## Archivos privados

Los adjuntos de nuevas solicitudes públicas ya no se escriben en `public/uploads/quotations`. Se validan por tipo MIME detectado con `finfo`, tamaño y contenido accesible, y se guardan en:

```text
var/storage/production/requests/<año>/<mes>/...
```

Los artes preparados y capturas se resguardan, respectivamente, en las carpetas privadas `artwork` y `references`. El nombre físico es aleatorio; el nombre original, MIME, tamaño, hash y procedencia quedan en `production_files`.

Las descargas se sirven por controlador y requieren `production.files.download`; no se construyen URL públicas. Para adjuntos históricos que todavía estén en `public/uploads/quotations`, Diseño puede descargarlos por la misma ruta protegida y, al autorizar el original, el sistema copia una versión validada al almacenamiento privado con procedencia `LEGACY_MIGRATION`. No se eliminan adjuntos históricos automáticamente.

## Permisos

La migración agrega el rol **Diseño** y el permiso `production.design.review`.

- **Diseño:** consulta órdenes operativas, cola de Diseño y archivos autorizados; puede iniciar y liberar partidas con el permiso `production.design.review`, sin asignación individual de operadora.
- **Producción y Administrador:** reciben `production.design.review` según la matriz de permisos. La autorización se valida en servidor y no depende de asignar a la diseñadora como operadora.
- **Operador y Calidad/Entregas:** pueden acceder a lo que les corresponda por sus permisos existentes, pero no liberan Diseño solo por consultar la partida.

## Datos y migración

Archivo: [Version20261011090000.php](../../../migrations/Version20261011090000.php)

La migración agrega `service_order_items.design_status`, la tabla `production_files`, la tabla `service_order_item_design_releases`, sus restricciones de versión y la seguridad del rol Diseño. Se aplicó como `DoctrineMigrations\Version20261011090000`; la base de desarrollo quedó con 48 migraciones ejecutadas y ninguna pendiente.

No se inventan liberaciones para órdenes antiguas. Una partida histórica sin snapshot de archivo deberá ser preparada por Diseño. Si sí conserva un adjunto histórico público, este se protege al momento de que Diseño lo use y lo autorice.

## Validación ejecutada

- Sintaxis PHP de servicios, controlador, migración y pruebas: correcta.
- Contenedor Symfony: correcto.
- 106 plantillas Twig: correctas.
- Mapeo Doctrine: correcto; el esquema no produjo SQL pendiente en `doctrine:schema:update --dump-sql`.
- Migración aplicada en desarrollo y permisos sincronizados con `app:security:bootstrap`.
- Suite completa: 110 pruebas, 326 aserciones correctas; 6 pruebas omitidas porque este equipo no cuenta con PDO SQLite.

## Nota de vigencia

La secuencia de ejecución evolucionó después de este registro. Consulta el [roadmap vigente](../roadmap/fases-y-historias.md) y la [guía operativa](../operacion/guia-operativa-planificacion-a-entrega.md) para el flujo actual y sus pendientes.
