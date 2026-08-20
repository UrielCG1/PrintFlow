# PrintFlow — Contexto técnico integral

> Documento vivo del proyecto. Consolida el estado funcional y las decisiones confirmadas hasta el **27 de julio de 2026**. Úsalo como referencia antes de desarrollar, revisar, desplegar o modificar módulos.
>
> **Fuente de verdad de código:** el repositorio local `C:\PrintFlow` y su historial Git. Este documento no sustituye el código ni las migraciones; explica cómo están organizados y por qué. Las copias de archivos utilizadas para consolidarlo pueden corresponder a un momento anterior a las últimas correcciones indicadas en la sección [Cambios confirmados recientes](#cambios-confirmados-recientes).

---

## 1. Propósito y alcance

PrintFlow es una plataforma web interna para una empresa de impresión. Su objetivo es convertir el flujo comercial y operativo en un proceso trazable: catálogo de clientes, cotizaciones, órdenes, materiales, producción, usuarios y control administrativo.

El alcance comercial que originó el proyecto contempla:

- Cotizador con generación de PDF, envío por correo e historial.
- Órdenes de servicio y de trabajo, también descargables y notificables.
- Gestión de clientes, proveedores, servicios, operaciones, materiales con stock, equipos y usuarios.
- Trazabilidad interna de los trabajos de impresión por etapas, materiales y destino siguiente.
- Alertas, por ejemplo por bajo inventario.

**Estado de implementación confirmado:** el cimiento técnico, el acceso administrativo, la auditoría, clientes, contactos, direcciones, catálogo comercial, cotizaciones y PDF ya existen y funcionan correctamente. También están integrados los catálogos operativos de **proveedores**, **áreas operativas**, **operaciones** y **equipos**, con permisos, CSRF, auditoría, filtros y baja lógica/estado según el dominio.

La Fase 2 se encuentra en su subfase de integridad y cierre. Sus datos semilla técnicos ya están incorporados para operaciones y equipos; no se deben inventar proveedores ni materiales: dichos datos se cargarán únicamente con una fuente validada por negocio. El catálogo de materiales todavía no forma parte del código presente en el repositorio y permanece fuera de este cierre hasta contar con sus datos y reglas de negocio definitivas.

---

## 2. Stack, entorno y decisiones base

| Área | Decisión vigente |
| --- | --- |
| Framework | Symfony **7.4.14 LTS**, aplicación server-rendered |
| PHP | Local: **8.2.31**. Producción Hostinger: **8.2.30** |
| Base de datos | MySQL **8.0.46** local; nombre actual: `printflow_app` |
| Zona de negocio | `America/Mexico_City` |
| Persistencia de fechas | UTC en MySQL y entidades; conversión a zona de negocio para filtros y presentación |
| Vista | Twig, Symfony Forms, Twig Components / UX y Stimulus |
| JavaScript/CSS | AssetMapper; Bootstrap 5, Bootstrap Icons, jQuery, Notyf, SweetAlert2 y SortableJS **locales**. CSS propio con tokens y componentes; no CDN como dependencia operativa |
| Sesión y seguridad | Symfony Security, sesiones, CSRF, rate limiter y RBAC por permisos almacenados en MySQL |
| ORM y esquema | Doctrine ORM + Doctrine Migrations; MySQL/InnoDB/utf8mb4 |
| Pruebas disponibles | PHPUnit, BrowserKit, CSS Selector y entorno `test` configurado |
| Despliegue previsto | Hostinger Business mediante SSH, Git y Composer; sin Docker |

### Identidad visual

- Marca principal: `#27F52E`.
- Hover: `#16C61D`.
- Activo: `#10A816`.
- Fondo suave de marca: `#E8FDEA`.
- Interfaz administrativa: escala de grises, acento verde, jerarquía sobria y componentes reutilizables.

No se debe crear un estilo aislado para cada pantalla. Los módulos nuevos deben construir sobre los tokens, layouts y componentes existentes.

---

## 3. Arquitectura funcional

La regla central es separar HTTP, validación, reglas de negocio y persistencia:

```text
Navegador / Twig
        │
        ▼
Controller ── autorización, request/response, CSRF, flashes
        │
        ▼
DTO + Form ── captura y validación de entrada
        │
        ▼
Manager / Application Service ── reglas de negocio + transacciones + auditoría
        │
        ▼
Entity + Repository ── modelo persistente y consultas especializadas
        │
        ▼
MySQL + AuditLog
```

### Responsabilidad de cada capa

| Capa | Debe hacer | No debe hacer |
| --- | --- | --- |
| Controller | Validar permiso, construir/recibir formulario, delegar, redirigir, mostrar flash | Reglas de negocio, SQL, `flush()` de lógica compleja |
| DTO | Transportar datos del caso de uso y declarar validaciones | Persistencia o autorización |
| Form Type | Definir campos, etiquetas, ayudas, opciones de UI | Regla de negocio transversal |
| Manager | Validar invariantes, coordinar repositorios, usar transacciones, registrar auditoría | Renderizar Twig o depender de detalles de ruta |
| Entity | Estado, normalización local, relaciones, timestamps y pequeñas invariantes | Recibir `Request`, consultar repositorios o generar respuestas |
| Repository | Consultas reutilizables, filtros y paginación | Cambios de negocio no ligados a una consulta |
| AuditLogger | Crear registros de auditoría saneados dentro de la transacción del caso de uso | Ejecutar `flush()` por sí mismo |

---

## 4. Estructura actual del repositorio

La siguiente estructura reúne el inventario confirmado y el lugar canónico para cada responsabilidad. `vendor/` y `var/` no se versionan como fuente y no son puntos de desarrollo.

```text
PrintFlow/
├── assets/
│   ├── app.js                           # Punto de entrada: importa CSS y Stimulus
│   ├── stimulus_bootstrap.js             # Registro de controladores Stimulus
│   ├── controllers/
│   │   ├── csrf_protection_controller.js # Protección complementaria de formularios
│   │   └── ui/
│   │       ├── confirm_action_controller.js
│   │       ├── flash_toast_controller.js
│   │       ├── password_visibility_controller.js
│   │       ├── sidebar_controller.js     # Apertura/cierre responsivo del sidebar
│   │       ├── sortable_controller.js    # Reordenamiento reutilizable con SortableJS
│   │       └── tooltip_controller.js
│   └── styles/
│       ├── app.css                       # Índice global de estilos
│       ├── foundation/
│       │   ├── tokens.css                # Colores, espaciado, fuentes y radios
│       │   ├── base.css                  # Base y normalización visual
│       │   └── bootstrap-overrides.css   # Ajustes globales de Bootstrap
│       ├── layout/
│       │   ├── public-shell.css          # Shell de pantallas públicas, como login
│       │   └── app-shell.css             # Grid autenticado, contenido y responsive
│       ├── components/
│       │   ├── button.css
│       │   ├── card.css
│       │   ├── flash.css
│       │   ├── form.css
│       │   ├── page-header.css
│       │   ├── sidebar.css
│       │   ├── sortable.css
│       │   ├── status-badge.css
│       │   ├── sweetalert.css
│       │   └── table.css
│       └── modules/                      # Estilos exclusivos solo si el módulo lo justifica
│           ├── audit-log/
│           ├── clients/
│           ├── dashboard/
│           ├── roles/
│           ├── security/
│           └── users/
├── config/
│   ├── packages/
│   │   ├── doctrine.yaml                 # Conexión y mapeo Doctrine
│   │   ├── doctrine_migrations.yaml      # Namespace y ruta de migraciones
│   │   ├── framework.yaml                # APP_SECRET, sesiones y entorno test
│   │   ├── security.yaml                 # Provider, firewall, login, logout y acceso
│   │   ├── twig.yaml                     # Configuración Twig
│   │   └── ...
│   ├── routes/                           # Rutas no definidas por atributos
│   ├── bundles.php
│   └── services.yaml                     # Servicios, autowiring y renderer de PDF
├── migrations/
│   ├── Version20260719164200.php         # Acceso, RBAC y auditoría inicial
│   ├── Version20260721223000.php         # Contactos de cliente y permisos asociados
│   ├── Version20260721224500.php         # Primera parte del lote de direcciones
│   ├── Version20260721230000.php         # Direcciones y permisos finales
│   ├── Version20260725163000.php         # Catálogo comercial, precios por cantidad y catalog.*
│   ├── Version20260726113000.php         # Folio anual de cotizaciones y permisos PDF
│   ├── Version20260726140000.php         # Catálogo de proveedores y suppliers.*
│   ├── Version20260726160000.php         # Áreas operativas, operaciones, semillas y permisos
│   └── Version20260726170000.php         # Equipos, Laminado, semillas y equipment.*
├── public/
│   ├── index.php                         # Front controller Symfony
│   ├── build/                            # Assets publicados por AssetMapper
│   └── vendor/                           # Dependencias frontend locales, si aplica
├── src/
│   ├── Application/
│   │   ├── Access/
│   │   │   ├── RoleManager.php
│   │   │   └── UserManager.php
│   │   ├── Catalog/
│   │   │   ├── CommercialCategoryData.php
│   │   │   ├── CommercialCategoryManager.php
│   │   │   ├── MeasurementUnitData.php
│   │   │   ├── MeasurementUnitManager.php
│   │   │   └── ...                       # Productos/servicios, rangos y resolución de precio
│   │   ├── Clients/
│   │   │   ├── ClientAddressData.php
│   │   │   ├── ClientAddressManager.php
│   │   │   ├── ClientContactData.php
│   │   │   ├── ClientContactManager.php
│   │   │   ├── ClientData.php
│   │   │   └── ClientManager.php
│   │   ├── Equipment/
│   │   │   ├── EquipmentData.php
│   │   │   └── EquipmentManager.php
│   │   ├── Operations/
│   │   │   ├── OperationAreaData.php
│   │   │   ├── OperationAreaManager.php
│   │   │   ├── OperationData.php
│   │   │   └── OperationManager.php
│   │   ├── Quotations/
│   │   │   ├── QuotationManager.php      # Borradores, snapshots, totales y emisión
│   │   │   └── ...                       # Datos y cálculos de cotización
│   │   └── Suppliers/
│   │       ├── SupplierData.php
│   │       └── SupplierManager.php
│   ├── Command/
│   │   └── BootstrapSecurityCommand.php  # Bootstrap idempotente de roles/permisos/admin
│   ├── Controller/
│   │   ├── DashboardController.php
│   │   ├── SecurityController.php
│   │   └── Admin/
│   │       ├── Access/
│   │       │   ├── RoleController.php
│   │       │   └── UserController.php
│   │       ├── Audit/
│   │       │   └── AuditLogController.php
│   │       ├── Catalog/
│   │       │   └── ...                   # Categorías, unidades y productos/servicios
│   │       ├── Clients/
│   │       │   ├── ClientAddressController.php
│   │       │   ├── ClientContactController.php
│   │       │   └── ClientController.php
│   │       ├── Equipment/
│   │       │   └── EquipmentController.php
│   │       ├── Operations/
│   │       │   ├── OperationAreaController.php
│   │       │   └── OperationController.php
│   │       ├── Quotations/
│   │       │   └── QuotationController.php # Borrador, emisión y descarga de PDF
│   │       └── Suppliers/
│   │           └── SupplierController.php
│   ├── DTO/
│   │   └── Access/
│   │       ├── CreateRoleData.php
│   │       ├── CreateUserData.php
│   │       ├── ResetUserPasswordData.php
│   │       ├── UpdateRoleData.php
│   │       └── UpdateUserData.php
│   ├── Entity/
│   │   ├── Audit/
│   │   │   └── AuditLog.php
│   │   ├── Catalog/
│   │   │   └── {CommercialCategory, MeasurementUnit, CommercialItem, ItemPriceRule}.php
│   │   ├── Clients/
│   │   │   └── {Client, ClientContact, ClientAddress}.php
│   │   ├── Equipment/
│   │   │   └── Equipment.php
│   │   ├── Operations/
│   │   │   └── {OperationArea, Operation}.php
│   │   ├── Quotations/
│   │   │   └── {Quotation, QuotationItem}.php
│   │   ├── Suppliers/
│   │   │   └── Supplier.php
│   │   └── Users/
│   │       └── {User, Role, Permission}.php
│   ├── EventSubscriber/
│   │   └── Security/
│   │       └── AuthenticationAuditSubscriber.php
│   ├── Enum/
│   │   └── Equipment/
│   │       └── EquipmentStatus.php       # available, maintenance, inactive
│   ├── Form/
│   │   └── Admin/
│   │       ├── Access/                  # Formularios de usuarios y roles
│   │       ├── Catalog/                 # Categorías, unidades y productos/servicios
│   │       ├── Clients/                 # Cliente, contacto y dirección
│   │       ├── Equipment/               # Ficha técnica y operación primaria
│   │       ├── Operations/              # Área operativa y operación
│   │       ├── Quotations/              # Formulario de cotización y sus partidas
│   │       └── Suppliers/               # Catálogo de proveedores
│   ├── Repository/
│   │   ├── Audit/
│   │   │   └── AuditLogRepository.php
│   │   ├── Catalog/
│   │   │   └── ...                      # Consultas de categorías, unidades y conceptos
│   │   ├── Clients/
│   │   │   └── {Client, ClientContact, ClientAddress}Repository.php
│   │   ├── Equipment/
│   │   │   └── EquipmentRepository.php
│   │   ├── Operations/
│   │   │   └── {OperationAreaRepository, OperationRepository}.php
│   │   ├── Quotations/
│   │   │   └── ...                      # Consultas de cotizaciones y partidas
│   │   ├── Suppliers/
│   │   │   └── SupplierRepository.php
│   │   └── Users/
│   │       └── {User, Role, Permission}Repository.php
│   ├── Security/
│   │   ├── UserChecker.php
│   │   └── Voter/
│   │       └── PermissionVoter.php
│   └── Service/
│       ├── Audit/
│       │   └── AuditLogger.php
│       └── Quotations/
│           ├── QuotationFolioGenerator.php # Consecutivo anual atómico MySQL
│           └── QuotationPdfRenderer.php    # PDF de cotización emitida con Dompdf
├── templates/
│   ├── base.html.twig                   # Documento HTML raíz y assets comunes
│   ├── dashboard/
│   │   └── index.html.twig
│   ├── form/
│   │   └── printflow_theme.html.twig    # Tema único de formularios
│   ├── layouts/
│   │   ├── app.html.twig                # Shell autenticado
│   │   └── public.html.twig             # Layout para login y páginas públicas
│   ├── partials/
│   │   ├── _app_header.html.twig
│   │   ├── _app_sidebar.html.twig       # Administración, Catálogos, Comercial, Operación y Control
│   │   └── _flash_messages.html.twig
│   ├── security/
│   │   └── login.html.twig
│   └── admin/
│       ├── access/
│       │   └── {users, roles}/          # Índice, alta, edición y restablecimiento
│       ├── audit/
│       │   └── logs/
│       │       └── index.html.twig
│       ├── catalog/
│       │   ├── categories/{index, form}.html.twig
│       │   ├── items/                   # Productos/servicios y sus reglas de precio
│       │   └── units/{index, form}.html.twig
│       ├── clients/
│       │   ├── {index, form}.html.twig
│       │   ├── addresses/{index, form}.html.twig
│       │   └── contacts/{index, form}.html.twig
│       ├── equipment/
│       │   └── {index, form}.html.twig
│       ├── operations/
│       │   ├── {index, form}.html.twig
│       │   └── areas/{index, form}.html.twig
│       ├── quotations/
│       │   ├── _form.html.twig
│       │   ├── edit.html.twig
│       │   ├── index.html.twig
│       │   └── pdf.html.twig            # Documento Carta descargable
│       └── suppliers/
│           └── {index, form}.html.twig
├── tests/                               # Pruebas automatizadas actuales y futuras
├── .env                                 # Variables no secretas, incluido emisor de cotización
├── .env.local                           # Credenciales y datos reales locales; no versionar
├── composer.json                        # Dependencias PHP, incluido Dompdf
├── composer.lock                        # Versiones reproducibles
└── phpunit.xml.dist                     # Configuración de PHPUnit
```

### Notas importantes de estructura

- El árbol refleja los nombres y ubicaciones confirmados. Si un archivo ya existe con un nombre distinto en `C:\PrintFlow`, se conserva el existente y se ajusta la documentación; no se duplican capas.
- Controladores bajo `Admin` usan rutas con atributos Symfony. El prefijo de URL no implica que toda la lógica viva allí.
- Los módulos se agrupan por dominio (`Access`, `Clients`, `Catalog`, `Quotations`, `Suppliers`, `Operations` y `Equipment`) y no por tipo técnico global.
- Si un estilo puede vivir en un componente compartido, no debe ir a `styles/modules/*`.
- El layout autenticado usa el bloque `app_content`. Las vistas nuevas deben extender `layouts/app.html.twig` y definir `app_navigation_active`, `app_breadcrumb` y `app_content`.

- CommercialItem representa un producto o servicio vendible. ItemPriceRule contiene sus rangos de precio por cantidad. Una partida pertenece a QuotationItem; no existe como catálogo.
- QuotationManager concentra creación, actualización, cálculo de totales, snapshots, auditoría y emisión. El controlador no calcula importes ni asigna folios.
- QuotationFolioGenerator encapsula la secuencia anual en SQL; no existe una entidad QuotationFolioSequence.
- El PDF se genera únicamente para cotizaciones emitidas mediante QuotationPdfRenderer y templates/admin/quotations/pdf.html.twig.
- El sidebar mantiene la organización: Administración, Catálogos (Productos y servicios, Categorías comerciales y Unidades de medida), Comercial (Clientes y Cotizaciones) y Control.
- El sidebar incorpora la sección **Operación**: Proveedores, Operaciones, Áreas operativas y Equipos. Las entradas de Materiales permanecen condicionadas por permisos, pero no se habilitan hasta que ese módulo exista realmente.
- `OperationArea` agrupa las operaciones y conserva un orden global sugerido; `Operation` conserva otro orden, solo dentro de su área. Ninguno de esos órdenes es todavía una ruta obligatoria de producción.
- `Equipment` conserva una única operación primaria por la decisión vigente de negocio. El área se deriva de esa operación y no se guarda duplicada. Si en el futuro una máquina soporta varias operaciones, se incorporará una relación M:N mediante una migración nueva.
- La consulta canónica para una futura ejecución es `EquipmentRepository::findAvailableForFutureExecution()`: devuelve exclusivamente equipos `available` cuya operación y área permanezcan activas.
- Las vistas autenticadas extienden layouts/app.html.twig y definen app_navigation_active, app_breadcrumb y app_content.
---

## 5. Seguridad, autenticación y autorización

### Flujo de autenticación

1. `GET /login` muestra el formulario de `SecurityController`.
2. Symfony busca al usuario por `username` mediante el provider `App\Entity\Users\User`.
3. `UserChecker` rechaza cuentas inactivas o no aptas para iniciar sesión.
4. `form_login` valida credenciales y token CSRF `authenticate`.
5. El throttling limita a **5 intentos en 15 minutos**.
6. Tras un login correcto, `AuthenticationAuditSubscriber` actualiza `lastLoginAt` y registra `authentication.login_success`.
7. `POST /logout` invalida la sesión, redirige a login y registra `authentication.logout`.

`security.yaml` deja `PUBLIC_ACCESS` solo en `/login`; el resto de la aplicación requiere `ROLE_USER` como acceso base. La autorización funcional posterior se resuelve con permisos del voter.

### RBAC: roles y permisos

La autorización no depende únicamente de ser `ROLE_ADMIN`. Cada acción se solicita mediante `is_granted('codigo.del.permiso')` o `denyAccessUnlessGranted(...)`.

```text
users ──< user_roles >── roles ──< role_permissions >── permissions
```

- `ROLE_ADMIN` es un rol de sistema, reservado y no editable desde la UI.
- Los roles propios deben usar código `ROLE_` en mayúsculas, por ejemplo `ROLE_SALES`.
- Las asignaciones se hacen a través de `user_roles` y `role_permissions`; no se codifican permisos en un controlador.
- Cambiar roles a un usuario afecta su autorización efectiva y, según el flujo aplicado, se fuerza el cierre de su sesión para que no conserve privilegios anteriores.

### Convención de permisos

Se utilizan códigos minúsculos separados por punto. Ejemplos:

| Dominio | Permisos confirmados |
| --- | --- |
| Dashboard | `dashboard.view` |
| Usuarios | `user.view`, `user.create`, `user.update`, `user.deactivate`, `user.reset_password` |
| Roles | `role.view`, `role.manage` |
| Auditoría | `audit_log.view` |
| Clientes | `clients.view`, `clients.create`, `clients.update`, `clients.toggle_status` |
| Contactos | `clients.contacts.view`, `clients.contacts.create`, `clients.contacts.update`, `clients.contacts.toggle_status` |
| Direcciones | `clients.addresses.view`, `clients.addresses.create`, `clients.addresses.update`, `clients.addresses.toggle_status` |
| Catálogo | `catalog.view`, `catalog.categories.manage`, `catalog.units.manage` |
| Proveedores | `suppliers.view`, `suppliers.create`, `suppliers.update`, `suppliers.toggle_status` |
| Áreas operativas | `operation_areas.view`, `operation_areas.create`, `operation_areas.update`, `operation_areas.toggle_status`, `operation_areas.reorder` |
| Operaciones | `operations.view`, `operations.create`, `operations.update`, `operations.toggle_status`, `operations.reorder` |
| Equipos | `equipment.view`, `equipment.create`, `equipment.update`, `equipment.change_status` |

**Regla crítica:** el `PermissionVoter` debe aceptar permisos de profundidad variable. El patrón correcto es:

```php
return $subject === null
    && preg_match(
        '/^[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)+$/',
        $attribute,
    ) === 1;
```

No se debe volver al patrón de dos segmentos (`modulo.accion`), ya que bloquearía permisos como `clients.contacts.view` y produciría HTTP 403 aun cuando MySQL los haya asignado correctamente.

`ROLE_ADMIN` recibe los permisos completos de los cuatro catálogos operativos mediante las migraciones. `ROLE_PRODUCTION` recibe exclusivamente `operation_areas.view`, `operations.view` y `equipment.view`; por tanto puede consultar la estructura operativa pero no modificarla. No se le asigna acceso a proveedores en el alcance actual.

### Reglas de seguridad de desarrollo

- Toda mutación debe ser `POST` (o método explícito apropiado) y validar CSRF.
- Las contraseñas nunca se almacenan ni auditan en texto plano. `UserPasswordHasherInterface` genera el hash.
- El alta y el restablecimiento de contraseña requieren mínimo 12 caracteres y marcan `mustChangePassword`.
- Un controlador debe obtener al actor y comprobar que es `User` antes de llamar al manager.
- Nunca confiar en un `id` de URL sin validar pertenencia cuando hay un padre contextual; por ejemplo, una dirección debe pertenecer al `Client` de la ruta.
- Las restricciones de base de datos complementan las reglas del manager; no son reemplazo de ellas.

---

## 6. Auditoría

La bitácora es transversal y debe acompañar toda modificación administrativa relevante.

### `AuditLogger`

Ruta: `src/Service/Audit/AuditLogger.php`.

- Crea un `AuditLog` y lo deja persistido, **sin hacer `flush()`**.
- El manager/caso de uso conserva el control de la transacción completa.
- Guarda actor, acción, tipo/id lógico de entidad, valores anteriores/nuevos, IP, user agent y fecha.
- Sanea claves sensibles de forma recursiva: password, hashes, tokens y equivalentes se reemplazan por `[REDACTED]`.

### Convención de eventos

Usar acción `dominio.verbo`, entidad singular/lógica y snapshots estables. Ejemplos reales:

```text
authentication.login_success
authentication.logout
user.created / user.updated / user.activated / user.deactivated / user.password_reset
role.created / role.updated
client.created / client.updated / client.activated / client.deactivated
client_contact.created / client_contact.updated / client_contact.activated / client_contact.deactivated
client_address.created / client_address.updated / client_address.activated / client_address.deactivated
client_address.default_changed
commercial_category.created / commercial_category.updated / commercial_category.activated / commercial_category.deactivated / commercial_category.reordered
measurement_unit.created / measurement_unit.updated / measurement_unit.activated / measurement_unit.deactivated / measurement_unit.reordered
supplier.created / supplier.updated / supplier.activated / supplier.deactivated
operation_area.created / operation_area.updated / operation_area.activated / operation_area.deactivated / operation_area.reordered
operation.created / operation.updated / operation.activated / operation.deactivated / operation.reordered
equipment.created / equipment.updated / equipment.status_changed
```

La pantalla `/admin/bitacora` permite consultar acciones, actor, fecha desde/hasta y texto de búsqueda. Los filtros de fecha se reciben en `America/Mexico_City` y se convierten a UTC antes de consultar.

Los eventos de áreas y operaciones incluyen snapshots del orden activo anterior y posterior cuando hay cambio de orden o estado; los eventos de equipos registran la operación primaria, el área derivada, la ficha técnica y el estado anterior/nuevo. Las capacidades técnicas son texto de referencia y nunca se interpretan como cálculo automático.

---

## 7. Modelo de datos actual

### Diagrama Mermaid de referencia

```mermaid
erDiagram
    USERS {
        int id PK
        varchar full_name
        varchar username UK
        varchar email UK
        varchar password
        varchar phone "nullable"
        varchar avatar_path "nullable"
        boolean is_active
        boolean must_change_password
        datetime last_login_at "nullable"
        datetime created_at
        datetime updated_at
        datetime deleted_at "nullable"
    }

    ROLES {
        int id PK
        varchar code UK
        varchar name
        text description "nullable"
        boolean is_system
        boolean is_active
        datetime created_at
        datetime updated_at
    }

    PERMISSIONS {
        int id PK
        varchar code UK
        varchar module
        varchar action
        varchar name
        text description "nullable"
        boolean is_system
        boolean is_active
        datetime created_at
        datetime updated_at
    }

    USER_ROLES {
        int user_id PK, FK
        int role_id PK, FK
    }

    ROLE_PERMISSIONS {
        int role_id PK, FK
        int permission_id PK, FK
    }

    AUDIT_LOGS {
        int id PK
        int actor_id FK "nullable"
        varchar action
        varchar entity_type
        varchar entity_id "nullable"
        json old_values "nullable"
        json new_values "nullable"
        varchar ip_address "nullable"
        varchar user_agent "nullable"
        datetime created_at
    }

    CLIENT_CATEGORIES {
        int id PK
        varchar name UK
        text description "nullable"
        int display_order
        boolean is_active
        datetime created_at
        datetime updated_at
    }

    DELIVERY_ZONES {
        int id PK
        varchar name UK
        text description "nullable"
        decimal base_delivery_cost
        int display_order
        boolean is_active
        datetime created_at
        datetime updated_at
    }

    COMMERCIAL_CATEGORIES {
        int id PK
        varchar code UK
        varchar name
        text description "nullable"
        int display_order
        boolean is_active
        datetime created_at
        datetime updated_at
    }

    MEASUREMENT_UNITS {
        int id PK
        varchar code UK
        varchar name
        int display_order
        boolean is_active
        datetime created_at
        datetime updated_at
    }

    CLIENTS {
        int id PK
        int client_category_id FK "nullable"
        varchar business_name
        varchar tax_id UK "nullable"
        varchar legal_name "nullable"
        char tax_regime_code "nullable"
        char fiscal_postal_code "nullable"
        varchar billing_email "nullable"
        varchar default_cfdi_use_code "nullable"
        float default_discount_percent
        varchar email "nullable"
        varchar phone "nullable"
        text notes "nullable"
        boolean is_active
        datetime created_at
        datetime updated_at
        datetime deleted_at "nullable"
    }

    CLIENT_CONTACTS {
        int id PK
        int client_id FK
        varchar full_name
        varchar job_title "nullable"
        varchar email "nullable"
        varchar phone "nullable"
        varchar phone_extension "nullable"
        varchar mobile_phone "nullable"
        varchar personal_mobile_phone "nullable"
        varchar work_schedule "nullable"
        boolean is_primary
        int primary_client_id UK "generada"
        boolean is_active
        datetime created_at
        datetime updated_at
    }

    CLIENT_ADDRESSES {
        int id PK
        int client_id FK
        int delivery_zone_id FK "nullable"
        varchar label
        varchar recipient_name "nullable"
        varchar street
        varchar exterior_number
        varchar interior_number "nullable"
        varchar neighborhood "nullable"
        char postal_code
        varchar municipality
        varchar state
        char country_code
        text references_text "nullable"
        decimal delivery_cost
        boolean is_fiscal_address
        boolean is_delivery_address
        boolean is_default_fiscal
        boolean is_default_delivery
        int default_fiscal_client_id UK "generada"
        int default_delivery_client_id UK "generada"
        boolean is_active
        datetime created_at
        datetime updated_at
    }

    SUPPLIERS {
        int id PK
        varchar code UK
        varchar business_name
        varchar legal_name "nullable"
        varchar tax_id UK "nullable"
        varchar email "nullable"
        varchar phone "nullable"
        text notes "nullable"
        boolean is_active
        datetime deleted_at "nullable"
        datetime created_at
        datetime updated_at
    }

    OPERATION_AREAS {
        int id PK
        varchar code UK
        varchar name UK
        text description "nullable"
        int display_order
        boolean is_active
        datetime created_at
        datetime updated_at
    }

    OPERATIONS {
        int id PK
        int operation_area_id FK
        varchar code UK
        varchar name "UK dentro de área"
        text description "nullable"
        int display_order
        boolean is_active
        datetime created_at
        datetime updated_at
    }

    EQUIPMENT {
        int id PK
        int primary_operation_id FK
        varchar code UK
        varchar name
        varchar technology "nullable"
        varchar brand "nullable"
        varchar model "nullable"
        varchar serial_number UK "nullable"
        decimal usable_width_cm "nullable"
        varchar technical_capacity "nullable"
        varchar color_configuration "nullable"
        text observations "nullable"
        enum status "available|maintenance|inactive"
        datetime created_at
        datetime updated_at
    }

    USERS ||--o{ USER_ROLES : "tiene"
    ROLES ||--o{ USER_ROLES : "asigna"
    ROLES ||--o{ ROLE_PERMISSIONS : "incluye"
    PERMISSIONS ||--o{ ROLE_PERMISSIONS : "otorga"
    USERS o|--o{ AUDIT_LOGS : "actor_id · SET NULL"

    CLIENT_CATEGORIES o|--o{ CLIENTS : "clasifica"
    CLIENTS ||--o{ CLIENT_CONTACTS : "registra"
    CLIENTS ||--o{ CLIENT_ADDRESSES : "posee"
    DELIVERY_ZONES o|--o{ CLIENT_ADDRESSES : "agrupa"
    OPERATION_AREAS ||--o{ OPERATIONS : "agrupa · RESTRICT"
    OPERATIONS ||--o{ EQUIPMENT : "operación primaria · RESTRICT"
```

### Tablas y reglas relevantes

| Tabla | Propósito | Reglas/índices importantes |
| --- | --- | --- |
| `users` | Cuentas operativas | `username` y `email` únicos; baja lógica; contraseña hasheada; roles M:N |
| `roles` | Agrupación de permisos | `code` único; `ROLE_ADMIN` reservado; `is_system` protege roles internos |
| `permissions` | Capacidades atómicas del sistema | `code` único; se registra por migración, no solo por UI |
| `user_roles` | Asignación usuario–rol | PK compuesta, evita duplicados |
| `role_permissions` | Asignación rol–permiso | PK compuesta, evita duplicados |
| `audit_logs` | Historial transversal | Actor opcional; relación polimórfica lógica por `entity_type` + `entity_id`; JSON de snapshots |
| `client_categories` | Clasificación comercial del cliente | `name` único; orden visual; estado activo/inactivo |
| `delivery_zones` | Zonas de entrega configurables | `name` único; costo base de entrega, orden visual y estado activo/inactivo |
| `clients` | Entidad comercial y fiscal | RFC único si existe; categoría opcional; datos fiscales y condiciones comerciales; baja lógica mediante `is_active`/`deleted_at` |
| `client_contacts` | Personas de contacto de un cliente | FK `client_id` con `RESTRICT`; índices por cliente/activo y cliente/principal; un contacto principal activo por cliente |
| `client_addresses` | Domicilios fiscales y de entrega | FK a cliente y zona de entrega opcional; una predeterminada fiscal activa y una de entrega activa por cliente mediante columnas generadas/índices únicos |
| `commercial_categories` | Agrupación de futuros conceptos cotizables | `code` único; nombre, descripción opcional, orden visual y estado activo/inactivo |
| `measurement_units` | Unidad de venta o cálculo de futuros conceptos | `code` único; nombre, orden visual y estado activo/inactivo |
| `suppliers` | Catálogo operativo de proveedores | `code` único; RFC único solo cuando se captura; baja lógica con `is_active` y `deleted_at`; sin contactos, direcciones ni compras prematuras |
| `operation_areas` | Agrupación administrable de operaciones | `code` y nombre únicos; orden global sugerido, estado y bloqueo de desactivación cuando aún tiene operaciones activas |
| `operations` | Etapas reutilizables de ejecución | FK `operation_area_id` con `RESTRICT`; código único; nombre único por área; orden sugerido dentro de su área y estado |
| `equipment` | Ficha técnica de máquinas/equipos | FK `primary_operation_id` con `RESTRICT`; código y serie (cuando existe) únicos; estado enumerado `available`, `maintenance` o `inactive`; el área se deriva de la operación |

### Clientes

`Client` es la entidad comercial principal y actúa como padre de contactos y direcciones. Incluye nombre comercial, categoría, correo y teléfono generales, notas internas, estado y baja lógica.

También concentra la información fiscal y comercial reutilizable para futuras cotizaciones:

- Razón social.
- RFC o identificador fiscal.
- Régimen fiscal.
- Código postal fiscal.
- Correo de facturación.
- Uso CFDI predeterminado.
- Categoría comercial.
- Porcentaje de descuento predeterminado.

El RFC se normaliza a mayúsculas, los correos a minúsculas y los valores vacíos se convierten a `null`. Al desactivar al cliente, se registra `deleted_at`; al reactivarlo, se limpia. Sus timestamps son `DateTimeImmutable` en UTC y `updated_at` se actualiza mediante `PreUpdate`.

El código postal fiscal es un dato propio del cliente y no se actualiza automáticamente al crear o modificar una dirección, evitando cambios fiscales involuntarios.

Permisos: `clients.view`, `clients.create`, `clients.update`, `clients.toggle_status`.

### Categorías de cliente y zonas de entrega

`ClientCategory` permite clasificar clientes para fines comerciales y operativos. Cuenta con nombre único, descripción opcional, orden de visualización y estado activo.

`DeliveryZone` representa zonas configurables de entrega. Cada zona define un nombre único, descripción opcional, costo base de entrega, orden y estado activo. Una dirección puede asociarse opcionalmente a una zona; el costo final de entrega queda guardado en la dirección para permitir ajustes específicos por cliente o domicilio.

### Catálogo comercial: categorías y unidades

La primera subfase del catálogo está terminada. `CommercialCategory` agrupa los conceptos que después se cotizarán; `MeasurementUnit` define cómo se venden o calculan (por ejemplo, pieza, m², metro lineal o servicio). Ambos catálogos usan un `code` único, nombre, `displayOrder`, estado y timestamps; las categorías además permiten descripción opcional.

Reglas implementadas:

- La validación de código único funciona en alta y edición: al editar se excluye el propio registro mediante su identificador, por lo que solo se rechaza un código que pertenezca a otro registro.
- Las listas permiten búsqueda por código/nombre, filtros de activas, inactivas o todas, paginación y cambio de estado con `POST`, CSRF, permiso y auditoría.
- La visualización y edición se controlan mediante `catalog.view`, `catalog.categories.manage` y `catalog.units.manage`; el sidebar se muestra solo a quien tiene el permiso de consulta.
- El orden de los registros activos se puede modificar al arrastrar desde una lista sin búsqueda. No se habilita sobre resultados filtrados, páginas o registros inactivos, para evitar reordenamientos parciales o ambiguos.
- `ui--sortable` es un controlador Stimulus genérico. Envía únicamente el registro movido y sus vecinos al endpoint correspondiente, incluye un token CSRF y revierte el DOM si el servidor rechaza la operación.
- Cada manager reordena dentro de una transacción y consulta los activos con bloqueo pesimista. Revalida los vecinos, reenumera `displayOrder` y deja el snapshot anterior/nuevo en `audit_logs`.

### Proveedores operativos

`Supplier` es un dominio distinto de `Client`: representa de quién se adquieren recursos, no a quién se le vende. El catálogo actual conserva código, nombre comercial, razón social opcional, RFC opcional, correo, teléfono, notas, estado, baja lógica y fechas UTC. No replica contactos, direcciones, condiciones de compra ni precios por proveedor porque esos casos no están validados todavía.

Reglas implementadas:

- El código es único y se normaliza a mayúsculas. El RFC se normaliza a mayúsculas y solo debe ser único cuando existe; correo a minúsculas y texto vacío a `null`.
- No hay ruta ni manager de eliminación física. Desactivar establece `deleted_at`; reactivar lo limpia y conserva la identidad histórica.
- El listado permite buscar por código, razón social, RFC, correo o teléfono; filtra activos, inactivos o todos.
- Cuando el módulo de Materiales exista, su FK de proveedor principal deberá usar `RESTRICT` y el manager deberá impedir desactivar un proveedor que siga siendo principal de un material activo. Esa relación todavía no existe y no debe simularse.

### Áreas operativas y operaciones

El catálogo separa las áreas administrables de las operaciones reutilizables. Las áreas iniciales son **Preprensa**, **Impresión**, **Acabados** y **Posproducción**. Las operaciones semilla son Diseño, Impresión, Corte, Laminado, Enrollado y Entrega.

El ejemplo de lona `Diseño → Impresión → Corte → Enrollado → Entrega` se usa como guía inicial, pero no es una ruta rígida: una futura orden podrá seleccionar únicamente las operaciones necesarias. Laminado se agregó dentro de Acabados por ser la operación primaria real de la Laminadora DXL.

Reglas implementadas:

- No hay eliminación física. `operations.operation_area_id` usa `ON DELETE RESTRICT`.
- Un área no se desactiva mientras tenga operaciones activas. Una operación activa no puede crearse, reactivarse ni trasladarse a un área inactiva.
- El orden de las áreas es global; el de las operaciones se mantiene únicamente dentro de su área. Ambos reordenamientos usan `POST`, CSRF, bloqueo pesimista, validación de vecinos y auditoría. No definen el flujo productivo futuro.
- Al editar una operación y cambiar de área, una operación activa se agrega al final del área destino y se normalizan los órdenes afectados. Una operación inactiva se conserva para historial y no aparece para altas nuevas.
- `OperationRepository::findAvailableForEquipmentForm()` ofrece solo operaciones activas de áreas activas; en edición conserva visible la asignación actual incluso si ya fue inactivada, para no romper la ficha histórica.

### Equipos

`Equipment` representa la ficha técnica de una máquina. Mantiene una **operación primaria** (una sola por ahora); el área se obtiene de esa relación y por ello no puede quedar desalineada. Sus campos son código, nombre, tecnología, marca, modelo, número de serie, ancho útil en centímetros, capacidad técnica textual, configuración de color, observaciones y estado.

El ciclo permitido es:

```mermaid
stateDiagram-v2
    Disponible --> En_mantenimiento
    Disponible --> Inactivo
    En_mantenimiento --> Disponible
    En_mantenimiento --> Inactivo
    Inactivo --> Disponible
```

- `Disponible` es el único estado seleccionable para una ejecución futura. `En mantenimiento` e `Inactivo` se conservan como historia y no se eliminan físicamente.
- Al crear un equipo disponible, editarlo como disponible o reactivarlo desde inactivo, el manager exige que su operación y área estén activas.
- `technical_capacity` se conserva como texto (`15 m²/h`, `50 cm/s`, `100 lm/h`, etc.). Es una especificación consultable, no un dato para estimar tiempo, precio, capacidad comprometida ni costo.
- `equipment.primary_operation_id` usa `ON DELETE RESTRICT`, de modo que una operación referenciada por un equipo no puede eliminarse físicamente.

### Contactos de cliente

Un contacto pertenece a un solo cliente y no puede existir sin él. Los campos actuales incluyen nombre completo, puesto, correo, teléfono, extensión, móvil laboral, móvil personal, horario de atención, `is_primary`, `is_active` y fechas de creación/actualización.

Reglas de negocio:

- Un contacto inactivo no puede quedar como principal.
- Al seleccionar un nuevo contacto principal, el anterior deja de serlo de forma transaccional.
- No se registran contactos sobre clientes inactivos.
- La consulta y edición validan que el contacto corresponda al cliente indicado en la ruta.
- La base protege que solo exista un contacto principal activo por cliente.

### Direcciones de cliente

Una dirección pertenece a un cliente y puede ser fiscal, de entrega o ambas. El modelo incluye etiqueta, destinatario, calle, número exterior e interior, colonia, código postal, municipio o alcaldía, estado, país, referencias, zona de entrega, costo de entrega y banderas de uso, predeterminadas y estado.

Reglas críticas:

- Una dirección puede marcarse como domicilio fiscal, domicilio de entrega o ambos.
- Solo direcciones activas pueden ser predeterminadas.
- Marcar una dirección como predeterminada fiscal activa automáticamente su uso fiscal; lo mismo aplica para entrega.
- Por cliente puede existir una sola dirección predeterminada fiscal activa y una sola dirección predeterminada de entrega activa.
- Al asignar una nueva predeterminada, el manager retira esa condición de las demás direcciones activas del mismo tipo dentro de una transacción y registra la auditoría `client_address.default_changed`.
- La liberación temporal y el `flush()` previo evitan colisiones de índices únicos MySQL durante el reemplazo.
- Al desactivar una dirección se eliminan sus marcas de predeterminada.
- No se registran direcciones para clientes inactivos.
- El costo de entrega se conserva en la dirección, aunque provenga inicialmente del costo base de su zona.

---

## 8. Módulos implementados

### 8.1 Acceso y usuarios

Rutas principales:

| Función | Ruta | Permiso |
| --- | --- | --- |
| Login | `/login` | Pública |
| Logout | `/logout` (POST) | Sesión activa |
| Inicio | `/` | `dashboard.view` |
| Listado de usuarios | `/admin/usuarios` | `user.view` |
| Nuevo usuario | `/admin/usuarios/nuevo` | `user.create` |
| Editar usuario | `/admin/usuarios/{id}/editar` | `user.update` |
| Activar/desactivar | `/admin/usuarios/{id}/estado` (POST) | `user.deactivate` |
| Restablecer contraseña | `/admin/usuarios/{id}/restablecer-contrasena` | `user.reset_password` |

Características:1

- Búsqueda y paginación administrativa de usuarios (20 por página).
- No se permite que un usuario cambie sus propios roles desde el formulario de edición.
- Alta con contraseña temporal y obligación1 de cambio.
- Restablecimiento seguro mediante `UserPasswordHasherInterface`.
- Cambio de estado con CSRF y auditoría.

### 8.2 Roles y permisos

| Función | Ruta | Permiso |
| --- | --- | --- |
| Listado | `/admin/roles` | `role.view` |
| Crear | `/admin/roles/nuevo` | `role.manage` |
| Editar | `/admin/roles/{id}/editar` | `role.manage` |

Reglas:

- Todo rol nuevo debe incluir al menos un permiso activo.
- El código debe cumplir `ROLE_[A-Z0-9_]{3,75}`.
- No se crea ni modifica `ROLE_ADMIN` desde la UI.
- Cada modificación se registra en auditoría con la lista de permisos ordenada.

### 8.3 Bitácora

- Ruta: `/admin/bitacora`.
- Permiso: `audit_log.view`.
- Paginación de 50 registros.
- Filtros por texto, actor, desde y hasta.
- Búsqueda por acción, tipo de entidad, nombre y usuario del actor.

### 8.4 Clientes

El cliente es el padre de contactos y direcciones. El formulario de edición ofrece accesos a **Administrar contactos** y **Administrar direcciones** cuando el usuario tiene los permisos correspondientes. Esos accesos no se muestran al crear, porque aún no existe el `id` del cliente.

### 8.5 Contactos y direcciones

Rutas anidadas confirmadas:

```text
/admin/clientes/{clientId}/contactos
/admin/clientes/{clientId}/contactos/nuevo
/admin/clientes/{clientId}/contactos/{contactId}/editar
/admin/clientes/{clientId}/contactos/{contactId}/estado

/admin/clientes/{clientId}/direcciones
/admin/clientes/{clientId}/direcciones/nueva
/admin/clientes/{clientId}/direcciones/{addressId}/editar
/admin/clientes/{clientId}/direcciones/{addressId}/estado
```

Los cambios de estado se realizan por `POST`, con token CSRF específico de cada registro.

### 8.6 Catálogo comercial: bases

Rutas principales:

| Función | Ruta | Permiso |
| --- | --- | --- |
| Categorías comerciales | `/admin/catalogo/categorias` | `catalog.view` |
| Nueva categoría | `/admin/catalogo/categorias/nueva` | `catalog.categories.manage` |
| Editar categoría | `/admin/catalogo/categorias/{id}/editar` | `catalog.categories.manage` |
| Estado de categoría | `/admin/catalogo/categorias/{id}/estado` (POST) | `catalog.categories.manage` |
| Reordenar categorías | `/admin/catalogo/categorias/reordenar` (POST/AJAX) | `catalog.categories.manage` |
| Unidades de medida | `/admin/catalogo/unidades` | `catalog.view` |
| Nueva unidad | `/admin/catalogo/unidades/nueva` | `catalog.units.manage` |
| Editar unidad | `/admin/catalogo/unidades/{id}/editar` | `catalog.units.manage` |
| Estado de unidad | `/admin/catalogo/unidades/{id}/estado` (POST) | `catalog.units.manage` |
| Reordenar unidades | `/admin/catalogo/unidades/reordenar` (POST/AJAX) | `catalog.units.manage` |

La tabla muestra el handle de arrastre únicamente si el usuario puede administrar, consulta registros activos y no tiene búsqueda aplicada. SortableJS se instala con `php bin/console importmap:require sortablejs`; queda en `importmap.php` y se importa desde el controlador reutilizable `assets/controllers/ui/sortable_controller.js`. No se utiliza CDN ni se duplica JavaScript por catálogo.

### 8.7 Proveedores

| Función | Ruta | Permiso |
| --- | --- | --- |
| Listado | `/admin/proveedores` | `suppliers.view` |
| Nuevo proveedor | `/admin/proveedores/nuevo` | `suppliers.create` |
| Editar proveedor | `/admin/proveedores/{id}/editar` | `suppliers.update` |
| Activar/desactivar | `/admin/proveedores/{id}/estado` (POST) | `suppliers.toggle_status` |

El formulario y el listado reutilizan el tema y los componentes estándar. Los cambios de estado son `POST`, validan `supplier_status_{id}` y se confirman desde el controlador Stimulus reutilizable.

### 8.8 Áreas operativas y operaciones

| Función | Ruta | Permiso |
| --- | --- | --- |
| Áreas operativas | `/admin/operaciones/areas` | `operation_areas.view` |
| Nueva área | `/admin/operaciones/areas/nueva` | `operation_areas.create` |
| Editar área | `/admin/operaciones/areas/{id}/editar` | `operation_areas.update` |
| Estado de área | `/admin/operaciones/areas/{id}/estado` (POST) | `operation_areas.toggle_status` |
| Reordenar áreas | `/admin/operaciones/areas/reordenar` (POST/AJAX) | `operation_areas.reorder` |
| Operaciones | `/admin/operaciones` | `operations.view` |
| Nueva operación | `/admin/operaciones/nueva` | `operations.create` |
| Editar operación | `/admin/operaciones/{id}/editar` | `operations.update` |
| Estado de operación | `/admin/operaciones/{id}/estado` (POST) | `operations.toggle_status` |
| Reordenar operaciones del área | `/admin/operaciones/reordenar/{idArea}` (POST/AJAX) | `operations.reorder` |

Las listas ofrecen búsqueda, filtro por estado y, en Operaciones, filtro por área. El arrastre solo aparece en una lista completa de activas, sin búsqueda, y en operaciones exige seleccionar una sola área activa.

### 8.9 Equipos

| Función | Ruta | Permiso |
| --- | --- | --- |
| Listado | `/admin/equipos` | `equipment.view` |
| Nuevo equipo | `/admin/equipos/nuevo` | `equipment.create` |
| Editar equipo | `/admin/equipos/{id}/editar` | `equipment.update` |
| Cambiar estado | `/admin/equipos/{id}/estado` (POST) | `equipment.change_status` |

El listado filtra por texto, área derivada, operación primaria y estado; por defecto muestra disponibles. El cambio de estado recibe un token `equipment_status_{id}` y solo acepta las transiciones definidas por `EquipmentStatus`.

---

## 9. Vistas, UX y componentes

### Layouts

- `base.html.twig`: HTML raíz, metadatos y carga de assets.
- `layouts/public.html.twig`: usa el shell público para login y pantallas sin sesión.
- `layouts/app.html.twig`: shell autenticado. Incluye sidebar, header, mensajes flash y `<main id="main-content">`.

Una pantalla autenticada nueva debe partir de este patrón:

```twig
{% extends 'layouts/app.html.twig' %}

{% block title %}Título | PrintFlow{% endblock %}
{% block app_navigation_active %}clients{% endblock %}
{% block app_breadcrumb %}Clientes / Título{% endblock %}

{% block app_content %}
    <div class="pf-page">
        {# encabezado, tarjetas, tabla o formulario #}
    </div>1
{% endblock %}
```
1
### Sidebar y navegación

El sidebar se adapta a móvil mediante `ui--sidebar_controller.js`, backdrop y clase de estado. Cada enlace se condiciona por `is_granted(...)`; no debe mostrarse una opción que terminará en 403. Al añadir un módulo:

1. Registrar sus permisos por migración.
2. Asignarlos al administrador inicial en esa misma migración de modo idempotente.
3. Agregar navegación con el permiso de consulta apropiado.
4. Definir el `app_navigation_active` de sus vistas.

La sección **Operación** se muestra solo si la persona cuenta con al menos uno de los permisos de consulta correspondientes. Sus accesos vigentes son:

| Acceso | Ruta Symfony | Clave activa | Permiso de visibilidad |
| --- | --- | --- | --- |
| Proveedores | `admin_suppliers_index` | `suppliers` | `suppliers.view` |
| Operaciones | `admin_operations_index` | `operations` | `operations.view` |
| Áreas operativas | `admin_operation_areas_index` | `operations` | `operation_areas.view` |
| Equipos | `admin_equipment_index` | `equipment` | `equipment.view` |

Los condicionales para `materials.view` y `material_categories.view` no habilitan enlaces mientras los permisos y las rutas reales de Materiales no hayan sido incorporados.

### Formularios

El tema único es `templates/form/printflow_theme.html.twig`. Agrega clases Bootstrap (`form-control`, `form-select`, `form-check-input`, `form-check-label`) y componentes propios (`pf-form-*`).

La implementación final del bloque de checkbox debe usar `form_label` y no `parent()` dentro de `checkbox_label`:

```twig
{% block checkbox_label %}
    {% set label_attr = label_attr|merge({
        class: (label_attr.class|default('') ~ ' form-check-label')|trim
    }) %}

    {{ block('form_label') }}
{% endblock %}
```

El uso de `parent()` en ese bloque genera el error de Twig: *The template has no parent and no traits defining the `checkbox_label` block*.

### Estándares de interfaz

- Botones: `btn pf-button` con modificadores `--primary`, `--secondary`, `--danger`, `--success`, `--sm`.
- Estados: `pf-status-badge` con variante semántica.
- Contenedores: `pf-card`.
- Tablas: `pf-table`, wrapper `pf-table-responsive` y acciones `pf-table__actions`.
- Formularios: `pf-form`, `pf-form-field`, `pf-form-actions`; grids solo donde la información se beneficie de dos columnas.
- Feedback: mensajes flash incluidos por el layout y validación junto a cada campo.
- Iconos: Bootstrap Icons locales mediante `<i class="bi bi-..."></i>` y `aria-hidden="true"`.

---

## 10. Migraciones y administración de esquema

### Estado confirmado

El código vigente incorpora las siguientes migraciones de Fase 2; sus cambios ya se encuentran funcionando en local. El estado final, incluida su condición de aplicada, siempre se confirma con `php bin/console doctrine:migrations:status` antes de desplegar o crear otra migración.

| Migración | Cambio incorporado |
| --- | --- |
| `Version20260726140000` | Tabla `suppliers`, baja lógica, índices y permisos `suppliers.*`. |
| `Version20260726160000` | Tablas `operation_areas` y `operations`, áreas/operaciones semilla, permisos `operation_areas.*` y `operations.*`. |
| `Version20260726170000` | Operación `ACA-LAMINADO`, tabla `equipment`, cinco equipos semilla, estados y permisos `equipment.*`. |

No se reescribe ninguna de esas migraciones, aunque aparezca un ajuste funcional posterior. Cualquier corrección se hace en una nueva versión de Doctrine Migrations.

La migración de contactos conocida es `Version20260721223000`: crea `client_contacts`, registra los cuatro permisos `clients.contacts.*` y los asigna a `ROLE_ADMIN` usando SQL idempotente.

El lote de direcciones se incorporó con `Version20260721224500` y `Version20260721230000`; ambos están aplicados. Antes de editar una migración histórica, revisar el archivo físico y `doctrine_migration_versions`; una migración ya ejecutada no se reescribe.

### Flujo correcto para cambios de datos

1. Diseñar entidad, relación, índices y reglas de negocio.
2. Crear DTO, Form, Repository, Manager, Controller y vistas respetando capas.
3. Generar una migración nueva.
4. Revisar su SQL, incluyendo `up()` y `down()`.
5. Validar mapping, container, Twig y sintaxis.
6. Ejecutar migración solamente después de confirmar el SQL.
7. Probar escenario normal, denegación de permisos, CSRF, restricciones y auditoría.

Comandos habituales en PowerShell:

```powershell
php -l src\Ruta\Archivo.php
php bin/console doctrine:schema:validate --skip-sync
php bin/console lint:container
php bin/console lint:twig templates
php bin/console doctrine:migrations:diff
php bin/console doctrine:migrations:migrate
php bin/console cache:clear
```

No usar un dump SQL local como fuente de verdad para evolucionar producción. El esquema debe avanzar por migraciones versionadas. Un dump puede servir como respaldo o para datos semilla deliberados, no como sustituto de Doctrine Migrations.

### Patrón para permisos nuevos en una migración

Una migración de módulo debe:

- Crear/modificar tablas y FK con InnoDB/utf8mb4.
- Insertar permisos con `INSERT ... ON DUPLICATE KEY UPDATE`.
- Vincular permisos de administración con `INSERT IGNORE ... SELECT`.
- No asumir IDs fijos de rol ni de permiso.
- Declarar compatibilidad MySQL con `abortIf` cuando use SQL específico de MySQL.

---

## 11. Cómo desarrollar un módulo nuevo

### Checklist de diseño antes de escribir código

1. Definir el objetivo de negocio, usuarios responsables y estados permitidos.
2. Dibujar relaciones y decidir si los datos se eliminan, se desactivan o se conservan como historial.
3. Definir permisos granulares desde el inicio: consulta, alta, edición, cambio de estado y cualquier operación sensible.
4. Identificar qué eventos deben quedar en auditoría y el snapshot mínimo útil.
5. Elegir si el módulo vive como dominio propio (`Catalog`, `Quotes`, `Production`) o como subdominio (`Clients`).
6. Definir rutas, navegación y la experiencia de vacíos/errores.
7. Crear la migración junto con el módulo; nunca dejar permisos o esquema como paso manual posterior.

### Secuencia recomendada de implementación

```text
Entidad + migración
        ↓
Repository (si hay consultas no triviales)
        ↓
DTO + Form + validaciones
        ↓
Manager con transacción, invariantes y auditoría
        ↓
Controller con permisos/CSRF
        ↓
Twig usando layout y componentes existentes
        ↓
Sidebar condicionado por permiso
        ↓
Validaciones técnicas y pruebas funcionales
```

### Plantilla de responsabilidades para un CRUD

| Pieza | Ubicación sugerida | Contenido mínimo |
| --- | --- | --- |
| Entidad | `src/Entity/<Dominio>/` | Tabla, índices, relaciones, timestamps y normalización local |
| Repositorio | `src/Repository/<Dominio>/` | Paginación, filtros y consultas de reglas de exclusividad |
| DTO | `src/Application/<Dominio>/` o `src/DTO/<Dominio>/` | Campos de entrada y validaciones |
| Form | `src/Form/Admin/<Dominio>/` | Campos y opciones de presentación |
| Manager | `src/Application/<Dominio>/` | Crear, editar, cambiar estado, invariantes y `AuditLogger` |
| Controlador | `src/Controller/Admin/<Dominio>/` | Rutas, `is_granted`, CSRF, redirect y flash |
| Templates | `templates/admin/<dominio>/` | Índice, formulario, detalle si aplica |
| Estilos | `assets/styles/modules/<dominio>/` | Solo si los componentes existentes no alcanzan |
| Migración | `migrations/` | Esquema, permisos iniciales y asignación idempotente a admin |

### Validación mínima antes de dar por terminado un módulo

- Sintaxis PHP de todos los archivos modificados.
- `doctrine:schema:validate --skip-sync`.
- `lint:container` y `lint:twig templates`.
- Usuario sin permiso: debe obtener 403 o no ver navegación.
- Usuario con permiso: debe llegar a todas las vistas y acciones necesarias.
- Formulario inválido: muestra mensajes sin perder los datos permitidos.
- Formulario válido: persiste, muestra flash y deja auditoría.
- Mutación por POST con CSRF inválido: debe fallar.
- Regla de exclusividad / cambio de estado: debe conservar consistencia con concurrencia razonable.
- Vista móvil y escritorio: respetar sidebar y tablas responsivas.

---

## 12. Fase actual: catálogos operativos y cierre de integridad

### 12.1 Alcance realmente implementado

La Fase 2 incorporó los dominios de **Proveedores**, **Áreas operativas**, **Operaciones** y **Equipos**. Cada uno tiene entidad, repositorio, DTO, formulario, manager transaccional, controlador, vistas, navegación, permisos y eventos de auditoría.

El catálogo de **Materiales** no está implementado en el código actual. Se mantiene expresamente pendiente: no existe tabla, entidad, permiso ni navegación utilizable para simular inventario, compras, existencias o proveedores principales. Su implementación deberá iniciar con datos reales de negocio y una nueva migración; no se debe afirmar que forma parte de este cierre.

### 12.2 Datos iniciales validados

`Version20260726160000` y `Version20260726170000` incorporan datos técnicos iniciales, repetibles para una base nueva:

| Tipo | Código | Nombre / especificación clave |
| --- | --- | --- |
| Área | `PREPRESS` | Preprensa |
| Área | `PRINT` | Impresión |
| Área | `FINISH` | Acabados |
| Área | `POSTPROD` | Posproducción |
| Operación | `PRE-DISENO` | Diseño |
| Operación | `IMP-IMPRESION` | Impresión |
| Operación | `ACA-CORTE` | Corte |
| Operación | `ACA-LAMINADO` | Laminado, entre Corte y Enrollado |
| Operación | `ACA-ENROLLADO` | Enrollado |
| Operación | `POS-ENTREGA` | Entrega |
| Equipo | `EQ-IMP-SKY-COLOR` | Plotter Sky Color · 160 cm · 15 m²/h |
| Equipo | `EQ-IMP-HP-365` | Plotter HP 365 · 160 cm · 23 m²/h |
| Equipo | `EQ-ACA-MIMAKI-GC-SRIII` | Plotter Mimaki GC SRIII · 61 cm · 50 cm/s |
| Equipo | `EQ-ACA-PRIME-XL` | Plotter Prime XL · 160 cm · 70 cm/s |
| Equipo | `EQ-ACA-LAMINADORA-DXL` | Laminadora DXL · 160 cm · 100 lm/h |

Los anchos están en centímetros. La relación inicial es una operación primaria por equipo. No se registra una capacidad operativa calculable: todas las velocidades y capacidades son texto técnico consultable.

No hay proveedores semilla porque no se recibió una lista aprobada. Es correcto mantener esa tabla sin datos antes que cargar empresas ficticias. Cuando negocio entregue la lista, se cargará por el flujo administrativo o por una importación documentada, con código, razón social, RFC si aplica, contacto y estado validados.

### 12.3 Subfase 2.5 — matriz de verificación y evidencia

La aceptación de la subfase requiere ejecutar y registrar esta matriz en la base local. No se sustituye con una lectura de código.

| Control | Prueba | Resultado esperado |
| --- | --- | --- |
| Datos iniciales | Consultar áreas, operaciones y equipos semilla. | Existen 4 áreas, 6 operaciones y 5 equipos; Laminadora DXL usa Laminado / Acabados. |
| Permiso positivo | `ROLE_ADMIN` crea, edita, cambia estado y reordena según el módulo. | Acceso y mutaciones exitosas; cada una deja flash y bitácora. |
| Permiso de consulta | `ROLE_PRODUCTION` entra a Áreas, Operaciones y Equipos. | Puede filtrar/consultar; no ve ni ejecuta crear, editar, estado o reordenar. |
| Permiso negativo | Un usuario sin el permiso exacto intenta la ruta o un POST construido manualmente. | Respuesta 403 y ningún cambio persistido. |
| CSRF | Enviar un POST de estado/reordenamiento con token ausente o inválido. | Se rechaza la mutación; el estado y orden permanecen intactos. |
| Áreas/operaciones | Intentar desactivar Acabados con Corte, Laminado o Enrollado activos. | Se rechaza con mensaje de negocio. |
| Estado de operación | Desactivar una operación y crear/editar un equipo disponible. | No se ofrece la operación inactiva; servidor también la rechaza para un equipo disponible. |
| Estados de equipo | Recorrer todas las transiciones permitidas y una no permitida. | Se aceptan solo `Disponible → Mantenimiento/Inactivo`, `Mantenimiento → Disponible/Inactivo` e `Inactivo → Disponible`. |
| Selección futura | Consultar `findAvailableForFutureExecution()`. | Solo retorna equipos disponibles con operación y área activas. |
| Auditoría | Revisar la Bitácora tras altas, ediciones, cambios de estado y orden. | Actor, acción, entidad y snapshots anterior/nuevo están presentes. |
| Filtros y responsive | Probar texto, estado, área y operación; revisar móvil y escritorio. | Tabla contenida en `pf-table-responsive`, filtros preservados y sidebar navegable. |

### 12.4 Integridad, historia y límite actual

La historia está protegida hoy en dos capas:

1. La interfaz y los managers no ofrecen borrado físico para proveedores, áreas, operaciones ni equipos; se conserva estado o baja lógica.
2. La base usa `RESTRICT` en `operations.operation_area_id` y `equipment.primary_operation_id`. Una operación con equipos, o un área con operaciones, no se puede borrar físicamente por SQL sin antes resolver sus referencias.

Para una prueba no destructiva, basta comprobar las reglas `DELETE_RULE = RESTRICT` en `information_schema.REFERENTIAL_CONSTRAINTS`. No se debe intentar un `DELETE` en datos de trabajo. Cuando Fase 4 cree Órdenes y Fase 5 cree Inventario, sus FKs deberán seguir esta misma política y las vistas usarán las consultas canónicas de registros activos.

La relación futura Material–Proveedor todavía no puede comprobarse porque Materiales no existe. Al implementarla, la migración debe usar una FK restrictiva y `SupplierManager::setActive()` debe incorporar la regla que bloquee desactivar un proveedor principal de un material activo. Esta es una dependencia pendiente documentada, no una excepción que se deba ignorar.

### 12.5 Siguiente fase autorizada

No se deben construir órdenes de trabajo, movimientos de inventario, conversiones de unidades, costos automáticos ni mantenimiento histórico como continuación directa de esta subfase. El siguiente módulo debe iniciar solamente con requisitos de negocio confirmados y respetar que:

- Operaciones y equipos son catálogos de preparación, no ejecuciones.
- La disponibilidad futura se resuelve con estados y catálogos activos, no con una agenda o capacidad calculada.
- Los datos técnicos de equipo no son fórmulas de precio, tiempos prometidos ni costos de producción.
- Todo documento histórico posterior deberá guardar sus snapshots y referenciar catálogos mediante FKs restrictivas.

---

## 13. Despliegue a Hostinger

### Datos confirmados

- Hosting con SSH disponible.
- PHP CLI: `/opt/alt/php82/usr/bin/php` (**8.2.30**).
- Composer: **2.9.8**.
- Git: **2.43.7**.
- Ruta del subdominio: `/home/u692972268/domains/teramorphosis.com/public_html/printflow`.
- La aplicación Symfony no debe exponer `src/`, `config/`, `vendor/` ni `.env`; el servidor web debe servir únicamente `public/` o un bridge seguro equivalente.

### Flujo de despliegue recomendado

```text
Repositorio remoto actualizado
        ↓
git pull en el servidor
        ↓
composer install --no-dev --optimize-autoloader
        ↓
configurar .env.local de producción (APP_ENV, APP_SECRET, DATABASE_URL, etc.)
        ↓
php bin/console doctrine:migrations:migrate --no-interaction
        ↓
php bin/console cache:clear --env=prod
        ↓
php bin/console cache:warmup --env=prod
        ↓
php bin/console assets:install public --env=prod
        ↓
verificación funcional y logs
```

Usar el binario PHP de Hostinger en cada comando, por ejemplo:

```bash
/opt/alt/php82/usr/bin/php bin/console doctrine:migrations:migrate --no-interaction --env=prod
```

Después de desplegar las migraciones de Fase 2, validar como mínimo:

```bash
/opt/alt/php82/usr/bin/php bin/console doctrine:migrations:status --env=prod
/opt/alt/php82/usr/bin/php bin/console doctrine:schema:validate --skip-sync --env=prod
```

Antes de aplicar `Version20260726140000`, `Version20260726160000` o `Version20260726170000` en un ambiente con datos, respaldar la base y revisar el `--dry-run`. Las migraciones incluyen tablas, FKs, permisos y datos semilla; no deben sustituirse por inserciones SQL manuales en producción.

### Reglas de producción

- No subir `.env.local`, `var/`, `vendor/` local ni archivos de credenciales a Git.
- No deshabilitar SSL de Composer de manera permanente. Si se hizo una prueba local con certificados, revertirla.
- No ejecutar `doctrine:schema:update --force` en producción.
- Ejecutar migraciones desde el repositorio y hacer backup de MySQL antes de un cambio sensible.
- Validar permisos de escritura solo en `var/` y directorios de carga explícitos.
- Tras cambios en roles/permisos, cerrar sesión e iniciar de nuevo para verificar la autorización efectiva.
- No ejecutar los métodos `down()` de Fase 2 para “limpiar” un ambiente con historia. Los catálogos se desactivan desde la aplicación; las reversas de migración solo son adecuadas para entornos desechables y sin referencias.

---

## 14. Cambios confirmados recientes

Estas correcciones ya se probaron de forma funcional y forman parte del estado vigente:

1. **Direcciones de cliente:** contactos y direcciones funcionan correctamente.
2. **Permisos de direcciones:** los cuatro `clients.addresses.*` existen y están asignados a `ROLE_ADMIN`.
3. **Voter:** se corrigió para aceptar permisos con más de dos segmentos. Esto eliminó el 403 de direcciones para administradores.
4. **Formulario de cliente:** en edición deben mostrarse ambos accesos, contactos y direcciones, condicionados por sus permisos respectivos.
5. **Tema de formularios:** se corrigió el bloque `checkbox_label` para utilizar `block('form_label')`; los formularios de contactos y direcciones ya funcionan con checkboxes.
6. **Migraciones:** el repositorio incorpora hasta `Version20260726170000`; confirmar el estado aplicado con `doctrine:migrations:status` antes de continuar o desplegar.
7. **Diagrama:** el formato preferido es Mermaid. El archivo draw.io generado anteriormente se descartó por calidad visual y no es referencia de diseño.
8. **Notificaciones y confirmaciones:** se incorporaron Notyf y SweetAlert2 como dependencias locales gestionadas por AssetMapper. Se registraron con `importmap:require`, sus archivos viven bajo `assets/vendor/`, `importmap.php` conserva el mapeo de versiones y sus CSS se importan desde `assets/app.js`. No se usan CDN, npm ni variables globales (`window.Notyf` / `window.Swal`).
9. **Catálogo comercial — bases:** categorías comerciales y unidades de medida ya cuentan con CRUD administrativo, filtro, estado, permisos granulares, sidebar y auditoría.
10. **Validación de edición:** los DTO de categoría y unidad incluyen el identificador actual en `UniqueEntity`, evitando que un registro se considere duplicado de sí mismo al cambiar nombre, descripción u orden.
11. **Orden visual:** SortableJS se añadió mediante AssetMapper y se encapsuló en `ui--sortable`. El reordenamiento persiste desde endpoints `POST` con CSRF, autorización, transacción, bloqueo pesimista de los activos, validación de vecinos y auditoría de snapshots.
12. **Proveedores:** `Version20260726140000` crea el catálogo operativo con baja lógica, filtros, permisos `suppliers.*` y auditoría. No se incorporaron datos ficticios, contactos ni direcciones de proveedor.
13. **Áreas y operaciones:** `Version20260726160000` crea los catálogos separados, las cuatro áreas iniciales y el orden sugerido por área. La edición de operación corrige su orden al cambiar de área y nunca habilita una operación activa en un área inactiva.
14. **Equipos y Laminado:** `Version20260726170000` agrega `ACA-LAMINADO`, la ficha técnica de equipos, cinco registros del CSV de maquinaria y el estado único `available`/`maintenance`/`inactive`.
15. **Selección segura para ejecución futura:** `EquipmentRepository::findAvailableForFutureExecution()` filtra estado disponible y exige que operación y área permanezcan activas. Las operaciones inactivas no se ofrecen para equipos nuevos; al editar se conserva visible la asociación histórica actual sin habilitar nuevos usos inválidos.
16. **Correcciones de integración:** `OperationRepository` incorpora `findAllOrdered()` para los filtros de Equipos y `findAvailableForEquipmentForm()` para crear/editar su operación primaria; no se requiere una migración adicional para esos métodos.

### Estándar de feedback y acciones sensibles

La implementación queda centralizada y debe reutilizarse en los módulos futuros:

- `assets/js/ui/notifications.js`: expone `notify.success`, `notify.error`, `notify.warning` y `notify.info`. Usa una única instancia de Notyf, duración de 4.5 segundos, posición superior derecha y colores semánticos Bootstrap.
- `assets/js/ui/confirmations.js`: expone `confirmAction`, `confirmDeletion` y `showError`. La configuración base usa SweetAlert2 con botones Bootstrap, orden inverso y foco inicial en cancelar; no se crean modales específicos por pantalla.
- `assets/controllers/ui/flash_toast_controller.js`: transforma los flashes renderizados por Symfony en una notificación Notyf y después elimina el elemento HTML. El parcial conserva la alerta Bootstrap como respaldo si JavaScript no está disponible.
- `assets/controllers/ui/confirm_action_controller.js`: intercepta el envío de un formulario, solicita confirmación y solo ejecuta `requestSubmit()` cuando la persona confirma. La protección CSRF, método `POST`, ruta y lógica de servidor se conservan sin cambios.
- `assets/styles/components/sweetalert.css`: define `.pf-swal-actions` con separación de `0.75rem`, margen superior y ancho mínimo de botones. Está importado por `assets/styles/app.css`; esta regla reemplaza el uso de márgenes individuales incompatibles con `reverseButtons`.

`templates/partials/_flash_messages.html.twig` entrega al controlador el tipo y mensaje de cada flash mediante `data-ui--flash-toast-*`. Como la aplicación usa el tipo Bootstrap `danger`, el Twig lo mapea a `error`, que es el tipo reconocido por Notyf.

El mismo controlador `ui--confirm-action` ya se aplica a los formularios de cambio de estado de clientes, contactos y direcciones. Cada formulario declara únicamente sus textos contextuales mediante atributos `data-ui--confirm-action-*-value`; por ejemplo, desactivar/reactivar cliente, contacto o dirección. Cancelar no debe enviar una petición; confirmar debe preservar el `POST`, el token CSRF, la redirección y el flash de éxito/error original.

---

## 15. Riesgos y reglas para no degradar el proyecto

- No meter lógica de negocio en controladores porque al crecer clientes, cotizaciones y producción se duplicarán reglas y auditorías.
- No codificar permisos en PHP sin registrarlos mediante migración y asignación inicial controlada.
- No usar un permiso de dos segmentos como restricción del voter; el modelo de permisos ya requiere jerarquía.
- No tocar una migración ya aplicada; crear una nueva corrección.
- No eliminar físicamente clientes, usuarios, contactos o direcciones si tienen valor histórico. Usar estado/baja lógica salvo decisión explícita de negocio.
- No reconstruir layouts o estilos por pantalla: reutilizar `layouts/app.html.twig`, el tema de forms y clases `pf-*`.
- No almacenar secretos en el repositorio ni registrar datos sensibles en `audit_logs`.
- No permitir que la edición de catálogos altere documentos históricos: cotizaciones y órdenes deberán guardar snapshots del concepto, precio y condiciones con las que fueron emitidas.
- No asumir que un administrador tiene un permiso por rol simbólico. Probar `is_granted` y verificar `role_permissions` cuando haya un 403.
- No almacenar un área también en `equipment`; debe derivarse siempre de su operación primaria para impedir inconsistencias.
- No tratar `technical_capacity`, ancho útil o color como parámetros de costo, calendario o productividad hasta que negocio apruebe la fórmula, unidad, precisión y responsable del dato.
- No crear una relación M:N Equipo–Operación ni un historial de mantenimiento hasta que un caso real lo requiera. La cardinalidad vigente es una operación primaria por equipo.
- No afirmar que Materiales está cerrado: todavía no hay modelo ni FK Proveedor–Material. Al implementarlo, respetar unidades maestras, `DECIMAL`, proveedor principal opcional y referencias restrictivas.
- En flujos futuros, no consultar todos los equipos/operaciones desde un repositorio genérico; usar las consultas que filtran estado disponible y catálogos activos.

---

## 16. Comandos de referencia

```powershell
# Estado de migraciones
php bin/console doctrine:migrations:status

# Ejecutar migraciones pendientes
php bin/console doctrine:migrations:migrate

# Validaciones generales
php bin/console doctrine:schema:validate --skip-sync
php bin/console lint:container
php bin/console lint:twig templates
php bin/console cache:clear

# Validar sintaxis de un archivo
php -l src\Security\Voter\PermissionVoter.php

# Comprobar permisos de administrador en MySQL
php bin/console dbal:run-sql "SELECT r.code AS role_code, p.code AS permission_code FROM roles r INNER JOIN role_permissions rp ON rp.role_id = r.id INNER JOIN permissions p ON p.id = rp.permission_id WHERE r.code = 'ROLE_ADMIN' ORDER BY p.code"

# Verificar datos iniciales de áreas, operaciones y equipos
php bin/console dbal:run-sql "SELECT oa.code AS area_code, oa.name AS area_name, o.code AS operation_code, o.name AS operation_name, o.display_order FROM operation_areas oa LEFT JOIN operations o ON o.operation_area_id = oa.id ORDER BY oa.display_order, o.display_order"
php bin/console dbal:run-sql "SELECT e.code, e.name, e.status, o.name AS operation_name, oa.name AS area_name, e.usable_width_cm, e.technical_capacity FROM equipment e INNER JOIN operations o ON o.id = e.primary_operation_id INNER JOIN operation_areas oa ON oa.id = o.operation_area_id ORDER BY oa.display_order, o.display_order, e.name"

# Verificar permisos operativos de ROLE_ADMIN y ROLE_PRODUCTION
php bin/console dbal:run-sql "SELECT r.code AS role_code, p.code AS permission_code FROM roles r INNER JOIN role_permissions rp ON rp.role_id = r.id INNER JOIN permissions p ON p.id = rp.permission_id WHERE r.code IN ('ROLE_ADMIN', 'ROLE_PRODUCTION') AND (p.code LIKE 'suppliers.%' OR p.code LIKE 'operation_areas.%' OR p.code LIKE 'operations.%' OR p.code LIKE 'equipment.%') ORDER BY r.code, p.code"

# Verificar FKs restrictivas sin modificar datos
php bin/console dbal:run-sql "SELECT CONSTRAINT_NAME, TABLE_NAME, REFERENCED_TABLE_NAME, DELETE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME IN ('fk_operations_area', 'fk_equipment_primary_operation') ORDER BY CONSTRAINT_NAME"
```

---

## 17. Documentos relacionados

Existen como referencia previa del proyecto:

- `PrintFlow_Documentacion_Tecnica_Acceso_y_Usuarios.md` / `.docx`: detalle del flujo de acceso, usuarios y seguridad.
- `Guia_Despliegue_Produccion_PrintFlow_Hostinger.docx`: guía operativa de despliegue para Hostinger.
- `Cotizacion_Mayo_260626_134428.pdf`: propuesta de alcance original de la plataforma de cotización y trazabilidad. Es referencia de negocio, no especificación técnica definitiva.
- `PrintFlow_Contexto_Fase_2_Catalogos_Operativos_2026-07-26.md`: documento de inicio de Fase 2. Esta versión integral lo sustituye como contexto operativo vigente para Proveedores, Operaciones y Equipos; sus propuestas de Materiales se mantienen pendientes, no implementadas.

---

## 18. Mantenimiento de este documento

Actualizarlo al cerrar cada bloque funcional con:

1. Módulo y objetivo entregado.
2. Tablas, relaciones, migraciones y permisos nuevos.
3. Rutas y archivos creados/modificados.
4. Reglas de negocio no obvias.
5. Eventos de auditoría.
6. Pruebas realizadas y decisiones pendientes.

El cierre de cada fase debe distinguir siempre entre: lo que ya está implementado y probado, los datos semilla reproducibles, los datos reales validados por negocio y las dependencias que siguen pendientes. No convertir un supuesto documental en una capacidad del sistema.

Así el documento conserva contexto real sin obligar a reconstruir decisiones anteriores cada vez que se retome el desarrollo.
