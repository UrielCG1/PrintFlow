# Fase 1 — Contratos y núcleo de trazabilidad

Fecha: 10 de octubre de 2026  
Estado: implementada en código y migrada en la base de desarrollo.

Esta fase establece las reglas persistentes que usarán Diseño, Impresión, Acabados, Calidad/Entregas y Entrega. No habilita todavía pantallas ni acciones de operación para el taller; esas se incorporan cuando cada etapa tenga sus evidencias y validaciones específicas.

## Entregado

### Estados independientes de la orden

`service_orders.status` conserva la planificación actual:

| Eje | Estados | Uso |
| --- | --- | --- |
| Planificación | `PENDING_PLANNING`, `PLANNED` | Define si la orden puede iniciar operaciones. |
| Producción | `WAITING_DESIGN`, `IN_PRODUCTION`, `WAITING_QUALITY`, `QUALITY_REWORK`, `PRODUCTION_COMPLETED`, `CANCELLED` | Resume el avance productivo y la liberación de Calidad. |
| Entrega | `NOT_READY`, `READY_FOR_PICKUP`, `READY_FOR_SHIPMENT`, `SHIPPED`, `DELIVERED` | Distingue disponibilidad, envío y entrega confirmada. |

La entidad `ServiceOrder` rechaza secuencias inválidas: no puede iniciar producción sin planificación, liberar producción sin pasar por Calidad, ni entregar sin disponibilidad de sucursal o envío registrado.

### Intentos y concurrencia

Se agregó `production_operation_executions`. Un intento pertenece a una operación planificada y conserva:

- operador asignado al iniciar, creador, número de intento y, si existe, intento origen de retrabajo;
- estados `IN_PROGRESS`, `PAUSED`, `COMPLETED` y `CANCELLED`;
- motivo de pausa, nota de cierre e instantes UTC;
- versión de estado y la restricción de un solo intento abierto por operación.

`ProductionExecutionManager` bloquea en orden `orden → operación planificada → intento`, verifica la versión esperada de la orden, exige que el actor sea el operador asignado y obliga a terminar la operación anterior de la misma partida. `ProductionExecutionCommand` exige un `commandId` de 16 a 64 caracteres; el mismo comando se responde de forma idempotente mediante su evento, sin duplicar la ejecución.

Al terminar todas las operaciones activas de todas las partidas, la orden pasa a `WAITING_QUALITY`. La posterior devolución, autorización de retrabajo, revisión de conformidad y liberación se implementarán en la fase de Calidad, porque requieren la evidencia y reglas de cada partida.

### Eventos operativos

`service_order_traceability_events` registra una línea de tiempo inmutable con orden, partida, plan, intento, actor, tipo de evento, estados antes/después, motivo, contexto, correlación, comando e instante UTC.

La conversión de una cotización aceptada ahora genera `production.order_initialized` para órdenes nuevas. La migración no genera eventos para órdenes existentes: la única orden abierta detectada durante la fase 0 conserva su situación real y queda en `WAITING_DESIGN` hasta que se planifique.

`AuditLogger` se mantiene para la bitácora administrativa. Los eventos nuevos sirven al historial operativo por orden, partida y operación.

### Seguridad

Se agregó el rol de sistema `ROLE_QUALITY_DELIVERY` (**Calidad/Entregas**) y se definieron permisos granulares:

| Perfil | Facultades de esta fase |
| --- | --- |
| Administrador | Todas las nuevas facultades productivas, de Calidad y Entrega. |
| Jefe de Producción (`ROLE_PRODUCTION`) | Consultar, planificar, asignar, ejecutar, aprobar sustituciones y retrabajos, y gestionar archivos/etiquetas operativas. |
| Operador (`ROLE_OPERATOR`) | Consultar y ejecutar operaciones asignadas; cargar/descargar archivos operativos e imprimir etiquetas. La asignación se valida además en el servicio. |
| Calidad/Entregas | Consultar información operativa, revisar/devolver/liberar Calidad y registrar envío o entrega. |
| Ventas | Conserva los permisos comerciales existentes; no recibe facultades productivas nuevas. |

Los permisos introducidos son `production.*`, `quality.*` y `delivery.*`; la migración asigna los permisos iniciales y el comando `app:security:bootstrap` queda sincronizado para instalaciones nuevas.

## Migración aplicada

Archivo: [Version20261010160000.php](../migrations/Version20261010160000.php)

La migración es aditiva. Añade las columnas e índices de estado a `service_orders`, las tablas de intentos/eventos y los permisos/rol. La simulación de Doctrine reportó 11 sentencias y finalizó correctamente con `--dry-run`; después se aplicó correctamente como `DoctrineMigrations\Version20261010160000`.

La única orden existente quedó con sus valores iniciales:

```text
production_status = WAITING_DESIGN
delivery_status   = NOT_READY
traceability_version = 1
```

No dispara correos, no cambia inventario y no crea eventos retroactivos.

## Validación ejecutada

- Sintaxis PHP de los archivos modificados: correcta.
- Contenedor Symfony: correcto.
- Mapeo de Doctrine: correcto.
- Simulación y aplicación de migración Doctrine: correctas; 45 migraciones aplicadas y ninguna pendiente.
- Verificación en base de desarrollo: 1 orden en `WAITING_DESIGN` / `NOT_READY`, versión de trazabilidad 1; rol Calidad/Entregas y sus 8 permisos iniciales presentes.
- Pruebas unitarias: 94 pruebas y 299 aserciones correctas.
- Suite completa: 100 pruebas y 299 aserciones correctas; 6 pruebas se omiten porque este equipo no tiene el controlador PDO SQLite.
- Plantillas Twig: 104 archivos válidos.

## Siguiente fase

La fase 2 completará la creación de la orden con snapshot técnico, contacto y modalidad de entrega, archivos de origen por partida y rutas publicadas que inicien obligatoriamente en Diseño. Después se habilitará la cola y las evidencias propias de Diseño.
