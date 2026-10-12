# PrintFlow — FSD de producción y trazabilidad

**Versión:** 1.3 · **Fecha:** 11 de octubre de 2026 · **Estado:** especificación funcional base; aplicar las decisiones posteriores resumidas en el índice y el roadmap.

> **Precedencia y vigencia:** este FSD conserva los requisitos de la etapa inicial. Cuando una decisión posterior difiere, prevalecen el [roadmap y sus actualizaciones](../roadmap/fases-y-historias.md) y la [guía operativa vigente](../operacion/guia-operativa-planificacion-a-entrega.md). Entre las precisiones posteriores: aceptar una cotización crea la orden pendiente de plan operativo; Producción confirma ese plan después de completar y reservar sus operaciones; todas las partidas pasan por revisión obligatoria de Diseño aunque el cliente envíe un archivo; Ventas confirma «Entregado»; la pausa libera la máquina y actualiza la agenda; el correo automático al cliente sigue pospuesto.

## 1. Propósito y alcance

Planear y seguir la fabricación de cada partida de una orden de servicio derivada de una cotización aceptada. Al crear la orden, PrintFlow aplica y conserva la ruta publicada del Producto cotizado. Después, durante la planificación operativa, Producción completa los datos pendientes, estima duración, asigna máquinas compatibles y reserva huecos laborables. Producción ve el trabajo y registra su avance sin recibir precios ni importes comerciales. El jefe de Producción y el Administrador gestionan excepciones, agenda, prioridades e incidencias. Ventas confirma la fecha compromiso y la entrega.

Quedan fuera de esta entrega el control de existencias, reservas o descuentos de inventario, compras y alertas de stock. Los descuentos de cotización ya existen y no forman parte de este módulo. El correo al cliente al finalizar queda como acción manual o integración posterior; ninguna transición enviará correo automáticamente en la primera versión.

## 2. Base real del repositorio y límites de verificación

| Capacidad actual | Evidencia | Implicación |
| --- | --- | --- |
| Orden única desde cotización aceptada | `src/Application/Orders/ServiceOrderManager.php::createFromAcceptedQuotation`, `src/Controller/Admin/Orders/ServiceOrderController.php`, tablas `service_orders` y `service_order_items` | La aceptación habilita un POST posterior para crear la orden. Extender ese caso de uso, sin crear otro flujo. Ya hay bloqueo de cotización, transacción, folio y restricciones únicas. |
| Partidas y ruta automática | `ServiceOrderItem`, `ServiceOrderOperationPlan`, `ServiceOrderManager::createFromAcceptedQuotation`, `ProductionRouteApplicator` | La orden nueva copia la ruta publicada aplicable a cada partida y conserva snapshots. La planificación posterior completa equipos, tiempos y reservas; `ServiceOrderManager::markPlanned` valida esos datos y registra la confirmación. |
| Producto del cotizador | `src/Entity/Catalog/CommercialItem.php` y FK de `QuotationItem`/`ServiceOrderItem` | La ruta de esta etapa pertenece a `CommercialItem`, que es el Producto utilizado realmente por la cotización. `src/Entity/Products/Product.php` y `products` existen, pero no están conectados a este flujo; no crear una segunda ruta allí. |
| Catálogos de operación/equipo | `OperationArea`, `Operation`, `Equipment`, `EquipmentRepository` | Existe operación primaria por equipo y filtro de activos. `technical_capacity` es texto; el ancho útil es numérico. Disponibilidad de catálogo no equivale a hueco libre. |
| Interfaz y seguridad | Twig, AssetMapper, Stimulus, `PermissionVoter`, `role_permissions` | Los permisos se validan en servidor. No hay FullCalendar, Scheduler ni licencia detectada en el repositorio. |
| Riesgo de importes detectado en la línea base | `Version20260810220000.php`, `templates/admin/service_orders/index.html.twig`, `show.html.twig`; corrección local en `Version20261010120000.php` | La línea base daba consulta operativa a Producción y las plantillas imprimían también importes. La corrección local conserva `service_orders.view` para operación, agrega `service_orders.view_commercial` para Ventas/Administración, habilita lectura para `ROLE_OPERATOR` y oculta datos comerciales en ambas plantillas. Falta confirmar migración y asignaciones en cada entorno. |

Esta tabla describe código inspeccionado. No confirma migraciones aplicadas, contenido de `role_permissions` de cada entorno ni estado de Hostinger. Los cambios locales sin commit existentes al 10 de octubre se preservan; no se reescriben migraciones ejecutadas.

## 3. Decisiones funcionales aprobadas

| Tema | Regla |
| --- | --- |
| Unidad inicial y división | Una partida aceptada genera un trabajo. Antes de iniciar ejecución y mientras la orden siga en planificación, Producción puede dividirla una vez con permiso y motivo. Las partidas hijas sustituyen la fila original en la orden y son visibles para Ventas. La primera conserva el vínculo único a la partida de cotización; las hijas nuevas tienen FK de origen nula y apuntan a la primera hija. |
| Importes al dividir | Las cantidades hijas deben sumar exactamente la cantidad contratada. Conservan el precio unitario y distribuyen el subtotal de la partida proporcionalmente a la cantidad, redondeando a centavos y asignando el residuo para que la suma sea exacta. El subtotal, descuentos, impuestos y total de la orden no cambian; la cotización aceptada tampoco se modifica. |
| Corrección técnica | Producción puede corregir ancho/alto terminado antes de planificar, con motivo. Se conserva el snapshot original de cotización; la corrección vive en el snapshot técnico de producción y no recalcula precios. La ruta se marca pendiente de revisión explícita antes de planificar. |
| Rutas | Rutas base reutilizables se asignan a varios Productos; cada Producto publica su configuración y variantes técnicas. La versión aplicada se congela en el trabajo. |
| Secuencia | Los pasos son secuenciales en la primera versión. Los datos deben admitir dependencias futuras sin activar paralelismo ahora. |
| Diseño y arte del cliente | Todas las partidas pasan por Diseño como primera operación obligatoria. Diseño revisa el archivo y las dimensiones; si el original del cliente es apto, lo autoriza y adjunta una captura con el Producto de referencia; si requiere ajustes o falta, prepara el archivo para impresión y adjunta esa captura. La siguiente operación no inicia hasta que Diseño libera la partida. |
| Excepciones de datos | La orden se crea aunque falte ruta, medida o rendimiento; la partida indica «requiere planificación/estimación» y una causa. No se inventan horas ni fechas. |
| Máquina y persona | Durante la planificación operativa se asigna una máquina compatible y se reserva cuando los datos lo permiten. Producción puede asignar en lote operaciones reservadas sin responsable y cambiar responsable por paso mientras la ejecución no haya iniciado, incluso después de `PLANNED`. La asignación usa `production.assign`; Diseño no requiere persona Operadora asignada. |
| Jornada base | Lunes a viernes de 08:00 a 18:00, sin pausa fija. Sábado, domingo y horario nocturno están excluidos por defecto; jefe o Administrador pueden autorizar una jornada extraordinaria concreta, con motivo. |
| Promesa de entrega | Se calcula fin de producción y se sugieren tres días **laborales** adicionales. La primera fecha pactada puede confirmarse sin motivo; cambiar o quitar una fecha guardada requiere motivo y alerta de riesgo. |
| Prioridad | Normal por defecto, Alta y Urgente. Urgente y cambios de prioridad requieren motivo. El desplazamiento de reservas futuras requiere vista previa y confirmación; un proceso activo no se interrumpe automáticamente. |
| Visibilidad | Operadores ven todos los trabajos de producción y solo datos operativos. Solo pueden ejecutar pasos asignados a ellos. Jefe y Administrador pueden reasignar. |
| Entrega | «Producción terminada» y «Entregado al cliente» son hitos separados. Ventas confirma la entrega. |
| Materiales | Nombre, cantidad y unidad previstos/usados por trabajo o paso, sin existencias ni movimientos automáticos. |
| Alertas | Inicialmente dentro del tablero, detalle y calendario. Los correos de alerta quedan fuera de la primera versión. |
| Calendario | DayPilot Lite: día con máquinas como columnas para empleados; jefe con la misma vista y filtros, más semana horizontal con máquinas como filas. |

## 4. Actores, permisos y datos

Los permisos se agregan al sistema granular de `permissions` y `role_permissions`, siguiendo el `PermissionVoter` existente. `service_orders.view` da lectura operativa a Producción y Operador; `service_orders.view_commercial` da importes y evidencia comercial y se asigna a Ventas y Administración. `quotations.view`, `quotations.create`/`update` y `quotations.download_pdf` siguen protegiendo detalle, previsualización JSON de precios y PDF comercial por separado. La asignación efectiva aún debe comprobarse en la base de cada entorno; el nombre de rol por sí solo no concede acceso.

| Acción o dato | Operador | Jefe de Producción | Ventas | Administrador |
| --- | --- | --- | --- | --- |
| Ver tablero, todos los trabajos, calendario e historia operativa | Sí | Sí | Solo si se concede permiso operativo | Sí |
| Ver importes, cotización comercial o PDF comercial | No | No por permiso productivo | Según permisos comerciales | Según permisos comerciales |
| Configurar/publicar rutas y capacidades | No | Sí | No | Sí |
| Asignar responsable, máquina, prioridad, mantenimiento y jornada extraordinaria | No | Sí | No | Sí |
| Iniciar, pausar, bloquear y completar pasos | Solo los asignados | Según permiso operativo | No | Según permiso operativo |
| Revisar impacto y confirmar reprogramación | No | Sí | No | Sí |
| Ajustar fecha prometida con motivo | No | Sí | Sí | Sí |
| Confirmar entrega al cliente | No | No | Sí | Según permiso de entrega |

Implementar permisos separados para configurar ruta/capacidad, planificar, reprogramar, cambiar prioridad, ejecutar, reportar/resolver incidencia, cerrar producción, cambiar compromiso y confirmar entrega. `service_orders.view` se conserva como consulta operativa; `service_orders.view_commercial` controla precios y evidencia comercial. La vista operativa actual omite importes, RFC, notas comerciales y evidencia de aceptación en HTML. La proyección de futuras respuestas JSON, PDF operativo y exportaciones deberá construirse en el servidor con campos permitidos explícitamente; descargas y URL directas se autorizan por separado. La migración nueva y las pruebas locales no confirman qué versión o permisos hay aplicados en Hostinger.

## 5. Flujo funcional

1. **Configurar Producto.** El jefe o Administrador asigna una ruta base reutilizable al `CommercialItem`, ajusta sus operaciones posteriores a Diseño y variantes técnicas, y publica una versión. Toda ruta publicada inicia con `PRE-DISENO` obligatorio; las rutas pueden diferir después de esa revisión según el Producto. Una ruta publicada debe ser inequívoca para las características de una partida. Publicar otra versión no modifica órdenes existentes.
2. **Crear orden.** El POST existente desde cotización aceptada crea una sola orden y copia las partidas dentro de la misma transacción. Por cada partida aplica snapshot técnico y de ruta y crea pasos en `ServiceOrderOperationPlan`. El resultado queda en `PENDING_PLANNING` (pendiente de completar el plan operativo); todavía no significa que las máquinas estén reservadas ni que la fabricación haya comenzado. Los importes comerciales permanecen en la orden, fuera de la proyección operativa.
3. **Completar el plan operativo.** Ventas registra la fecha compromiso acordada con el cliente. Producción revisa la ruta copiada, asigna máquina compatible y reserva todos los pasos activos en secuencia dentro de la jornada. Una regla de tiempo estándar de la ruta del Producto estima el trabajo automáticamente; si falta, se usa capacidad física validada cuando hay carga confiable o se captura una estimación manual justificada. La máquina se reserva sin exigir operador. Si falta ruta, carga, estimación o compatibilidad, se informa la causa y no se inventa un pronóstico.
4. **Revisar.** Jefe/Administrador ve excepciones, asigna persona en lote a pasos reservados sin responsable o corrige un responsable por paso antes de iniciar, corrige medidas técnicas con motivo e introduce estimación manual justificada cuando no hay regla ni cálculo confiable. Una corrección conserva el snapshot de cotización, exige revisar y confirmar la ruta y no recalcula importes. Una división conserva cantidad y subtotal mediante filas hijas visibles para Ventas y recalcula tiempos estándar por cantidad. Ventas puede ajustar la promesa al pactarla; se muestra el riesgo calculado.
5. **Ejecutar.** El operador asignado pasa el paso por `Pendiente → Listo → En curso → Completado`, con `Pausado` y `Bloqueado` cuando aplique. Cada transición guarda actor, instante UTC, estado anterior/nuevo y motivo cuando corresponda. El tiempo real se obtiene de los segundos activos acumulados por intento y excluye pausas; una corrección manual se conserva como excepción auditada. Al completarse se habilita el paso siguiente y se recalcula pronóstico.
6. **Calidad y retrabajo.** Si falla calidad, jefe/Administrador abre un nuevo intento o retrabajo vinculado al paso original, con motivo. Los intentos y eventos anteriores permanecen. Un paso ejecutado no se borra para corregir una ruta.
7. **Cerrar y entregar.** Al completar pasos obligatorios y resolver bloqueos, una persona autorizada marca «Producción terminada». Ventas confirma después «Entregado al cliente». Ninguno de esos hitos dispara correo automático en la primera versión.

El estado operativo de orden es distinto del estado comercial de cotización. Los estados actuales `PENDING_PLANNING` y `PLANNED` deben ampliarse o complementarse mediante migración nueva, sin reescribir las anteriores.

**Excepción para Diseño:** el paso obligatorio inicial `PRE-DISENO` no requiere asignación individual como Operador. Puede iniciarlo y autorizar sus archivos cualquier usuario activo con permiso `production.design.review`; se registra como responsable de ese intento. La asignación de operador permanece disponible para las demás operaciones.

### 5.1. Qué significa confirmar el plan operativo

La aceptación de la cotización y la confirmación del plan operativo son acciones distintas, aunque ambas ocurran al inicio del recorrido:

| Momento | Qué ocurre | Estado / responsable |
| --- | --- | --- |
| Se acepta la cotización y se crea la orden | PrintFlow crea la orden, copia sus partidas y aplica a cada una la versión de ruta publicada para el Producto. | `PENDING_PLANNING`: la orden existe y espera completar la planificación operativa. La creación no confirma fecha, máquina ni agenda. |
| Ventas confirma la fecha compromiso | Registra la fecha acordada con el cliente; cualquier cambio posterior conserva motivo e historial según las reglas vigentes. | La fecha comercial queda establecida por Ventas. Esto por sí solo no confirma la agenda de taller. |
| Producción confirma el plan operativo | El servidor valida fecha compromiso, ruta sin incidencias, al menos una operación activa por partida, equipo compatible, estimación y reserva activa para cada operación. Registra actor y fecha de confirmación. | `PLANNED`: la orden puede iniciar la operación de Diseño. No significa que ya se fabricó ni que Diseño haya aprobado el archivo. |

Por lo tanto, **«Confirmar planificación» confirma que el trabajo tiene una ruta ejecutable y una agenda reservada**, no que se esté creando la orden, autorizando el arte, iniciando la producción o confirmando la entrega. La operación de Diseño `PRE-DISENO` comienza después de esta confirmación y sigue siendo un control obligatorio independiente.

### 5.2. Flujo de planificación en la interfaz

- La orden muestra avance por máquina, tiempo, agenda y fecha compromiso. Las acciones disponibles indican cuántos datos faltan y se habilitan solo cuando sus requisitos están satisfechos.
- Una única máquina compatible aparece como recomendación y se puede asignar con la preparación automática de la orden. Si hay varias, Producción elige; si no hay ninguna, se presenta esa causa.
- Con máquina y capacidad validadas, el sistema calcula automáticamente las unidades físicas que pueda inferir con seguridad. Si no tiene dimensión suficiente, solicita únicamente la carga necesaria: m² reales, metros lineales del acomodo o cm de trayectoria vectorial. No deriva longitud de corte de las piezas.
- Las estimaciones manuales, la corrección manual de tiempo real, el cambio de prioridad, el ajuste de agenda, la reasignación por paso, la corrección técnica, la división y el retiro de pasos son excepciones o mantenimiento y permanecen en secciones plegadas hasta que se soliciten.
- La reserva principal se ejecuta para toda la orden como una operación coordinada: prevalidación y escritura transaccional, etapas en secuencia y sin solapes. Si algún paso no puede reservarse, la acción completa se revierte. Los trabajos que ya tienen reserva se conservan al completar reservas pendientes.
- La primera fecha compromiso puede asignarse sin motivo. Si se modifica o elimina una fecha ya guardada, se exige motivo y queda auditoría. La fecha sugerida se precarga como propuesta, nunca se confirma por sí sola.
- La orden solo se puede cerrar como planificada después de reservar todos sus pasos y confirmar una fecha compromiso. Personal operativo sigue viendo la ruta de solo lectura y no recibe importes.

## 6. Rutas, variantes y estimación

Una ruta base es una ayuda de mantenimiento, no una ruta copiada en vivo. Cada Producto publica una versión concreta con secuencia, operación de catálogo, condición de inclusión, obligatoriedad, equipo preferido y alternativas compatibles. En la ficha actual cada equipo tiene una operación primaria; varias máquinas registradas para la misma operación son las alternativas. La capacidad textual queda como referencia. La capacidad estructurada guarda unidad, rendimiento, ancho útil, preparación, limpieza, eficiencia, vigencia, usuario validador e instante de validación. Sin confirmación de medición, la máquina puede asignarse para una estimación manual justificada, pero no calcular tiempo automáticamente. En v1 una operación aparece como máximo una vez en la ruta inicial de una partida, por la restricción única actual; el retrabajo es un nuevo intento de ejecución. Si negocio necesita repetir formalmente una operación en la ruta inicial, se revisará esa restricción antes de publicar tal ruta.

Cada estimación conserva datos de origen, regla, resultado y, cuando aplique, carga/unidad física, rendimiento, equipo, preparación, limpieza, eficiencia, vigencia o motivo manual. La ruta publicada por Producto puede configurar minutos base por partida y minutos por unidad. La estimación estándar se calcula como `ceil(minutos_base + minutos_por_unidad × cantidad)` y queda congelada en el snapshot del paso. En el momento de reservar, una capacidad de máquina validada y una carga automática confiable sustituyen ese estándar por el cálculo físico; si no hay regla estándar o carga física confiable, se mantiene la captura manual justificada. El calendario aplica minutos a huecos laborales; duración técnica y fecha de fin son conceptos distintos.

El cálculo físico con capacidad estructurada redondea hacia arriba a minutos: `minutos = ceil(preparación + procesamiento / eficiencia + limpieza)`. Para m²/h y m lineales/h, procesamiento es `carga / rendimiento × 60`; para cm/s de trayectoria es `carga_cm / rendimiento / 60`. Corte necesita centímetros medidos de trayectoria; no se deducen de piezas o área.

| Operación | Carga y regla | Dato ausente |
| --- | --- | --- |
| Impresión | Área **real a imprimir** en m² / m² por hora, más preparación. Validar ancho útil, paneles, repeticiones, márgenes y modo. El m² facturado puede ser distinto. | No estimable si faltan medidas físicas, distribución o rendimiento. |
| Laminado | Capturar los metros lineales de avance / metros lineales por hora medidos conforme al ancho útil y la disposición. | Los m² por sí solos no determinan longitud de avance; no convertir área a metros sin ancho y acomodo confirmados. |
| Corte de vinil | Longitud total medida de trayectorias en cm / cm por segundo, convertida a minutos, más preparación y limpieza. | No derivar longitud de corte de piezas o área. Capturar cm de vector o estimación manual justificada. |
| Tarjetas, guillotina y otros acabados | Unidad propia validada: hojas, cortes, lotes, piezas o tiempo fijo. | No utilizar velocidades de plotter de vinil. |
| Diseño, revisión de archivo y calidad | Tiempo estándar por Producto/variante o estimación manual calibrada. | Marcar no estimable si no hay regla fiable. |

Las cinco fichas semilla del repositorio contienen Sky Color 160 cm/15 m²/h, HP 365 160 cm/23 m²/h, Mimaki 61 cm/50 cm/s, Prime XL 160 cm/70 cm/s y Laminadora DXL 160 cm/100 lm/h. Son datos de ficha **textuales**, pendientes de validación y calibración con producción; no se convierten por análisis de texto en capacidades numéricas de manera automática.

## 7. Agenda, prioridades y alertas

El calendario base usa `America/Mexico_City`; los instantes se guardan en UTC y se presentan en hora local. Los campos nuevos usan una conversión UTC explícita para que la zona predeterminada de PHP/MySQL no desplace los tramos al guardar o consultar. Se configuran feriados, mantenimiento, averías, bloqueos y excepciones extraordinarias por máquina. Una excepción autorizada identifica máquina, fecha, tramo, actor y motivo. Las horas de personal no se descuentan del tiempo de máquina salvo que se configure una restricción explícita futura.

Cada paso tiene estimación y cero o más segmentos de reserva diarios. Los segmentos usan intervalos `[inicio, fin)` y no se solapan en la misma máquina. Una operación de 24 horas requiere, en una máquina libre con jornada de diez horas, dos segmentos de diez horas y uno de cuatro, distribuidos entre días laborables; no ocupa 24 horas continuas. Las dependencias impiden iniciar un paso antes del anterior. En Fase 2 el jefe elige la máquina entre las compatibles; varias reservas simultáneas sobre una máquina se serializan con bloqueo de fila antes de revisar huecos. Feriados, mantenimiento, averías y horas extra autorizadas alteran los huecos; al cambiar una excepción se recalculan reservas futuras no iniciadas y se conservan los segmentos de operaciones que ya comenzaron.

El orden de la cola pendiente es prioridad, fecha prometida, fecha de creación e identificador como desempate estable. Reprogramar bloquea la máquina/cola en transacción, relee reservas, comprueba solapes y guarda versión, actor y motivo. Una urgencia puede mover reservas futuras **no iniciadas**; preserva ejecución activa, mantenimiento y reservas fijadas. El jefe/Administrador ve antes de confirmar la máquina elegida, las reservas desplazadas, nuevas fechas y riesgos. Si confirma, todos los cambios y eventos se guardan de forma atómica; si hay carrera o conflicto, se rechaza y se solicita recalcular.

El pronóstico de cada partida es el fin de su último paso requerido. El de la orden es el máximo de sus partidas. La fecha sugerida al cliente suma **tres días laborables completos** al fin previsto de producción según calendario del negocio. Una fecha pactada manual puede diferir, pero se registra motivo y no oculta riesgo. Alerta ámbar para holgura baja o espera crítica y roja para pronóstico posterior al compromiso; umbral de holgura configurable. Bloqueo se muestra aparte. Prioridad y riesgo nunca comparten un único significado cromático; se muestran texto e icono.

**Regla confirmada el 11 de octubre de 2026:** una pausa libera la máquina para otros trabajos. Se retiran las reservas pendientes que puedan moverse y las de pasos dependientes; se conserva la agenda pasada y su auditoría. Sin hora de resolución conocida, la partida y la orden muestran «Sin pronóstico» con la causa de pausa/incidencia. Las reservas fijadas se conservan y exigen revisión del jefe si entran en conflicto. Resolver una incidencia no reanuda un intento pausado. Al reanudar, se reserva `max(1, ceil(minutos estimados − segundos activos / 60))`, se reorganiza únicamente la cola relacionada por máquina y dependencias, y se recalculan pronóstico y margen de tres días laborables sin cambiar la fecha compromiso. Una máquina ejecutando otro trabajo impide reanudar; una jornada o reserva fijada que impida empezar ahora también rechaza la acción, conservando el intento pausado. Cierre de operación libera sus reservas pendientes y permite ajustar los pasos siguientes. La transición y la agenda se guardan juntas bajo el bloqueo global existente.

## 8. Pantallas y calendario

- **Empleado de Producción:** día seleccionado, horas en vertical y máquinas como columnas. El lunes muestra las máquinas y sus reservas del lunes; navegación por fecha. Ve trabajos de todo el taller, etapa, destino siguiente, responsable, carga, estado y riesgo, sin dinero. Puede abrir detalle y solo actuar sobre pasos asignados.
- **Jefe/Administrador:** misma vista diaria, selección de fecha y filtros por máquina, área, operación, estado, prioridad, responsable, orden, Producto y riesgo. Incluye semana horizontal con máquinas como filas, ocupación por jornada, horas disponibles/reservadas/ejecutadas/libres y trabajos con riesgo. Puede abrir vista previa y confirmar reprogramaciones.
- **Tablero y lista:** trabajos pendientes, en proceso, bloqueados y en riesgo; búsqueda por folio y Producto, filtros persistibles y enlaces a detalle.
- **Detalle operativo:** partida y especificación técnica, archivo autorizado, ruta congelada, etapa actual, siguiente destino, equipo, persona, estimación frente a tiempo real, materiales manuales, incidencias y eventos. Las secciones comerciales son otra proyección y requieren sus permisos propios.
- **Detalle de planificación de la orden:** avance y pendientes primero; sugerencia de máquina y estimaciones seguras; una acción coordinada para reservar la orden. Los motivos, ajustes, correcciones y controles de seguimiento se muestran bajo demanda. La vista comercial y los importes siguen sujetos a `service_orders.view_commercial`.

Se usará **DayPilot Lite**, Apache 2.0, disponible para uso comercial. Su calendario libre ofrece recursos como columnas en vista diaria y su Scheduler libre ofrece recursos como filas en línea temporal semanal. La jerarquía día × máquina en columnas y ciertas funciones avanzadas son Pro; no se incluyen. Integrarlo como activo local de AssetMapper y controlador Stimulus, sin CDN en producción. El endpoint de eventos recibe rango y filtros autorizados, devuelve únicamente campos operativos y representa cada segmento por separado. Arrastrar o redimensionar, si se habilita para jefe/Administrador, solicita vista previa y confirmación al servidor. La UI nunca decide solapes ni fecha de entrega.

`timeGridWeek` estándar de FullCalendar agrupa por día, no por máquina; `resourceTimeGridWeek` requiere FullCalendar Premium/licencia. FullCalendar v3 `agendaWeek` con Scheduler y `groupByDateAndResource` también era Premium. No se usará v3 como atajo gratuito. Referencias: [DayPilot Lite](https://javascript.daypilot.org/open-source/), [vista de recursos DayPilot](https://doc.daypilot.org/calendar/resources-view/), [FullCalendar TimeGrid](https://fullcalendar.io/docs/timegrid-view), [FullCalendar Premium](https://fullcalendar.io/docs/vertical-resource-view), [Scheduler v3](https://legacy.fullcalendar.io/v3/scheduler).

## 9. Datos y cambios mínimos previstos

Nombres de tablas nuevos son propuestas técnicas que se validarán con el esquema aplicado, sin arquitectura paralela:

| Extensión | Datos esenciales |
| --- | --- |
| `commercial_item_route_versions` y `commercial_item_route_steps` | FK a `commercial_items`, versión, publicación, criterios de variante, secuencia, operación, condición, obligatoriedad y reglas de estimación. Reutilización de ruta base mediante vínculo/versionado, no edición retroactiva. |
| Extensión de `service_order_items` | Snapshot técnico de producción separado del snapshot de cotización; `split_parent_item_id` y `split_position` para filas hijas visibles; estado operativo, prioridad heredada y pronóstico en fases posteriores. Solo la primera fila del grupo conserva `source_quotation_item_id`; las hijas lo dejan nulo. |
| Extensión de `service_order_operation_plans` | Versión de ruta, condición aplicada, estimación, responsable, estado operativo y fechas reales. Conservar snapshots y FK actuales. |
| `equipment_capabilities` | Equipo, operación, unidad/valor decimal de rendimiento, ancho y condiciones, preparación, eficiencia y vigencia. Coexiste con `equipment.technical_capacity`. |
| Calendarios y excepciones | Jornada base, feriados, mantenimiento/avería, bloqueo y jornada extraordinaria por máquina con actor/motivo. |
| Segmentos de agenda | FK al plan y equipo, inicio/fin UTC, estado, versión y fijación manual. Índices por equipo/tiempo y plan; exclusión de solapes en lógica transaccional para MySQL/MariaDB. |
| Eventos e intentos | FK a partida/plan, tipo, actor, estado anterior/nuevo, motivo, tiempo real y vínculo de retrabajo. Solo anexar. |
| Materiales y archivos | Notas de cantidad/unidad sin stock; referencias a archivos protegidos por descarga autorizada. |

La restricción única `source_quotation_id` de `service_orders` y `source_quotation_item_id` de `service_order_items` se conserva. En una división, la fila original se convierte en la primera hija y conserva el FK; las demás filas usan FK nula y enlazan con `split_parent_item_id`. Las FK históricas usan `RESTRICT` y los eventos no se borran.

## 10. Criterios de aceptación y seguridad

1. Una cotización aceptada produce una sola orden mediante el flujo existente; cada partida obtiene trabajo y ruta publicada sin captura repetida. Dos solicitudes concurrentes no duplican orden ni trabajos.
2. Cambio posterior de ruta, catálogo o ficha de equipo no altera el snapshot de órdenes existentes. Falta de ruta, archivo o carga técnica produce causa visible y ninguna fecha falsa.
3. Solo se reserva máquina activa y compatible en jornada permitida; ninguna pareja de reservas de una máquina se solapa, incluso con dos solicitudes simultáneas. Trabajo de varios días se divide en segmentos.
4. Una Urgente reubica solo futuro no iniciado tras vista previa y confirmación. Los desplazados conservan historia y muestran nuevo riesgo. Mantenimiento y jornada extraordinaria alteran el plan únicamente con autorización.
5. Operador ve todos los trabajos y solo muta pasos asignados. Cada transición, corrección, retrabajo y cambio de plan deja actor, hora y motivo cuando corresponde.
6. Producción terminada exige pasos obligatorios completos y bloqueos resueltos. Ventas confirma Entregado por separado. No se envía correo automático.
7. Con cuenta operativa, verificar ausencia de precio, subtotal, descuento, IVA, total, costo, margen y valores deducibles en **HTML visible u oculto, atributos `data-*`, JSON/AJAX, eventos del calendario, PDF operativo, exportaciones, archivos y bitácora accesible**. Acceso directo a cotización, PDF comercial, endpoint o descarga sin permiso devuelve denegación sin filtrar datos.
8. Todas las mutaciones requieren permiso servidor, CSRF cuando corresponda y validación de pertenencia de orden/partida/paso, además de control de concurrencia.
9. Dividir mantiene exactas la cantidad y el subtotal comercial de la fila original, deja sin cambio el total de la orden y la cotización, copia ruta/snapshot a cada subtrabajo y registra actor/motivo. Empleados operativos no reciben los importes de ninguna fila hija.
10. Corregir medidas solo modifica el snapshot técnico de producción, registra actor/motivo, conserva precio y cotización y bloquea la planificación hasta que el jefe confirme que revisó la ruta.
11. Un estándar configurado en la ruta de Producto se copia a la orden, calcula minutos base más tasa por cantidad, se recalcula al dividir y queda reemplazado por cálculo físico solo cuando máquina, capacidad y carga son válidas. El tiempo real visible suma segundos activos de los intentos y excluye pausas; cualquier ajuste manual requiere motivo y auditoría.

## 11. Verificación previa a implementación y liberación

Este apartado describe la revisión técnica inicial y sus límites a esa fecha; sus observaciones de extensiones, caché y pruebas ya no representan el estado más reciente. Las salidas posteriores y comandos vigentes están en el [runbook de validación y despliegue](../operacion/validacion-y-despliegue-hostinger.md) y en el [estado actual del módulo](../estado-actual.md). Antes de migrar, consulta el estado de Doctrine, el esquema y los permisos efectivos del entorno objetivo.
