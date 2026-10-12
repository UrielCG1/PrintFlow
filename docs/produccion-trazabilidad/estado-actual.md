# Estado actual y mapa técnico

**Actualizado:** 11 de octubre de 2026
**Alcance:** estado documentado del trabajo en el repositorio; no certifica por sí solo el estado de Hostinger.

## Qué cubre el módulo

Aceptar una cotización crea la orden y aplica a cada partida su ruta publicada. La orden queda en `PENDING_PLANNING` hasta que Ventas registre la fecha compromiso y Producción valide equipo, estimación y reservas de todas las operaciones activas; esa confirmación la cambia a `PLANNED` y habilita el inicio de Diseño. Diseño revisa cada partida aunque el cliente haya entregado un archivo. El sistema conserva por separado los datos comerciales y la vista de trabajo que usan los perfiles de producción. Planificación, producción y entrega son estados relacionados, pero independientes.

El sistema ya contempla rutas de producción, estimaciones, reservas, compromisos, prioridad por partida, Diseño y archivos protegidos, ejecución por intentos, pausas e incidencias, revisión de Calidad, modalidad y confirmación de entrega. La interfaz de detalle presenta el avance por etapas y acciones pendientes. El menú de Operación se agrupó en trabajo diario, planificación del taller y materiales/proveedores, según permisos.

## Avance y alcance pendiente

| Área | Estado documentado | Pendiente principal |
| --- | --- | --- |
| Seguridad comercial en órdenes | Separación por permiso y pruebas funcionales locales registradas. | Mantener regresión para HTML, JSON, PDF, descargas, exportaciones y accesos directos a medida que aparezcan salidas nuevas. |
| Ruta automática y snapshot por partida | Implementados con los catálogos actuales; toda ruta nueva inicia con `PRE-DISENO` obligatorio. Diseño revisa el original del cliente o prepara/corrige el arte y adjunta referencia. Las partidas divididas sustituyen la fila y conservan el total comercial. | Conservar la regresión desde cotización aceptada hasta la orden y la ruta aplicada. |
| Estimación, asignación y agenda | Implementados tiempos estándar por paso de ruta del Producto, snapshot y cálculo por cantidad, asignación de operadores por permiso antes del inicio y duración real desde segundos activos. El detalle de la orden proyecta la disponibilidad de máquinas por secuencia, reservas y excepciones; compara términos cuando existen datos confiables y muestra empates sin elegir arbitrariamente. `Version20261011150000` ya está aplicada en la base local. | La vista previa nueva está validada localmente, pero no desplegada ni probada en Hostinger. Configurar estándares solo con referencias reales; ejecutar validación de concurrencia en MySQL/MariaDB y comprobar capacidades de equipos. |
| Compromisos y prioridades | Fecha sugerida, riesgo, motivo de cambios, vista previa de desplazamientos y reprogramación implementados. Se corrigió la conversión de fecha observada en el endpoint productivo. | Confirmar con evidencia que el flujo corregido funciona en la base productiva y probar competencia/solapes en MySQL. |
| Diseño y archivos | Bandeja, revisión, autorización de arte y almacenamiento protegido implementados. La revisión no requiere asignación de operador; se controla por `production.design.review`. | Probar con el perfil de Diseño y verificar el ciclo de archivo original, arte preparado, captura y liberación en los datos reales que correspondan. |
| Ejecución, calidad y entrega | Pausas/incidencias recalculan agenda; la pausa libera equipo. Ventas confirma entrega; Calidad/Entregas revisa y libera Producción. La etiqueta QR de salida impresa ya genera SVG en local; la dependencia sigue pendiente de instalar en Hostinger. | Ejecutar y registrar el recorrido completo por roles en producción, incluyendo retrabajo, etiqueta QR y envío/entrega. |
| Equipos manuales para Diseño y Acabados | Comando idempotente y pruebas locales registrados para Mesa de preprensa y Estación de acabados. | El registro técnico disponible no confirma que ambos equipos estén creados en Hostinger. Verificar tras revisar permisos y migraciones. |
| Interfaz del detalle y navegación | Progreso por etapas, siguiente acción y menú de Operación agrupado están incorporados en el frontend. | Revisar en los tamaños de pantalla y perfiles de uso reales. El calendario por máquina sigue pendiente. |
| Calendario visual por máquina y filtros | En roadmap como fase de tablero. La vista diaria del empleado y la vista con filtros para jefatura están descritas. | Implementar, comprobar AssetMapper, JSON, permisos y navegación diaria/semanal; usar únicamente la edición y licencia elegidas en el FSD. |
| QA y liberación | Se documentan suites unitarias y funcionales locales en el runbook y los registros fechados. | Resolver las puertas de concurrencia, matriz de seguridad ampliada, consistencia de migraciones y smoke test productivo. |

## Evidencias que deben leerse con fecha

- La salida de Hostinger compartida el 11 de octubre registró 44 migraciones y `Version20261010150000`. Es la última salida explícita de migraciones recogida en los documentos fuente.
- Un reporte posterior de producción muestra que la ruta de confirmación de prioridad se ejecutó, pero devolvió un error al enlazar una fecha. La corrección quedó incorporada localmente y fue cubierta por pruebas; un POST fallido no certifica el flujo productivo corregido ni la agenda final.
- El registro de agenda y permisos informa base de desarrollo local en `Version20261011140000`, 139 pruebas/1,041 aserciones de suite y seis verificaciones específicas posteriores con 73 aserciones. Lee ese registro para conocer el alcance exacto.
- La base local `printflow_p1_20261004` quedó en `Version20261011150000`, con 54 migraciones aplicadas y cero pendientes. La validación de sincronía de Doctrine todavía reporta drift que requiere evaluación; el dump propone quitar objetos históricos y las columnas estándar, aunque estas aparecen tanto en `information_schema` como en el mapping. No se ejecutó ese SQL. Esta migración y el código asociado aún no están incluidos en la última evidencia de Hostinger documentada; no asumir que los estándares o permisos de asignación ya están desplegados.
- El runbook registra una ejecución local de `ServiceOrderCommercialAccessTest` con 9 pruebas y 347 aserciones, más 17 pruebas unitarias de Producción y 25 aserciones. No mezclar esos resultados con las cifras históricas de octubre 10.

## Mapa de código existente

| Responsabilidad | Archivos principales |
| --- | --- |
| Conversión de cotización aceptada y armado de la orden | `src/Application/Orders/ServiceOrderManager.php`, `src/Application/Orders/ProductionRouteApplicator.php` |
| Plantillas y publicación de ruta | `src/Application/Production/ProductionRouteTemplateManager.php`, `src/Application/Production/CommercialItemRoutePublisher.php`, `src/Controller/Admin/Production/ProductionRouteController.php` |
| Entidades de orden, partida y operación | `src/Entity/Orders/ServiceOrder.php`, `src/Entity/Orders/ServiceOrderItem.php`, `src/Entity/Orders/ServiceOrderOperationPlan.php` |
| Capacidad, duración y calendario | `src/Application/Production/ProductionLoadResolver.php`, `src/Application/Production/ProductionTimeEstimator.php`, `src/Application/Production/ProductionScheduleCalculator.php`, `src/Entity/Production/ProductionReservation.php` |
| Comparación de disponibilidad de equipos en el detalle de la orden | `src/Application/Production/ProductionEquipmentAvailabilityAdvisor.php`, `src/Controller/Admin/Orders/ServiceOrderController.php`, `templates/admin/service_orders/show.html.twig`, `assets/styles/modules/service-orders.css` |
| Estándares por ruta de Producto y tiempo real | `src/Entity/Production/CommercialItemRouteStep.php`, `src/Application/Orders/ProductionRouteApplicator.php`, `src/Entity/Orders/ServiceOrderOperationPlan.php`, `migrations/Version20261011150000.php` |
| Compromiso, prioridad y pronóstico | `src/Application/Production/ProductionCommitmentForecaster.php`, `src/Application/Production/ProductionCommitmentAnalyzer.php`, `src/Application/Production/ProductionPriorityRescheduler.php` |
| Ejecución, pausa y reanudación | `src/Application/Production/ProductionExecutionManager.php`, `src/Application/Production/ProductionIncidentManager.php`, `src/Controller/Admin/Production/ProductionExecutionController.php` |
| Diseño y archivos | `src/Application/Production/DesignReviewManager.php`, `src/Service/Production/ProductionFileStorage.php`, `src/Controller/Admin/Production/DesignController.php` |
| Calidad y entrega | `src/Application/Production/ProductionQualityDeliveryManager.php`, `src/Controller/Admin/Production/QualityDeliveryController.php` |
| Permisos iniciales | `src/Command/BootstrapSecurityCommand.php`, migraciones Doctrine en `migrations/` |
| Orden de servicio e interfaz de Operación | `src/Controller/Admin/Orders/ServiceOrderController.php`, `templates/admin/service_orders/show.html.twig`, `templates/partials/_app_sidebar.html.twig`, `assets/styles/modules/service-orders.css`, `assets/styles/components/sidebar.css` |

## Qué no se incluye

Inventario, existencias, reservas y movimientos de materiales continúan pospuestos. Los descuentos de cotización pertenecen al módulo comercial existente. El correo automático al cliente al terminar una orden queda para una decisión o fase posterior; no se debe presentar como comportamiento implementado.

## Cómo cerrar una fase

Usa los criterios y dependencias del [roadmap](roadmap/fases-y-historias.md). Luego valida migraciones, pruebas, permisos y smoke test con el [runbook](operacion/validacion-y-despliegue-hostinger.md). No marques una fase como cerrada solo porque exista una pantalla o un servicio.
