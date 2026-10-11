# PrintFlow — comandos de validación y despliegue en Hostinger

**Fecha:** 10 de octubre de 2026
**Estado:** las fases iniciales se reportaron desplegadas y operativas. El código y las pruebas locales posteriores incluyen prioridades, reprogramación y ajustes de interfaz. La última salida explícita de migraciones productivas registrada aquí es `Version20261010150000`; antes de una nueva liberación, revisar el dry run del commit y validar reservas concurrentes en MySQL.
**Alcance:** cerrar verificaciones pendientes de Fase 0 y documentar el despliegue controlado de Symfony, sin respaldos.

Antes de desplegar, revisar el conjunto de cambios, conservar todo cambio local fuera del alcance aprobado y publicar un commit identificado. No desplegar el árbol de trabajo local directamente.

### Resultado remoto recibido el 10 de octubre de 2026

La salida SSH reportada confirma Composer `2.9.0`, PHP CLI `8.2.34` y requisitos de dependencias no-dev correctos. Los scripts `cache:clear`, `assets:install` e `importmap:install` terminaron con `[OK]`. El estado de Doctrine reportó 40 migraciones aplicadas, 41 disponibles y una pendiente: `Version20261010120000`. El `--dry-run` simuló esa migración y terminó `[OK]`; **no la aplicó**.

El comando identificó la base que se confirmó como productiva en hPanel. `doctrine:schema:validate` encontró mappings válidos, pero el esquema no estaba sincronizado con esos mappings. La migración pendiente solo agrega/relaciona permisos y no corregía diferencias estructurales, por lo que hacía falta identificar el SQL de esas diferencias antes de continuar.

Este diagnóstico inicial quedó superado por una comprobación posterior: `doctrine:migrations:status` informó `44/44`, última y actual `Version20261010150000`, sin versiones nuevas pendientes; también se reportó el esquema correcto. Es la última salida explícita de migraciones productivas registrada aquí. No repetir la revisión antigua de `schema:update --dump-sql` como condición de liberación; revisar el commit objetivo con `migrations:status`, `migrations:list` y `migrate --dry-run`.

El `--dump-sql` posterior no propone borrar tablas o columnas, pero sí quitar tres claves foráneas y ocho índices, incluido `uniq_volume_review_attempt`. Esos objetos aparecen en migraciones históricas y varios protegen integridad o consultas; por tanto ese volcado **no es una lista de cambios aprobados**. El desglose y la evidencia del código están en la sección siguiente. No se ejecutó el SQL.

Comando siguiente de solo lectura para obtener ese diagnóstico:

```sh
php bin/console doctrine:schema:update --dump-sql --env=prod --no-debug
```

Guardar y revisar la salida junto con el historial de migraciones. No ejecutar la misma orden con `--force`; no aplicar SQL generado automáticamente sin identificar el origen y comprobarlo en una copia de QA.

### Lectura de la diferencia reportada

La salida posterior del servidor propone estas familias de cambios. Son diferencias entre migraciones históricas y mapeos/código, no instrucciones para ejecutar `schema:update`:

| Diferencia propuesta | Evidencia del repositorio | Tratamiento |
| --- | --- | --- |
| `quotation_items.specification_schema_version` y `service_order_items.specification_schema_version`: `SMALLINT UNSIGNED` → `INT UNSIGNED` | `Version20260816150000` crea `SMALLINT`; las entidades actuales declaran `int` sin `smallint` explícito. | Alinear mediante una migración revisada o ajustar el mapeo con una decisión explícita. No usar `schema:update --force`. |
| `client_classes.code`: `CHAR(1)` → `VARCHAR(1)` | `Version20261003010000` crea `CHAR(1)`; `ClientClass` usa `#[ORM\Column(length: 1)]`, que mapea a `VARCHAR(1)`. | Elegir una representación y migrarla explícitamente. |
| `quotations.pricing_hash`, `quotations.volume_hash` y `quotation_volume_discount_reviews.volume_hash`: `CHAR(64)` → `VARCHAR(64)` | La migración de descuentos crea `CHAR(64)`; las entidades declaran string de longitud 64. | Es una diferencia de mapeo con columnas ya pobladas; evaluar y convertir con migración explícita. |
| Claves foráneas e índices `fk_quotations_status_catalog`, `fk_qsh_from_status`, `fk_qsh_to_status`, `idx_qsh_from_status`, `idx_qsh_to_status` | `Version20260819010000` crea estos controles; `Version20261008110000` vuelve a crear la FK del estado de cotización. El estado se representa como enum escalar en vez de asociación Doctrine. | **No eliminarlos:** perderían integridad referencial del catálogo de estados. El árbol de trabajo local ya contiene un cambio sin publicar en `DatabaseManagedSchemaListener` que preserva esas tres FKs al generar el esquema. La salida de Hostinger no refleja ese resultado; Composer no publica archivos PHP. Confirmar qué commit/archivo está ejecutándose y que el listener esté registrado antes de comparar de nuevo. |
| `IDX_A9F48EAE7B00651C` en `quotations` | La salida también propone soltarlo al quitar la FK de estado; falta confirmar en información de esquema las columnas que cubre. | Consultar la definición del índice antes de clasificarlo; no borrarlo automáticamente junto con la FK. |
| `idx_costing_profiles_active`, `idx_volume_review_current`, `uniq_volume_review_attempt`, `idx_quotation_discount_quotation`, `idx_volume_rules_lookup` | `Version20261003010000` crea estos índices. Varias entidades no declaran índices equivalentes. El índice único de intento evita duplicados de revisión; los índices compuestos apoyan consultas del flujo de descuentos. | Mantener la unicidad de intento y validar/representar los índices restantes en el mapeo o en la política de esquema administrado. El volcado no debe eliminar índices de migración sin decisión. |

Para comprobar el índice de cotizaciones y si el listener está presente en el checkout remoto, se pueden ejecutar estas lecturas:

```sql
SELECT INDEX_NAME, COLUMN_NAME, NON_UNIQUE, SEQ_IN_INDEX
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'quotations'
  AND INDEX_NAME = 'IDX_A9F48EAE7B00651C'
ORDER BY SEQ_IN_INDEX;
```

```sh
git rev-parse HEAD
git status --short
grep -nE 'STATUS_FOREIGN_KEYS|DATABASE_MANAGED_INDEXES' src/Doctrine/DatabaseManagedSchemaListener.php
php bin/console debug:container 'App\\Doctrine\\DatabaseManagedSchemaListener' --env=prod --no-debug
```

Composer `install` no cambia el código fuente del checkout. La salida compartida no muestra `git fetch`, despliegue desde hPanel ni SHA del código instalado, así que no confirma que los PHP modificados localmente estén en Hostinger. Si el directorio no es un checkout Git, los dos primeros comandos pueden omitirse; inspeccionar el archivo y verificar por hPanel qué commit/paquete se publicó.

## 1. Validaciones pendientes desde Windows

Ejecutar en PowerShell desde `C:\PrintFlow`. Las siguientes órdenes no consultan ni modifican la base de datos:

```powershell
Set-Location C:\PrintFlow
git status --short
git diff --check
git branch --show-current
git log --since="2026-08-20 00:00" --oneline --decorate
git status --porcelain
git rev-parse HEAD
git show --stat --oneline HEAD

& C:\tools\php82\php.exe -v
& C:\tools\php82\php.exe --ini
& C:\tools\php82\php.exe -m | Select-String -Pattern 'PDO|sqlite|mysql|gd|intl'
& C:\tools\php82\php.exe -d extension=pdo_sqlite -m | Select-String -Pattern 'pdo_sqlite'

& C:\tools\php82\php.exe -l migrations\Version20261010120000.php
& C:\tools\php82\php.exe -l tests\Functional\ServiceOrderCommercialAccessTest.php
& C:\tools\php82\php.exe -d extension=pdo_sqlite bin\phpunit --configuration phpunit.dist.xml --filter ServiceOrderCommercialAccessTest --do-not-cache-result
& C:\tools\php82\php.exe bin\console lint:container
& C:\tools\php82\php.exe bin\console lint:twig templates
& C:\tools\php82\php.exe bin\console lint:yaml config

composer validate --strict
composer check-platform-reqs
```

Los dos comandos de Composer requieren que `composer` esté instalado localmente. Si una extensión no carga, `php --ini` muestra qué `php.ini` usa el CLI; verificar también que `extension_dir` contenga `pdo_sqlite.dll`. No sustituir el test aislado por uno que apunte a la base configurada en `.env.local`.

El SHA de `git rev-parse HEAD` solo es candidato de despliegue si `git status --porcelain` está vacío, el commit ya fue revisado y se publicó al remoto. No tomar el `HEAD` actual si contiene trabajo sin commit.

Para repetir las pruebas nuevas de Fase 3 desde la consola local normal:

```powershell
Set-Location C:\PrintFlow
& C:\tools\php82\php.exe -l src\Application\Production\ProductionPriorityRescheduler.php
& C:\tools\php82\php.exe -l src\Controller\Admin\Orders\ServiceOrderController.php
& C:\tools\php82\php.exe -l migrations\Version20261010160000.php
& C:\tools\php82\php.exe -d extension=pdo_sqlite vendor/bin/phpunit --configuration phpunit.dist.xml tests/Unit/Application/Production/ProductionCommitmentAnalyzerTest.php tests/Unit/Application/Production/ProductionScheduleCalculatorTest.php --do-not-cache-result
& C:\tools\php82\php.exe -d extension=pdo_sqlite vendor/bin/phpunit --configuration phpunit.dist.xml tests/Functional/ServiceOrderCommercialAccessTest.php --do-not-cache-result
& C:\tools\php82\php.exe bin/console lint:container --env=test
& C:\tools\php82\php.exe bin/console lint:twig templates/admin/service_orders --env=test
```

En la sesión de revisión del 11 de octubre se ejecutaron la suite de unidad de Producción (**17 pruebas, 25 aserciones**) y `ServiceOrderCommercialAccessTest` (**9 pruebas, 347 aserciones**); ambas pasaron. También pasaron `lint:twig` para el detalle de órdenes y `php -l` para los PHP modificados. El sandbox inicial no podía leer `tests/bootstrap.php` ni `.env`; las pruebas se repitieron con acceso local normal. Para `lint:twig` se proporcionó `DATABASE_URL=sqlite:///:memory:` solo al proceso de test; no se modificó `.env` ni se consultó una base productiva. Sigue pendiente la prueba concurrente contra MySQL.

Resultado local del 10 de octubre: `ServiceOrderCommercialAccessTest.php` pasó con `pdo_sqlite` (**6 pruebas, 277 aserciones**) y `ProductionRouteApplicatorTest.php` pasó (**3 pruebas, 16 aserciones**). También pasaron `lint:container`, Twig para las **101 plantillas**, y sintaxis PHP para los **30 archivos PHP** de la Fase 1. Doctrine cargó 72 entidades y las migraciones quedaron en **43 de 43**, última `Version20261010140000`. `schema:update --dump-sql` ya no propone eliminar el índice de partidas divididas; todavía muestra diferencias históricas de tipos/defaults e índices del módulo de descuentos. No ejecutar ese SQL ni `schema:update --force`. Una ejecución inicial desde el entorno de auditoría no podía abrir `.env` ni `tests/bootstrap.php`; la validación final se repitió con acceso local normal al PHP.

### Error local `Unknown column 'q0_.discount_breakdown'`

La entidad `Quotation` selecciona `discount_breakdown`, columna creada por `Version20261003010000`. La base local de desarrollo estaba en `Version20260822020000`: tenía **37 de 43** migraciones ejecutadas y seis pendientes. Se aplicaron en local (**52 consultas**). Doctrine quedó en **43 de 43**, última `Version20261010140000`, y una consulta sobre `quotations.discount_breakdown` ya se ejecutaba. La conexión comprobada fue MySQL local, no Hostinger.

Para diagnosticar una base local primero consultar y simular las migraciones; ejecutar la escritura solo contra la base local correcta:

```powershell
Set-Location C:\PrintFlow
& C:\tools\php82\php.exe bin\console doctrine:migrations:status --env=dev --no-debug
& C:\tools\php82\php.exe bin\console doctrine:migrations:list --env=dev --no-debug
& C:\tools\php82\php.exe bin\console doctrine:migrations:migrate --dry-run --env=dev --no-debug
# Después de revisar que la conexión apunta a MySQL local y que la lista es la esperada:
& C:\tools\php82\php.exe bin\console doctrine:migrations:migrate --no-interaction --env=dev --no-debug
& C:\tools\php82\php.exe bin\console doctrine:migrations:status --env=dev --no-debug
```

El `schema:validate` local sigue reportando drift histórico de tipos/defaults y de índices heredados de descuentos. La corrección del mapeo ahora conserva el índice de partidas divididas. El `schema:update --dump-sql` aún propone quitar índices históricos como `uniq_volume_review_attempt`; no aplicar ese SQL ni usar `schema:update --force`. La aplicación de migraciones y la comparación automática del esquema son operaciones distintas.

## 2. Inspección de solo lectura en Hostinger

Los ejemplos anteriores de rutas son históricos. Copiar desde hPanel la ruta SSH vigente y sustituir el marcador del primer comando. No imprimir ni pegar `.env.local`, `DATABASE_URL`, contraseñas, claves ni salidas completas de configuración.

```sh
cd /RUTA/REAL/DE/PRINTFLOW
pwd -P
git rev-parse --show-toplevel
git status --short
git rev-parse HEAD
test -f bin/console && test -f public/index.php && echo "Symfony y public/ encontrados"
php -v
php -m | sort
command -v composer2 || command -v composer
test -w var/cache && test -w var/log && echo "var/cache y var/log escribibles"

php bin/console about --env=prod --no-debug
php bin/console doctrine:migrations:status --env=prod --no-debug
php bin/console doctrine:migrations:list --env=prod --no-debug
php bin/console doctrine:schema:validate --env=prod --no-debug
```

Confirmar en hPanel que la versión de PHP del sitio sea compatible con PHP `>=8.2`, y que el *document root* efectivo sea la carpeta `public/` de esta aplicación. La versión de `php` en SSH puede diferir de la asignada al sitio. El directorio del proyecto no debe quedar expuesto bajo el dominio. La integración Git de hPanel requiere elegir repositorio, rama y ruta de instalación; comprobar que esa ruta y la raíz web de Symfony coincidan con el diseño anterior antes de pulsar Deploy. Si el plan no permite servir `printflow_app/public`, detener el despliegue y resolver primero la disposición de carpetas con las funciones disponibles en el plan. No publicar la raíz del repositorio en `public_html` por usar el valor predeterminado.

Para comprobar migraciones y permisos desde phpMyAdmin/Adminer, seleccionar la base de datos de producción exacta en hPanel y ejecutar consultas de lectura. Primero confirmar que existe `doctrine_migration_versions`; luego revisar:

```sql
SELECT version, executed_at
FROM doctrine_migration_versions
ORDER BY executed_at DESC;

SELECT r.code AS role_code, p.code AS permission_code
FROM roles r
JOIN role_permissions rp ON rp.role_id = r.id
JOIN permissions p ON p.id = rp.permission_id
WHERE p.code IN ('service_orders.view', 'service_orders.view_commercial')
ORDER BY r.code, p.code;

SELECT u.username, r.code AS role_code, p.code AS permission_code
FROM users u
JOIN user_roles ur ON ur.user_id = u.id
JOIN roles r ON r.id = ur.role_id
JOIN role_permissions rp ON rp.role_id = r.id
JOIN permissions p ON p.id = rp.permission_id
WHERE p.code = 'service_orders.view_commercial'
ORDER BY u.username, r.code;

SELECT a.code AS area_code, o.code AS operation_code, o.name AS operation_name,
       e.code AS equipment_code, e.name AS equipment_name, e.technology,
       e.usable_width_cm, e.technical_capacity, e.status
FROM equipment e
JOIN operations o ON o.id = e.primary_operation_id
JOIN operation_areas a ON a.id = o.operation_area_id
ORDER BY a.display_order, o.display_order, e.name;

SELECT COUNT(*) AS service_order_items,
       COUNT(DISTINCT commercial_item_id) AS commercial_items_used
FROM service_order_items;
```

Comprobar primero si `products` está desplegada; ejecutar el segundo SQL solo si `SHOW TABLES` devuelve la tabla:

```sql
SHOW TABLES LIKE 'products';
```

```sql
SELECT product_type, is_active, requires_production, COUNT(*) AS product_count
FROM products
GROUP BY product_type, is_active, requires_production
ORDER BY product_type, is_active, requires_production;
```

Esperado para la migración de Fase 0: `service_orders.view_commercial` se asigna a Ventas y Administración; Producción y Operador reciben `service_orders.view`, sin `service_orders.view_commercial`. Revisar cuentas que tengan varios roles: un segundo rol puede conceder acceso comercial. Si faltan tablas, permisos o versiones, detenerse y revisar el historial de migraciones antes de cambiar datos. No ejecutar `app:security:bootstrap` como comando de diagnóstico porque altera datos/permisos.

La consulta de equipos confirma el texto guardado, no una velocidad efectiva: validar capacidad por prueba cronometrada con Producción antes de usarla para prometer fechas. Si `products` no existe en esa base, detenerse y comparar con las migraciones aplicadas; si existe, sus conteos y los de `service_order_items` ayudan a detectar datos actuales, pero no establecen por sí solos una relación entre esas dos tablas.

## 3. Preparación para liberar

Este procedimiento no incluye respaldos. La ruta SSH debe confirmarse en hPanel antes de usarla. Antes de escribir, confirmar la carpeta correcta, el dominio, la raíz web `public/`, el PHP que ejecuta el sitio y la base conectada al sitio en vivo.

### Publicar desde Windows a `main`

Los archivos deben pasar revisión en `staged` antes de crear el commit. No añadir todo el árbol si hay archivos fuera del alcance de la entrega:

```powershell
Set-Location C:\PrintFlow
git status --short
git diff --cached --check
git diff --cached --stat
git diff --cached
# Después de revisar que solo estén los archivos de la entrega aprobada:
git commit -m "Publica cambios revisados de producción y trazabilidad"
git push origin main
git rev-parse HEAD
```

Conservar cualquier cambio ajeno que siga fuera de `staged`. No usar `git add -A`, `git reset --hard` ni `git clean` para preparar la publicación.

## 4. Despliegue controlado por SSH

Usar esta secuencia solo si el directorio de la aplicación es un checkout Git limpio con `origin` configurado y SSH está habilitado. Si se usa el botón Git de hPanel, seleccionar el commit/branch revisado y ejecutar por SSH las verificaciones posteriores; no asumir que el botón ejecutó Composer, migraciones o compilación de AssetMapper.

Antes de entrar al servidor, copiar el SHA completo que devolvió `git rev-parse HEAD` después del `push` revisado. En el servidor, después de comprobar en hPanel que la base destino es la del sitio productivo:

```sh
cd /RUTA/REAL/DE/PRINTFLOW
DEPLOY_COMMIT='<SHA_COMPLETO_DEL_COMMIT_APROBADO>'
test -z "$(git status --porcelain)" || { echo "Checkout con cambios; detener deploy"; exit 1; }
PREVIOUS_COMMIT="$(git rev-parse HEAD)"
git fetch --prune origin
git cat-file -e "$DEPLOY_COMMIT^{commit}"
git show --stat --oneline "$DEPLOY_COMMIT"
git switch --detach "$DEPLOY_COMMIT"

if command -v composer2 >/dev/null 2>&1; then
  COMPOSER_CMD="$(command -v composer2)"
elif command -v composer >/dev/null 2>&1; then
  COMPOSER_CMD="$(command -v composer)"
else
  echo "Composer no disponible; detener deploy"
  exit 1
fi

APP_ENV=prod APP_DEBUG=0 "$COMPOSER_CMD" install --no-dev --prefer-dist --no-interaction --optimize-autoloader
APP_ENV=prod APP_DEBUG=0 "$COMPOSER_CMD" check-platform-reqs --no-dev
php bin/console doctrine:migrations:status --env=prod --no-debug
php bin/console doctrine:migrations:list --env=prod --no-debug
php bin/console doctrine:schema:validate --env=prod --no-debug
php bin/console doctrine:migrations:migrate --dry-run --env=prod --no-debug
```

`schema:validate` y `schema:update --dump-sql` son diagnósticos: el historial de Hostinger ya mostró drift y SQL que elimina índices históricos. No ejecutar ese SQL ni `schema:update --force`. Revisar `migrations:list` y el SQL del *dry run* contra los archivos de migración del commit. Si el checkout tiene versiones pendientes distintas de las esperadas, parar. Si coincide, aplicar todas las pendientes de ese commit y preparar producción:

```sh
php bin/console doctrine:migrations:migrate --no-interaction --env=prod --no-debug
php bin/console asset-map:compile --env=prod --no-debug
php bin/console cache:clear --env=prod --no-debug
php bin/console cache:warmup --env=prod --no-debug
php bin/console doctrine:migrations:status --env=prod --no-debug
php bin/console doctrine:schema:validate --env=prod --no-debug
```

Verificar que `doctrine:migrations:status` termine en la última versión del commit desplegado. El error `discount_breakdown` solo queda resuelto en producción cuando está desplegado el código que mapea la columna y también está aplicada `Version20261003010000`; no inferirlo del estado local. La última salida explícita registrada para producción mostró `Version20261010150000`. Los despliegues posteriores no quedaron certificados en este documento: usar el `--dry-run` del commit objetivo y revisar todas las migraciones pendientes, sin asumir que una versión específica sea la única.

El repositorio no tiene un comando de mantenimiento de Symfony configurado; no usar `bin/console down`. Programar el cambio en una ventana de bajo tráfico. No usar `chmod -R 777`; resolver cualquier permiso de `var/cache` y `var/log` con el propietario/grupo de PHP asignado por Hostinger.

## 5. Comprobación posterior y recuperación

`/login` y `/health` existen en el repositorio. Configurar la URL pública correcta después de confirmar el dominio en hPanel; la siguiente consulta debe devolver HTTP 200:

```sh
APP_URL='https://DOMINIO_CONFIRMADO'
curl --fail --silent --show-error --output /dev/null --write-out '%{http_code}\n' "$APP_URL/login"
curl --fail --silent --show-error --output /dev/null --write-out '%{http_code}\n' "$APP_URL/health"
```

Después, con cuentas existentes, comprobar en navegador: flujo de inicio de sesión; empleado de Producción/Operador sin importes en la orden ni acceso a cotización, PDF y JSON comercial; Ventas con el acceso comercial esperado; aceptación de una cotización y orden resultante si existe un caso de prueba operativo seguro. No crear datos de prueba en producción. Revisar únicamente las últimas líneas pertinentes de `var/log/prod.log` y los errores del despliegue, sin copiar secretos ni registros personales completos.

Si falla una migración o la comprobación, detener nuevos cambios y conservar la salida de error. No revertir la base automáticamente ni ejecutar `doctrine:migrations:execute --down`: la migración de seguridad quita asignaciones RBAC al revertirse y volver a código anterior puede volver a mostrar precios a empleados. Resolver con una corrección hacia adelante o un cambio de código compatible.

## Referencias Hostinger

- [Desplegar un repositorio Git desde hPanel](https://support.hostinger.com/en/articles/1583302-how-to-deploy-a-git-repository)
- [Conectar por SSH a un plan Hostinger](https://support.hostinger.com/en/articles/1583245-how-to-connect-to-a-hosting-plan-via-ssh)
- [Encontrar la ruta raíz del sitio](https://www.hostinger.com/support/1583494-what-is-the-path-to-your-website-s-root-home-directory-and-how-to-change-it-in-hostinger/)
- [Cambiar la versión PHP asignada al sitio](https://www.hostinger.com/support/1575755-how-to-change-the-php-version-of-your-hostinger-hosting-plan/)
