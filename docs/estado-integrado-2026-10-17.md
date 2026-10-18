# PrintFlow — estado integrado al 18 de octubre de 2026

**Propósito:** punto de partida para continuar el desarrollo y mejorar la experiencia de uso. Este documento distingue código disponible, evidencia del piloto y trabajo todavía sin verificar. No sustituye los requisitos ni las guías operativas de cada módulo.

El archivo conserva el corte detallado del 17/10 y añade el estado de las líneas de trabajo al 18/10 debajo de la tabla original.

## Corte base revisado al 17 de octubre de 2026

- Último cambio de la línea de trabajo anterior: `0df50a8` del 12/10/2026, «planificación operativa dentro de cada partida».
- Código revisado: `main` en `6c906b5` del 17/10/2026. Una consulta de solo lectura a `origin/main` devolvió el mismo SHA completo: `6c906b537c794ea71b24df75d11d67a2bbe1a811`.
- Entre ambos commits hay **33 commits y 169 archivos modificados**, concentrados en inventario y su vínculo con producción. Esta cifra describe el historial de Git, no una validación funcional.
- El árbol local contiene otros cambios y archivos sin commit. No se atribuyen a `6c906b5` ni se incluyen en el estado confirmado de la rama.
- La [guía del piloto](operacion-inventario-piloto-y-salida.md) registra un checkout `dev` en `5af994e` el 14/10. Eso no demuestra que los commits posteriores estén desplegados. Tampoco se verificó en este corte el SHA, las migraciones o los datos del sitio de Hostinger.

## Qué cambió desde el 12 de octubre

| Fecha | Capacidad incorporada al código | Estado comprobable |
| --- | --- | --- |
| 12/10 | Lectura QR de unidad física en Impresión; consumo y merma descontados del inventario; compromisos por orden, faltantes, sustituciones, espera de compra, conteo cíclico, reabasto e indicadores. | Hay controladores, servicios, entidades, migraciones y pruebas. Los recorridos completos con materiales reales siguen sujetos al piloto. |
| 13/10 | Solicitudes de compra y recepciones parciales, operaciones auditadas de bodega, presentaciones configurables y rollos separados por ancho. | Los casos de ensayo y las consultas de auditoría están documentados; el catálogo real requiere validación de unidades y conversiones. |
| 14/10 | Preparación del piloto, formulario de material más claro, primera recepción de prueba y corrección de hora en etiquetas. | La guía conserva evidencia parcial de `dev`; no certifica una salida general. |
| 15/10 | Consulta QR con cámara o lector, etiquetas QR imprimibles para equipos, corrección de redondeo de apartados y posibilidad de intervención administrativa en producción. | La lectura con cámara fue informada en `dev`; faltan pruebas registradas del recorrido físico completo y de los demás casos. |
| 17/10 | La pantalla de Impresión propone el consumo desde **Material previsto** y permite justificar una cantidad real distinta. Se documentó la conciliación del primer cierre del piloto. | El primer cierre se realizó con el formulario anterior; la propuesta automática todavía requiere comprobarse tras desplegarse. |

Las migraciones nuevas de esta serie llegan hasta `Version20261011320000`. El documento del piloto registra **71 ejecutadas de 71 disponibles** en `dev` antes de un `git pull` del 14/10; se debe repetir `migrations:status` en el checkout y la base que se pretenda evaluar.

## Modelo operativo que debe conservar el diseño

```mermaid
flowchart LR
    A[Material y presentación<br/>unidades y conversiones] --> B[Recepción<br/>QR por unidad física]
    B --> C[Saldo físico y disponible]
    D[Orden y material previsto] --> E[Compromiso y cobertura]
    C --> E
    E --> F{¿Hay faltante?}
    F -- Sí --> G[Resolución administrativa<br/>sustituto, compra o autorización]
    F -- No --> H[Diseño liberado y plan vigente]
    G --> H
    H --> I[Impresión: escanear máquina y unidad]
    I --> J[Consumo real, merma y posible sobrante]
    J --> K[Conciliación y trazabilidad]
```

- **Material** es el concepto general; la **presentación o variante** fija compra, saldo, consumo y conversiones. Un rollo de ancho distinto necesita otra presentación. No inferir metros o área desde un nombre o desde la variante legada en `PZA`.
- Cada rollo, paquete, envase o sobrante separado es una **unidad física con QR propio**. Recibir o reimprimir una etiqueta no equivale a consumir. Un apartado reduce lo disponible, sin reducir el saldo físico.
- La necesidad de una orden se calcula desde el **material previsto**. Un faltante exige un tratamiento explícito; una compra solicitada no aumenta existencias. Las recepciones parciales pueden conservar la orden en espera.
- Impresión valida la máquina y el QR esperado, registra consumo real y merma, y puede separar un sobrante reutilizable con una unidad hija. La merma forma parte del total consumido.
- Administración configura mínimos, resuelve faltantes, autoriza sustitutos y revisa ajustes. Impresión registra recepciones, escaneos, conteos y consumo según sus permisos. La intervención administrativa en producción quedó habilitada sin convertir al operador en administrador.

## Estado de las líneas de trabajo

| Línea | Situación al corte | Siguiente comprobación |
| --- | --- | --- |
| Producción y trazabilidad, fases 0–4 | Conserva rutas por partida, planificación, Diseño, ejecución, Calidad y entrega. El detalle de la orden muestra progreso, duración y fin pronosticado. | Mantener la regresión de cotización → orden → entrega, permisos y pronóstico con datos del ambiente objetivo. |
| Fase 5 de producción | El [roadmap](produccion-trazabilidad/roadmap/fases-y-historias.md) sigue pidiendo tablero diario, calendario por máquina, filtros y movimiento seguro de reservas. No se encontró integración DayPilot ni una vista de calendario en el código revisado. | Definir el alcance del tablero antes de desarrollarlo. |
| Fase 6 de producción | QA y liberación siguen como puerta de cierre del roadmap. | Probar roles, migraciones, concurrencia, fechas y recorrido completo en el ambiente elegido. |
| Inventario | El código abarca presentaciones, unidades QR, entradas, compromisos, faltantes, sustitutos, compras, conteos, reabasto, operaciones de bodega e impresión con consumo. | Terminar los casos del piloto y comparar libro, proyecciones, saldos QR y apartados. |

### Actualización de las líneas al 18 de octubre de 2026

La tabla anterior conserva el corte del 17/10. Esta actualización revisa el código de `main` en `1b28387` (18/10); los cambios locales sin commit y el estado de Hostinger quedan fuera de lo confirmado. La presencia de una vista o un flujo en el repositorio no acredita su despliegue ni una prueba completa con datos reales.

| Línea | Situación en código al 18/10 | Siguiente comprobación |
| --- | --- | --- |
| Cotizaciones y órdenes de servicio | Se incorporaron archivos por partida, vista previa de adjuntos y evidencia de respuesta del cliente. La aceptación puede registrar la orden de compra y crear la orden de servicio; la orden de compra también puede completarse después. | Recorrer aceptación → orden de servicio → Diseño con archivos reales, comprobar permisos de lectura y descarga, y verificar errores y reintentos sin duplicar la orden. |
| Diseño | La cola muestra métricas, filtros, prioridad descendente y fechas de cotización y orden. El detalle presenta especificaciones legibles, archivos con vista previa e historial, y dos rutas claras: autorizar el original o entregar arte preparado con captura guía. El acceso a la vista exige `production.design.review`; liberar archivos exige además `production.files.upload`. | Revisar ambos caminos con un diseñador, comprobar archivos originales y preparados en Impresión, formatos de vista previa, permisos por URL directa y estados sin archivo del cliente. |
| Impresión | El trabajo se presenta por pasos, con arte autorizado, configuración de equipo y material previsto, validación por QR, consumo real y etiqueta. El estado del material distingue apartados, faltantes, sustitutos, espera de compra y autorización parcial; los faltantes nuevos pueden avisarse por correo a administradores. | Repetir el cierre del piloto con la propuesta de consumo, comprobar sustitución y compra parcial, correo y permisos, y conciliar consumo, merma, sobrante y saldo físico. |
| Ejecución de producción | El index prioriza el turno del operador: resumen, filtros, operación destacada con ruta de la partida y siguientes operaciones en filas compactas. Si no hay tarea en curso, destaca una lista para iniciar. La cola del operador se limita a sus asignaciones; gestión de incidencias y materiales conserva los permisos específicos. | Validar la vista con cuentas de operador y jefe, filtros y estados de bloqueo, cronómetro, acciones de iniciar/pausar/reanudar/completar y adaptación móvil. |
| Calidad y Entregas | Se conservan la revisión de Calidad y la liberación hacia Entregas. Este corte no confirma un rediseño nuevo de esos dos módulos ni el cierre funcional del recorrido completo. | Probar retrabajo → nueva liberación → Calidad → Entrega y después abordar la legibilidad de la cola y el detalle de Entregas. |
| Fase 5: tablero y calendario | La cola diaria de Ejecución cubre una parte de la consulta operativa del turno. El calendario diario por máquina, la semana por equipo y el movimiento seguro de reservas de [Fase 5](produccion-trazabilidad/roadmap/fases-y-historias.md) siguen pendientes; no se verificó integración DayPilot. | Definir datos, permisos y acciones del calendario y comprobar reservas, solapes y cambios de agenda en el servidor antes de habilitar movimientos en la interfaz. |
| Inventario y Fase 6 | El código añade mensajes de disponibilidad de material, avisos de faltante y la migración `Version20261018060000` para conciliar apartados reducidos o retirados. El piloto físico y la puerta de QA de Fase 6 siguen abiertos. | Verificar migraciones en la base objetivo y completar las consultas de auditoría, los casos pendientes del piloto, la matriz de permisos y la regresión cotización → entrega. |

**Trabajo local posterior al corte del 18/10 — Fase 5:** se incorporó el calendario diario por máquina con DayPilot Lite local, la vista semanal para `production.assign`, filtros de servidor, pendientes sin horario, detalle operativo y movimiento futuro con vista previa y confirmación. El servidor valida permisos, CSRF, jornada y excepciones, solapes, secuencia y compatibilidad bajo el bloqueo de agenda; Impresión mantiene su cambio de equipo en su flujo especializado. Pasaron verificaciones de sintaxis PHP, JavaScript y Twig independientes del arranque de Symfony. La comprobación funcional con cuentas de Operador y jefe, datos reales y concurrencia MariaDB sigue pendiente; este avance local no acredita despliegue.

### Evidencia y pendientes del piloto de inventario

La [guía de salida](operacion-inventario-piloto-y-salida.md) y los [datos de ensayo](datos-prueba-piloto-inventario.md) separan los códigos `QA-` del catálogo real. Para `OS-2026-000013`, partida 1, se informó una recepción de **10 ML**, una necesidad sintética de **6 M2 = 4 ML**, lectura de cámara y cierre de Impresión con **6 ML** restantes en el rollo. Cobertura mostró 4 ML consumidos y cero pendiente. Esto valida parcialmente reserva, lectura y conciliación del primer caso.

Siguen pendientes de evidencia: rechazo del conteo hecho solo para probar el lector; separación de **1.5 ML** como QR hijo y reimpresión; uso de la nueva propuesta automática de consumo; caso de faltante con sustituto; caso de compra y dos recepciones parciales; conciliación mediante [consultas de auditoría](consulta-inventario-piloto.sql); permisos, plataforma PHP y pruebas con dispositivos/etiquetas físicas. No declarar completo el piloto ni trasladar saldos `QA-` a una base de operación real.

## Mapa de interfaz para la siguiente etapa de diseño

| Pantalla | Lo que ya ofrece | Oportunidad de diseño respaldada por la vista actual |
| --- | --- | --- |
| Órdenes de servicio, listado y detalle | Métricas, filtros, progreso por etapas y resumen de planificación por partida. | Afinar mensajes de siguiente acción y coherencia visual con los nuevos estados de inventario; conservar la separación de datos comerciales. |
| Impresión, cola y trabajo | Cola con pestañas y estados; trabajo con pasos, arte, material previsto, cámara, consumo, etiqueta y trazabilidad. | Validar en el piloto la propuesta de consumo y hacer inequívocas unidad prevista, unidad física del rollo, merma y saldo resultante. |
| Calidad | Resumen, filtros, riesgo, revisión por operación y no conformidad. | Revisar con usuarios si la acción principal y el motivo de retrabajo se encuentran rápidamente; mantener permisos. |
| Entregas | La lista es una tabla simple sin filtros; el detalle agrupa formularios y muestra eventos con nombres técnicos. | Prioridad visual: filtros por estado/compromiso/modalidad, acción principal según etapa y trazabilidad legible para Ventas y Calidad. |
| Inventario físico | Consulta QR, filtro y tabla de unidades; hasta ocho accesos de trabajo compiten en el encabezado según permisos. La portada lista saldos por unidad física, sin un resumen agregado de físico, comprometido y disponible por presentación. | Prioridad visual: portada por tareas frecuentes y resumen de cobertura por presentación, búsqueda/escaneo prominentes y acceso secundario a configuración y reportes. En móvil, revisar la tabla con desplazamiento horizontal. |
| Recepción, conteos, compras y bodega | Flujos separados con permisos y registros auditados. | Homogeneizar estados, cantidades con unidad visible, validación contextual y confirmación de acciones que cambian saldo o disponibilidad. |

**Orden sugerido para UI/UX:** 1) Entregas; 2) entrada de Inventario y consulta de unidad; 3) captura de recepción/conteo y resolución de faltantes; 4) pulido de Impresión tras observar el nuevo cierre. Mantener componentes, colores y navegación actuales de OoxCorp y probar cada vista con el perfil que la usa. Este orden es de diseño, no altera la prioridad del piloto técnico ni del calendario de la fase 5.

## Documentos, código y verificación

- Requisitos de inventario: [estrategia](estrategia-modulo-inventario.md), [modelo](inventory-model/README.md), [piloto](operacion-inventario-piloto-y-salida.md) y [consultas SQL](consulta-inventario-piloto.sql).
- Producción: [índice](produccion-trazabilidad/README.md), [guía por rol](produccion-trazabilidad/operacion/guia-operativa-planificacion-a-entrega.md), [roadmap](produccion-trazabilidad/roadmap/fases-y-historias.md).
- Entradas de código para continuar: `src/Controller/Admin/Inventory/`, `src/Application/Inventory/`, `src/Controller/Admin/Production/PrintingController.php`, `templates/admin/inventory/`, `templates/admin/printing/`, `templates/admin/quality_delivery/`, `templates/admin/service_orders/` y `assets/styles/modules/`.
- En este corte pasaron **58 pruebas unitarias y 206 aserciones** de `tests/Unit/Application/Inventory`, `tests/Unit/Entity/Inventory` y `PrintingConsumptionResolverTest`. No se ejecutó aquí una suite funcional completa, ni un ensayo concurrente en MariaDB, ni una comprobación de las migraciones o del despliegue de Hostinger.

## Vigencia de documentos anteriores

El [estado de producción del 11/10](produccion-trazabilidad/estado-actual.md) describe su corte histórico. Su frase «Inventario continúa pospuesto» ya no representa el repositorio del 17/10. La [guía del piloto](operacion-inventario-piloto-y-salida.md) llama `dev` a la base `u692972268_printflow_dev`; en conversaciones anteriores esa misma base fue identificada como productiva para el sitio. Antes de cualquier despliegue o ensayo con datos reales, confirmar el propósito actual de ese ambiente por su configuración y operación, sin deducirlo del nombre de la base ni de `--env=prod`.
