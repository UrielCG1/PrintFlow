# Registro técnico — asignación de operadores y estándares de tiempo

> Registro cronológico de cambios en producción y trazabilidad; no corresponde a una fase nueva del roadmap.

## Asignación de operadores

- Los usuarios con permiso `production.assign` pueden asignar o cambiar al operador responsable mientras la orden está pendiente de planificación o planificada, siempre que la operación tenga reserva activa y todavía no se haya iniciado ningún intento de ejecución.
- La asignación sigue validando que la persona esté activa y tenga el rol `ROLE_OPERATOR`. Las operaciones de Diseño (`PRE-DISENO`) no entran en esta asignación: las atiende el perfil de Diseño.
- En el detalle de una orden aparece una acción masiva que asigna un operador solo a las operaciones reservadas, activas, sin bloqueo, sin asignación previa y aún no iniciadas. No reemplaza responsables existentes ni modifica operaciones en ejecución.
- Una vez iniciada la operación, el selector de responsable se oculta y los endpoints rechazan cambios. Cada cambio permitido queda auditado.

## Estándares por Producto

En la ruta específica de cada Producto se puede configurar, para cada paso, una regla opcional:

`minutos estándar = minutos base por partida + minutos por unidad × cantidad vendida`

El resultado se redondea hacia arriba al minuto entero. Los campos se pueden dejar vacíos; no se inventan rendimientos ni se rellenan valores por defecto. La regla publicada queda copiada en el snapshot de la ruta de la partida cuando se crea la orden, para que los cambios futuros del Producto no reescriban órdenes existentes.

La estimación estándar da un pronóstico inicial y permite preparar la operación. Cuando se asigna la máquina y se reserva su agenda, el sistema la sustituye por una estimación física únicamente si la máquina tiene capacidad validada y las dimensiones proporcionan la carga automática necesaria. Un estándar sin validar conserva su procedencia y no se presenta como capacidad medida. Para corte, los metros lineales se deben capturar o medir directamente; no se calculan deduciendo el recorrido desde el número de piezas.

Al dividir una partida, el sistema recalcula el estándar de cada hija con su cantidad; el reparto comercial existente conserva el total contratado.

## Tiempo real

El tiempo real se obtiene de los segundos activos de los intentos de ejecución y se redondea hacia arriba a minutos. Las pausas quedan excluidas. La vista muestra el tiempo medido y su diferencia contra la estimación vigente. La captura manual queda como corrección excepcional auditada para `service_orders.plan`.

## Persistencia y despliegue

`Version20261011150000` agrega `standard_base_minutes` y `standard_minutes_per_unit`, ambas opcionales, a `commercial_item_route_steps`. No altera pedidos ni valores comerciales. Se aplica con Doctrine Migrations; no usar `doctrine:schema:update --force`.

En la base local `printflow_p1_20261004`, la migración se aplicó correctamente: 54 migraciones ejecutadas, cero nuevas pendientes. El dry-run fue correcto. La batería focalizada pasó con 33 pruebas y 677 aserciones; PHP lint no reportó errores y `git diff --check` no encontró problemas. El mapping de Doctrine es válido.

La comprobación de sincronía completa de Doctrine sigue reportando drift local. El dump propone eliminar varios índices/campos históricos; también lista las dos columnas de estándar aunque `information_schema` confirma que existen y `doctrine:mapping:describe` las reconoce. No se ejecutó ese SQL. La validación de sincronía en MySQL/MariaDB y el recorrido por roles en producción siguen pendientes; en Hostinger se debe revisar el dry-run de migraciones, no aplicar `schema:update`.

En Hostinger, seguir el runbook [validación y despliegue](../operacion/validacion-y-despliegue-hostinger.md), comprobar el nombre de la base productiva en hPanel y revisar todo el SQL de `doctrine:migrations:migrate --dry-run` antes de aplicarlo. No se hicieron cambios en producción.

## Archivos de referencia

- Regla y flujo: [FSD de producción y trazabilidad](../definicion/FSD-produccion-y-trazabilidad.md).
- Progreso y pendientes: [estado actual](../estado-actual.md) y [roadmap](../roadmap/fases-y-historias.md).
- Migración de esta entrega: `migrations/Version20261011150000.php`.
