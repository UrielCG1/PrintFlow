# Producción y trazabilidad

Documentación funcional y técnica para el flujo que transforma una cotización aceptada en una orden planificada, ejecutada, revisada y entregada. El módulo reutiliza las órdenes, partidas, productos comerciales, catálogos, permisos y servicios existentes en PrintFlow.

## Por dónde empezar

1. [Estado actual y mapa técnico](estado-actual.md): alcance construido, pendientes, evidencias y archivos principales del código.
2. [Guía operativa](operacion/guia-operativa-planificacion-a-entrega.md): recorrido y responsabilidades por perfil.
3. [Roadmap e historias](roadmap/fases-y-historias.md): alcance, criterios de aceptación y dependencias de las fases de producto.
4. [FSD](definicion/FSD-produccion-y-trazabilidad.md): requisitos funcionales y límites.
5. [Estrategia de implementación](definicion/estrategia-de-implementacion.md): arquitectura existente, decisiones de diseño y extensiones propuestas.
6. [Runbook de validación y despliegue](operacion/validacion-y-despliegue-hostinger.md): comandos y comprobaciones para local y Hostinger.
7. [Registros técnicos de implementación](implementacion/README.md): evidencia y contexto de cambios realizados por fecha.

## Cómo interpretar las fases

Las fases de producto son las del [roadmap](roadmap/fases-y-historias.md): línea base; rutas; estimación y agenda; compromisos y prioridad; ejecución y entrega; calendario; QA y liberación.

Los archivos de `implementacion/` son registros cronológicos de entregas. Sus números originales describían otra secuencia de trabajo y **no corresponden uno a uno** con las fases del roadmap. La estrategia conserva también una propuesta técnica inicial con su propia secuencia. Para saber qué está pendiente, prevalece el roadmap; para conocer el detalle de una modificación concreta, consulta el registro fechado y el código enlazado.

## Decisiones vigentes resumidas

- La cotización aceptada crea la orden y aplica la ruta por partida; la orden queda pendiente del plan operativo (`PENDING_PLANNING`). Ventas registra la fecha compromiso. Producción confirma el plan cuando todas las operaciones activas tienen ruta válida, máquina, estimación y reserva (`PLANNED`).
- Toda partida pasa primero por Diseño (`PRE-DISENO`), incluso si el cliente envió un archivo: Diseño valida medidas y preparación, autoriza el arte y adjunta referencia visual. Las rutas pueden variar en los pasos posteriores. Revisar el arte no exige asignar una persona como operadora; el permiso `production.design.review` habilita la acción.
- Ventas confirma la fecha compromiso, define la modalidad y confirma la entrega. Calidad/Entregas revisa y libera Producción y registra el envío a domicilio.
- Una pausa libera la máquina; el sistema recalcula el pronóstico y la agenda relacionada. No desplaza una operación que ya está en curso ni una reserva fijada sin la revisión prevista.
- El personal operativo recibe datos de trabajo y trazabilidad, no importes comerciales. Los cambios de interfaz actuales añaden avance por etapas y agrupan el menú de Operación por tarea.
- El correo automático de entrega queda pospuesto. Inventario también queda fuera; descuentos comerciales ya existentes no se modifican por este módulo.
- La jornada base es de lunes a viernes, 08:00–18:00, en `America/Mexico_City`; las excepciones configuradas se respetan.
- La vista de calendario por máquina sigue en el roadmap. La guía de librerías y licencias del FSD explica la opción gratuita elegida y los límites de FullCalendar.

## Estado de producción

La documentación conserva evidencias aportadas en fechas distintas. La última salida de migraciones de Hostinger registrada en el runbook informó `Version20261010150000`; después hubo reportes de rutas de prioridades en el sitio, pero no quedó registrada una salida posterior de migraciones que certifique el estado completo actual. Antes de cualquier despliegue, ejecutar el runbook contra la rama y el checkout que se van a publicar.

Los registros de pruebas locales certifican solo los comandos, entorno y fecha que allí se anotan. No equivalen a una certificación integral de producción.
