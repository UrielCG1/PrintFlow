# PrintFlow — Fase 0: línea base y preparación de trazabilidad

**Fecha:** 10 de octubre de 2026  
**Estado:** completada en código local y base de desarrollo; las consultas de desarrollo requieren mantener activo el túnel SSH local.  
**Referencia funcional:** [Estrategia de implementación de trazabilidad](Estrategia-Implementacion-Trazabilidad-2026-10-10.md).

## Resultado

El código local está en una condición sana para iniciar el módulo: el contenedor de Symfony y las plantillas compilan, y las pruebas unitarias pasan. Además, el repositorio ya contiene una parte relevante de la planificación de producción: rutas versionadas por Producto, snapshots por partida, capacidades estructuradas, estimaciones y reservas de máquina.

La conexión directa a la base remota estaba restringida. Se habilitó una conexión remota en Hostinger para el host del túnel SSH y se validó la base mediante PuTTY en `127.0.0.1:3307`. Esta configuración no se persiste en `.env.local`: las consultas que dependan de ella requieren que la sesión de PuTTY permanezca abierta.

No se aplicaron migraciones, no se modificaron datos, no se enviaron correos y no se modificó código de aplicación durante este diagnóstico.

## Verificaciones realizadas

| Verificación | Resultado | Evidencia / implicación |
| --- | --- | --- |
| Estado de trabajo | Correcto | `git status --short` no reportó cambios antes de crear este documento; la rama actual es `main`. |
| Contenedor de Symfony | Correcto | `php bin/console lint:container --no-interaction` terminó correctamente. |
| Plantillas Twig | Correcto | `php bin/console lint:twig templates --no-interaction` validó las 104 plantillas. |
| Pruebas unitarias | Correcto | `php bin/phpunit tests/Unit --no-coverage`: 86 pruebas, 278 aserciones, sin errores. |
| Suite completa | Parcial | `php bin/phpunit --no-coverage`: 92 pruebas, 278 aserciones; 6 funcionales se omitieron. |
| Pruebas funcionales de acceso comercial | Pendiente de entorno local | Las 6 pruebas de `ServiceOrderCommercialAccessTest` se omiten porque PHP no tiene habilitada la extensión `pdo_sqlite`. |
| Migraciones aplicadas | Correcto | 44 de 44 aplicadas; versión actual y última: `Version20261010150000`; no existen migraciones pendientes. |
| Mapeo y esquema aplicado | Correcto con seguimiento | Doctrine valida los archivos de mapeo. La comparación de esquema no emitió SQL pendiente; solo avisó que `--complete` está deprecado y no modifica la comparación. |
| Permisos efectivos por entorno | Revisado | Se consultaron roles y permisos reales. Falta el rol Calidad/Entregas y sus permisos específicos. |
| Catálogo real de materiales/equipos | Revisado | Se consultaron equipos, capacidades y variantes. Las brechas se detallan en la sección de base de desarrollo. |
| Almacenamiento existente de archivos | Sin datos locales | No existen `public/uploads/quotations` ni `var/storage/quotation-decisions` en esta copia de trabajo. Esto no demuestra ausencia de adjuntos en el servidor. |

La primera ejecución de Doctrine dentro del sandbox fue bloqueada por la restricción de red. Fuera del sandbox, el servidor rechazó inicialmente el usuario por host. Después de crear la autorización de MySQL remoto en Hostinger y usar el túnel SSH, las consultas de lectura funcionaron. La aplicación y sus migraciones no se modificaron durante ese ajuste de conectividad.

## Validación de la base de desarrollo

| Hallazgo | Resultado | Implicación |
| --- | --- | --- |
| Migraciones | 44 aplicadas, 0 pendientes; última `Version20261010150000`. | Las migraciones de rutas, corrección de medidas, capacidades y reservas sí existen en el entorno. Las nuevas migraciones deben ser posteriores y aditivas. |
| Órdenes | Una orden `PENDING_PLANNING`; su cotización origen está `ACCEPTED`. | Existe trabajo abierto que debe incorporarse sin crear una segunda orden. |
| Partidas abiertas | Dos partidas: una sin incidencia y una con «El Producto no tiene una ruta de producción publicada». Ninguna tiene ruta aplicada. | Antes de una migración de ejecución se debe definir tratamiento explícito para la partida sin ruta; no se le asigna una ruta retroactiva sin revisión. |
| Rutas publicadas | Una versión de ruta, asociada a un Producto de prueba, con tres pasos. | La infraestructura de rutas existe, pero no cubre la orden abierta ni demuestra cobertura del catálogo comercial. |
| Equipos | Cinco equipos disponibles; todos tienen ancho útil, pero ninguno tiene unidad/tasa de capacidad ni validación capturada. | No se habilita estimación automática confiable hasta registrar y validar esas capacidades. |
| Materiales | Una variante activa `LONA-FRONT-13OZ-DEFAULT`, con especificación mínima y sin código de barras. | El flujo de escaneo de material no puede activarse hasta completar variantes, especificaciones y códigos de barras físicos. |
| Adjuntos de cotización | Dos partidas de cotización y ningún adjunto registrado. | No hay datos de archivo que migrar en este entorno de desarrollo; se debe revisar producción antes de planear una transición masiva. |
| Roles | Existen Administrador, Ventas, Producción y Operador; no existe `ROLE_QUALITY_DELIVERY`. | La fase 1 agrega el rol y no depende de renombrar los actuales. |
| Permisos | Existen `service_orders.*`, `production_routes.manage` y `production_calendar.manage`; no existen permisos `production.*`, `quality.*` ni `delivery.*` de la estrategia. | La matriz granular de trazabilidad será una migración y casos de uso nuevos. |

La orden abierta y sus dos partidas se conservan como datos históricos de prueba. Su tratamiento se probará en la migración de incorporación: la partida sin ruta debe quedar con bloqueo visible; la otra no debe declararse lista para producir solo porque no tiene incidencia registrada.

## Línea base técnica encontrada

| Área | Ya disponible en el código | Implicación para trazabilidad |
| --- | --- | --- |
| Ciclo comercial | Solicitud pública, revisión, borrador, emisión, envío, aceptación y creación de una orden única desde cotización aceptada. | Se reutilizan `Quotation`, `QuotationStatus` y `ServiceOrderManager`; no se crea un módulo comercial paralelo. |
| Partidas de orden | `ServiceOrderItem` conserva origen de cotización, especificaciones y snapshots. | Es la base para que cada partida complete Diseño, Impresión y Acabados antes de llegar a Calidad. |
| Rutas de producción | Plantillas reutilizables, versiones por `CommercialItem`, pasos secuenciales, snapshot por partida y publicación de ruta. | Se amplían las rutas existentes para hacer obligatorio Diseño y configurar evidencias/consumos por operación. |
| Planificación | `ProductionPlanManager`, estimación física, carga, responsable, reservas de máquina, jornada y excepciones. | Se conserva esta planificación; las nuevas ejecuciones reales no deben confundirse con una reserva. |
| Equipos | Código único, operación primaria, estado, ancho útil y capacidad estructurada validable. | El código de equipo sirve como identidad para el escaneo de Impresión. |
| Materiales | `MaterialVariant` tiene código único, código de barras opcional, especificaciones y unidades. | El código de barras de variante es la base correcta para validar el material técnico, sin activar inventario. |
| Consumo | `ProductionMaterialUsage` contiene cantidad prevista, real, desperdicio y procedencia. | Debe ampliarse con paso/intento y hacerse obligatoria la cantidad real en los cierres definidos por la estrategia. |
| Auditoría y seguridad | `AuditLogger`, `PermissionVoter`, permisos granulares y separación de importes comerciales en órdenes. | Las acciones nuevas se implementan como permisos y eventos, más validación de asignación y pertenencia del recurso. |
| Correo | Symfony Mailer, servicios de correo de cotización y configuración SMTP existente. | Permite implementar la bandeja transaccional de aviso de recolección sin introducir otro proveedor por defecto. |

## Brecha frente a la estrategia vigente

La tabla siguiente distingue capacidades comprobadas en código de los componentes que todavía no existen. La ausencia se determina por entidades, controladores, plantillas, migraciones y servicios revisados; la base de datos real sigue pendiente.

| Requisito de la estrategia | Situación actual | Trabajo posterior |
| --- | --- | --- |
| Diseño obligatorio por partida y captura de referencia | No existe una ejecución de Diseño ni un activo operativo versionado. | Crear ejecución/estado de paso, archivos protegidos, captura obligatoria y liberación de diseño. |
| Archivo original viable sin duplicarlo | La partida de cotización conserva ruta/nombre de adjunto, pero la copia a orden no fija una referencia operativa. | Modelar vínculo/versionado del original y autorización explícita por partida. |
| Ejecución por operador asignado | Existe asignación para planificación, pero no un ciclo `Pendiente → En curso → Completado` de ejecución. | Crear intentos de operación, transiciones, control de asignación y eventos. |
| Escaneo de máquina/material | No hay entidad, endpoint, interfaz ni lector para escaneo. | Agregar validación de escaneo contra plan/arte vigente y sustituciones autorizadas. |
| Consumo real obligatorio y desperdicio | Existe modelo base, pero permite que el consumo real sea nulo y no está conectado a un intento. | Adaptar contratos y validaciones sin generar movimientos de inventario. |
| Etiqueta QR después de Impresión | No existe etiqueta, salida impresa, QR, historial de reimpresión ni recepción identificada. | Crear salida/etiqueta con identidad estable y artefacto imprimible. |
| Recepción y tareas de Acabados | Solo hay rutas y planificación; no existe recepción QR ni cierre por tareas. | Extender la ejecución y configurar si una operación exige consumo. |
| Calidad/Entregas como quinta etapa | No existe rol, permiso, entidad, bandeja ni flujo de revisión/calidad. | Crear rol `ROLE_QUALITY_DELIVERY`, revisiones de orden/partida, no conformidad y devolución. |
| Retrabajo con historial | No existe modelo de intento/retrabajo. | Enlazar intento nuevo con el afectado e invalidar únicamente resultados dependientes. |
| Producción terminada tras revisión de Calidad | `ServiceOrderStatus` actualmente solo distingue planificación pendiente/planificada. | Separar estado de planificación, producción y entrega; liberar con control de concurrencia. |
| Recoger en sucursal, envío y entrega | Hay datos de dirección/método de entrega comercial, no hitos operativos. | Crear `READY_FOR_PICKUP`, `READY_FOR_SHIPMENT`, `SHIPPED` y `DELIVERED` con responsables. |
| Correo automático de recolección | Existe infraestructura SMTP, no una outbox de trazabilidad. | Crear evento transaccional, procesador, reintentos técnicos y trazabilidad de envío. |
| Vista por área y tablero de Calidad | Hay vistas de órdenes, rutas y calendario; no paneles por etapa. | Crear proyecciones operativas sin importes y colas de Diseño, Impresión, Acabados y Calidad/Entregas. |

## Hallazgos que afectan el diseño

1. Las migraciones locales de octubre ya implementan una porción del FSD original. Antes de crear nuevas tablas se debe usar ese modelo como punto de partida, especialmente `commercial_item_route_versions`, `service_order_operation_plans`, `production_reservations` y `production_calendar_exceptions`.
2. El estado actual de una orden solo representa planificación. Agregar todos los estados de trazabilidad en ese único enum mezclaría conceptos. La estrategia de tres ejes —planificación, producción y entrega— sigue siendo la dirección adecuada.
3. La entidad `ProductionMaterialUsage` fue diseñada con inventario opcional y estimaciones permitidas. Para este módulo se conserva sin movimientos de inventario y se endurecen únicamente las operaciones que obligan a reportar consumo real.
4. Las cinco capacidades iniciales de equipo se sembraron originalmente como texto: Sky Color 15 m²/h, HP 365 23 m²/h, Mimaki 50 cm/s, Prime XL 70 cm/s y Laminadora DXL 100 lm/h. Aunque el código ya permite capacidad estructurada, los valores efectivos y su vigencia deben verificarse en la base y con el taller antes de estimar automáticamente.
5. Los adjuntos de solicitudes públicas se guardan actualmente bajo una ruta pública cuando el directorio existe. La estrategia requiere mover la operación futura a almacenamiento protegido y realizar transición de adjuntos reales sin perder referencias.
6. No se detectaron componentes específicos de Calidad, Entregas, QR, etiquetas, escaneos, ejecuciones de operación ni bandeja de notificaciones. Estos son trabajo nuevo, no un simple ajuste de interfaz.
7. `BootstrapSecurityCommand` contiene permisos y roles de compatibilidad históricos. La nueva matriz debe migrar y probar permisos granulares reales, sin asumir que ejecutar ese comando representa la configuración efectiva de producción.

## Decisiones de implementación ya fijadas

No requieren nueva decisión funcional:

- Todas las partidas pasan por Diseño. Si el original es viable, se autoriza sin subir un duplicado y se requiere la captura de referencia.
- La unidad de avance inicial es la partida completa. No habrá lotes parciales en esta primera versión.
- Impresión exige escaneo de máquina y material; el jefe de Producción o Administrador puede aprobar una sustitución compatible con motivo e historial.
- Impresión y acabados que consuman insumos registran material total utilizado y desperdicio; no descuentan inventario.
- Calidad/Entregas o Administrador revisa la orden completa, puede devolver partidas y es quien marca Producción terminada.
- Recolección en sucursal genera correo automático al solicitante después de liberar producción. Domicilio conserva los hitos Enviado y Entregado, sin correo de recordatorio.

## Bloqueos y acción requerida

| Bloqueo | Riesgo si se ignora | Acción requerida |
| --- | --- | --- |
| Falta `pdo_sqlite` en el PHP local. | Se omiten las pruebas funcionales HTTP que validan separación comercial en órdenes. | Habilitar la extensión de SQLite de PHP o proporcionar un entorno de prueba equivalente. No es requisito para inspeccionar MySQL, pero sí para cerrar la regresión funcional local. |
| Catálogo y dispositivos no revisados físicamente. | Se podrían publicar escaneos, códigos o estimaciones que no corresponden al taller. | Confirmar después de conectar: datos de los cinco equipos, material/variantes, códigos impresos, tablets, lectores y etiquetadora. |

No se solicita una contraseña por este documento ni debe enviarse por chat. La autorización de acceso se resolvió en Hostinger y las consultas se ejecutan mediante el túnel SSH. Mantener la sesión de PuTTY abierta mientras se requiera acceso a la base de desarrollo.

## Siguiente paso técnico propuesto

La fase 0 terminó. La siguiente modificación de código es el núcleo de trazabilidad: estados operativos separados, rol y permisos de Calidad/Entregas, eventos de transición e invariantes de ejecución. Se implementará mediante una migración nueva y aditiva, conservando la orden abierta como caso de incorporación y sin activar inventario.
