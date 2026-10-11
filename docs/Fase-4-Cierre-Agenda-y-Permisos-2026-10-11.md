# Fase 4: agenda ante detenciones, confirmación de entrega y puestos manuales

## Comportamiento

- Pausar libera la máquina para otros trabajos, según decisión de Uriel. La cola relacionada se reorganiza usando prioridad, compromiso y creación, dentro de lunes a viernes de 08:00 a 18:00 y las excepciones vigentes.
- La operación pausada o bloqueada y sus pasos dependientes quedan sin pronóstico. No se inventa una duración de la detención. Las reservas pasadas y las fijadas se conservan; las fijadas pueden requerir revisión del jefe.
- Resolver una incidencia sin intento pausado recupera sus reservas. Si el intento sigue pausado, la recuperación espera a que el operador reanude.
- Reanudar reserva el tiempo estimado pendiente descontando solamente los segundos activos del intento actual, redondeados hacia arriba a minutos, con mínimo de un minuto. No descuenta las horas de pausa ni altera la estimación original. Es una proyección que puede variar con el tiempo real.
- Se rechaza reanudar si la máquina ejecuta otro trabajo o si no puede empezar ahora por jornada/reserva fijada. El intento y la agenda conservan su estado anterior.
- Completar libera la porción pendiente de sus reservas y ajusta la secuencia siguiente. Una fabricación completada usa la fecha real de cierre para el pronóstico.
- Agenda y transición comparten transacción y el bloqueo global MySQL existente. Cada cambio de agenda deja auditoría sin importes; los reintentos con el mismo identificador no duplican ni vuelven a mover reservas.
- El pronóstico y los riesgos se consultan desde la agenda vigente. La fecha sugerida sigue sumando tres días laborables; la fecha pactada no se cambia automáticamente.

## Permisos

`delivery.confirm` se concede por defecto a `ROLE_SALES` y se retira de `ROLE_QUALITY_DELIVERY`. Administrador conserva sus permisos. Calidad puede revisar, liberar producción y registrar envíos; Ventas define la modalidad y confirma «Entregado». El controlador conserva su validación de permiso y CSRF.

El bootstrap corrige también la concesión antigua, porque su comportamiento habitual solo añade permisos. Se declaran los permisos reales de equipos (`equipment.create`, `equipment.update`, `equipment.change_status`): la pantalla exigía esos códigos, mientras que el bootstrap solo declaraba `equipment.manage`.

## Equipos solicitados

| Código | Nombre | Operación primaria | Estimación |
| --- | --- | --- | --- |
| EQ-PRE-MESA-01 | Mesa de preprensa | PRE-DISENO — Diseño | Manual por partida |
| EQ-ACA-ESTACION-01 | Estación de acabados | ACA-ENROLLADO — Enrollado | Manual por partida |

El área se toma de la operación. Cada equipo sigue teniendo una sola operación primaria; la estación de acabados no cubre automáticamente Laminado, Corte u otros acabados. No se asignan marcas, modelos, anchuras ni rendimientos inventados. El comando valida usuario administrador, operaciones y áreas activas antes de guardar, reutiliza `EquipmentManager`, registra auditoría y evita duplicados por código. Un código existente con otra operación se rechaza.

Estado verificado: ambos puestos ya están creados en la base local `printflow_p1_20261004`. En producción, el alta por pantalla devuelve 403 a la cuenta administradora actual debido a los permisos de equipos pendientes de sincronizar; todavía no se han creado allí. Los comandos de despliegue siguientes corrigen el permiso y registran los dos puestos.

## Migraciones

- `Version20261011130000`: transfiere confirmación de entrega y sincroniza permisos de equipos para Administrador. No cambia tablas comerciales.
- `Version20261011140000`: marca seis columnas DATETIME de intentos como `utc_datetime_immutable`. Sus valores ya se guardaban en UTC; no desplaza ni recalcula datos históricos.
- La migración anterior `Version20261011120000` añade medición de segundos activos; debe estar aplicada antes de usar esta versión.

## Verificación local

- Suite completa: 139 pruebas y 1,041 aserciones correctas.
- Comprobación posterior del alcance de la reprogramación: seis pruebas y 73 aserciones correctas. Incluye una reserva independiente en un equipo inactivo, que se conserva intacta.
- Cobertura específica: huecos liberados, dependencias entre máquinas, minutos restantes, pronóstico suspendido/recuperado, rechazo de reanudación ante trabajo activo o reserva fijada, reversión sin cambios parciales, reintentos idempotentes, autorización HTTP de entrega y altas de equipos sin duplicados.
- PHP sin errores de sintaxis; mapeo de Doctrine válido. Base local en `Version20261011140000`, cero migraciones nuevas. Se verificó por SQL que ambos equipos tienen la operación prevista y carecen de rendimiento numérico validado.

Para repetir las seis pruebas específicas en PowerShell:

```powershell
cd C:\PrintFlow
& C:\tools\php82\php.exe -d extension=pdo_sqlite vendor/bin/phpunit --configuration phpunit.dist.xml tests/Functional/ServiceOrderCommercialAccessTest.php --filter 'testPauseFreesMachine|testIncidentResolutionRestores|testBusyMachineRejects|testPinnedReservationRejects|testBootstrapTransfers|testManualStationsCommand' --do-not-cache-result
```

## Despliegue Hostinger sin respaldos

Después de revisar, confirmar y subir a `main` los archivos de esta entrega, ejecutar en la carpeta productiva confirmada por Uriel. Si `git status --short` muestra cambios del servidor, resolverlos antes de hacer pull; no descartarlos.

```bash
set -e
cd /home/u692972268/domains/teramorphosis.com/printflow_app
git status --short
git branch --show-current
if [ "$(git branch --show-current)" != "main" ] || [ -n "$(git status --porcelain)" ]; then
    printf '%s\n' 'Detenido: se requiere main sin cambios locales en el servidor.'
    exit 1
fi
git pull --ff-only origin main
export APP_ENV=prod
export APP_DEBUG=0
COMPOSER_CMD="$(command -v composer2 || command -v composer)"
test -n "$COMPOSER_CMD" || exit 1
"$COMPOSER_CMD" install --no-dev --prefer-dist --optimize-autoloader --no-interaction --no-scripts
php bin/console cache:clear --env=prod --no-debug
php bin/console doctrine:migrations:status --env=prod --no-debug
php bin/console doctrine:migrations:migrate --dry-run --no-interaction --env=prod --no-debug
php bin/console doctrine:migrations:migrate --no-interaction --env=prod --no-debug
php bin/console app:equipment:ensure-manual-stations --actor=ucarbajal --dry-run --env=prod --no-debug
php bin/console app:equipment:ensure-manual-stations --actor=ucarbajal --env=prod --no-debug
php bin/console cache:clear --env=prod --no-debug
php bin/console cache:warmup --env=prod --no-debug
php bin/console asset-map:compile --env=prod --no-debug
php bin/console doctrine:migrations:status --env=prod --no-debug
php bin/console doctrine:schema:validate --skip-sync --env=prod --no-debug
```

Detenerse si cualquier comando falla. El estado final debe mostrar `Version20261011140000` y cero migraciones nuevas. No ejecutar `doctrine:schema:update --force`. La migración corrige los permisos específicos; no es obligatorio ejecutar todo el bootstrap para aplicar esta entrega. Recargar la sesión después de migrar si la pantalla conserva permisos anteriores.

Validación por SQL, sin exponer credenciales:

```bash
php bin/console doctrine:query:sql "SELECT r.code AS rol, p.code AS permiso FROM role_permissions rp JOIN roles r ON r.id = rp.role_id JOIN permissions p ON p.id = rp.permission_id WHERE p.code = 'delivery.confirm' AND r.code IN ('ROLE_ADMIN', 'ROLE_SALES', 'ROLE_QUALITY_DELIVERY') ORDER BY r.code" --env=prod --no-debug
php bin/console doctrine:query:sql "SELECT e.code, e.name, o.code AS operacion, e.capacity_rate, e.capacity_validated_at FROM equipment e JOIN operations o ON o.id = e.primary_operation_id WHERE e.code IN ('EQ-PRE-MESA-01', 'EQ-ACA-ESTACION-01') ORDER BY e.code" --env=prod --no-debug
```

Confirmar la entrega con Ventas y rechazar el mismo POST con Calidad/Entrega. Probar pausa, resolución de incidencia y reanudación con una orden realmente planificada y otra reserva futura de la misma máquina; revisar que se libera el hueco, no se solapan reservas y no se interrumpe otra ejecución activa. La validación de concurrencia de MySQL y el recorrido completo con roles en producción permanecen como puerta de salida de la fase.
