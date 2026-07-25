# PrintFlow — Contexto técnico integral

> Documento vivo del proyecto. Consolida el estado funcional y las decisiones confirmadas hasta el **24 de julio de 2026**. Úsalo como referencia antes de desarrollar, revisar, desplegar o modificar módulos.
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

**Estado de implementación confirmado:** el cimiento técnico, el acceso administrativo, la auditoría y el módulo de clientes ya existen. Contactos y direcciones de cliente funcionan correctamente. El siguiente módulo acordado, aún sin iniciar, es el **catálogo de productos y servicios** (con categorías, unidades de medida y conceptos reutilizables en cotizaciones).

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
| JavaScript/CSS | AssetMapper; Bootstrap 5, Bootstrap Icons y jQuery **locales**. CSS propio con tokens y componentes; no CDN como dependencia operativa |
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
│   ├── app.js                         # Punto de entrada: importa CSS y Stimulus
│   ├── stimulus_bootstrap.js           # Registro de controladores Stimulus
│   ├── controllers/
│   │   └── ui/sidebar_controller.js    # Apertura/cierre responsivo del sidebar
│   └── styles/
│       ├── app.css                     # Índice de estilos de la aplicación
│       ├── foundation/                 # Tokens, base y ajustes Bootstrap
│       │   ├── tokens.css              # Variables de color, espaciado, fuentes, radios
│       │   ├── base.css                # Normalización/base visual
│       │   └── bootstrap-overrides.css # Ajustes globales de Bootstrap
│       ├── layout/
│       │   ├── public-shell.css        # Shell de pantallas públicas (login)
│       │   └── app-shell.css           # Grid app, contenido y responsive
│       ├── components/                 # Componentes compartidos
│       │   ├── button.css
│       │   ├── status-badge.css
│       │   ├── card.css
│       │   ├── page-header.css
│       │   ├── sidebar.css
│       │   ├── flash.css
│       │   ├── form.css
│       │   └── table.css
│       └── modules/                    # Estilos exclusivos cuando un módulo los justifica
│           ├── users/
│           ├── roles/
│           ├── audit-log/
│           ├── dashboard/
│           ├── security/
│           └── clients/
├── config/
│   ├── packages/
│   │   ├── doctrine.yaml               # Conexión y mapeo Doctrine
│   │   ├── doctrine_migrations.yaml    # Namespace/ruta de migraciones
│   │   ├── framework.yaml              # APP_SECRET, sesiones y modo test
│   │   ├── security.yaml               # Provider, firewall, login, logout, acceso
│   │   ├── twig.yaml                   # Configuración Twig
│   │   └── ...
│   ├── routes/                         # Rutas, cuando no se usan atributos
│   ├── services.yaml                   # Autowiring/autoconfigure de servicios
│   └── bundles.php
├── migrations/
│   ├── Version20260719164200.php       # Lote inicial: acceso, RBAC, auditoría
│   ├── Version20260721223000.php       # Contactos de cliente + permisos
│   ├── Version20260721224500.php       # Parte del lote de direcciones; verificar código
│   └── Version20260721230000.php       # Última aplicada: lote final de direcciones/permisos
├── public/
│   ├── index.php                       # Front controller Symfony
│   ├── build/                           # Assets publicados por AssetMapper
│   └── vendor/                          # Bootstrap, jQuery e iconos locales, si aplica
├── src/
│   ├── Application/
│   │   ├── Access/
│   │   │   ├── UserManager.php
│   │   │   └── RoleManager.php
│   │   └── Clients/
│   │       ├── ClientData.php
│   │       ├── ClientManager.php
│   │       ├── ClientContactData.php
│   │       ├── ClientContactManager.php
│   │       ├── ClientAddressData.php
│   │       └── ClientAddressManager.php
│   ├── Command/
│   │   └── BootstrapSecurityCommand.php # Bootstrap idempotente de roles/permisos/admin
│   ├── Controller/
│   │   ├── DashboardController.php
│   │   ├── SecurityController.php
│   │   └── Admin/
│   │       ├── Access/
│   │       │   ├── UserController.php
│   │       │   └── RoleController.php
│   │       ├── Audit/
│   │       │   └── AuditLogController.php
│   │       └── Clients/
│   │           ├── ClientController.php
│   │           ├── ClientContactController.php
│   │           └── ClientAddressController.php
│   ├── DTO/
│   │   └── Access/
│   │       ├── CreateUserData.php
│   │       ├── UpdateUserData.php
│   │       ├── ResetUserPasswordData.php
│   │       ├── CreateRoleData.php
│   │       └── UpdateRoleData.php
│   ├── Entity/
│   │   ├── Audit/AuditLog.php
│   │   ├── Users/{User,Role,Permission}.php
│   │   └── Clients/{Client,ClientContact,ClientAddress}.php
│   ├── EventSubscriber/
│   │   └── Security/AuthenticationAuditSubscriber.php
│   ├── Form/
│   │   └── Admin/
│   │       ├── Access/                 # Forms de usuarios y roles
│   │       └── Clients/                # ClientType, ClientContactType, ClientAddressType
│   ├── Repository/
│   │   ├── Audit/AuditLogRepository.php
│   │   ├── Users/{User,Role,Permission}Repository.php
│   │   └── Clients/{Client,ClientContact,ClientAddress}Repository.php
│   ├── Security/
│   │   ├── UserChecker.php
│   │   └── Voter/PermissionVoter.php
│   └── Service/
│       └── Audit/AuditLogger.php
├── templates/
│   ├── base.html.twig                  # Documento HTML raíz, assets y bloques comunes
│   ├── layouts/
│   │   ├── public.html.twig             # Layout para login/páginas públicas
│   │   └── app.html.twig                # Shell autenticado: sidebar, header, flashes, main
│   ├── partials/
│   │   ├── _app_sidebar.html.twig
│   │   ├── _app_header.html.twig
│   │   └── _flash_messages.html.twig
│   ├── form/printflow_theme.html.twig  # Tema único de formularios
│   ├── security/login.html.twig
│   ├── dashboard/index.html.twig
│   └── admin/
│       ├── access/{users,roles}/        # Índice, alta, edición, reset de contraseña
│       ├── audit/logs/index.html.twig
│       └── clients/
│           ├── {index,form}.html.twig
│           ├── contacts/{index,form}.html.twig
│           └── addresses/{index,form}.html.twig
├── tests/                               # Pruebas automatizadas actuales y futuras
├── .env                                 # Valores no secretos por entorno (no credenciales)
├── .env.local                           # Credenciales/configuración local, no versionar
├── composer.json                        # Dependencias PHP y scripts
├── composer.lock                        # Versiones reproducibles
└── phpunit.xml.dist                     # Configuración de pruebas
```

### Notas importantes de estructura

- El árbol refleja los nombres y ubicaciones confirmados. Si un archivo ya existe con un nombre distinto en `C:\PrintFlow`, se conserva el existente y se ajusta la documentación; no se duplican capas.
- Controladores bajo `Admin` usan rutas con atributos Symfony. El prefijo de URL no implica que toda la lógica viva allí.
- Los módulos se agrupan por dominio (`Access`, `Clients`) y no por tipo técnico global.
- Si un estilo puede vivir en un componente compartido, no debe ir a `styles/modules/*`.
- El layout autenticado usa el bloque `app_content`. Las vistas nuevas deben extender `layouts/app.html.twig` y definir `app_navigation_active`, `app_breadcrumb` y `app_content`.

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

**Regla crítica:** el `PermissionVoter` debe aceptar permisos de profundidad variable. El patrón correcto es:

```php
return $subject === null
    && preg_match(
        '/^[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)+$/',
        $attribute,
    ) === 1;
```

No se debe volver al patrón de dos segmentos (`modulo.accion`), ya que bloquearía permisos como `clients.contacts.view` y produciría HTTP 403 aun cuando MySQL los haya asignado correctamente.

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
```

La pantalla `/admin/bitacora` permite consultar acciones, actor, fecha desde/hasta y texto de búsqueda. Los filtros de fecha se reciben en `America/Mexico_City` y se convierten a UTC antes de consultar.

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

    CLIENTS {
        int id PK
        varchar business_name
        varchar tax_id UK "nullable"
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
        boolean is_primary
        boolean is_active
        datetime created_at
        datetime updated_at
    }

    CLIENT_ADDRESSES {
        int id PK
        int client_id FK
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
        boolean is_fiscal_address
        boolean is_delivery_address
        boolean is_default_fiscal
        boolean is_default_delivery
        boolean is_active
        int default_fiscal_client_id UK "generada"
        int default_delivery_client_id UK "generada"
        datetime created_at
        datetime updated_at
    }

    USERS ||--o{ USER_ROLES : "tiene"
    ROLES ||--o{ USER_ROLES : "asigna"
    ROLES ||--o{ ROLE_PERMISSIONS : "incluye"
    PERMISSIONS ||--o{ ROLE_PERMISSIONS : "otorga"
    USERS o|--o{ AUDIT_LOGS : "actor_id · SET NULL"
    CLIENTS ||--o{ CLIENT_CONTACTS : "registra"
    CLIENTS ||--o{ CLIENT_ADDRESSES : "posee"
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
| `clients` | Entidad comercial | `tax_id` único si existe; baja lógica mediante `is_active`/`deleted_at`; índice por estado/nombre |
| `client_contacts` | Personas de un cliente | FK `client_id` con `RESTRICT`; índices por cliente/activo y cliente/principal |
| `client_addresses` | Direcciones fiscales y de entrega | FK a cliente; una predeterminada fiscal y una de entrega activas por cliente mediante columnas generadas/índices únicos |

### Clientes

`Client` normaliza el RFC/identificador fiscal a mayúsculas, el correo a minúsculas y convierte vacíos a `null`. Al desactivar, marca `deleted_at`; al reactivar, lo limpia. Sus timestamps son `DateTimeImmutable` en UTC y `updated_at` se actualiza mediante `PreUpdate`.

Permisos: `clients.view`, `clients.create`, `clients.update`, `clients.toggle_status`.

### Contactos de cliente

Un contacto pertenece a un solo cliente y no puede existir sin él. Los campos actuales son nombre completo, puesto, correo, teléfono, `is_primary`, `is_active`, fechas de creación/actualización.

Reglas de negocio esperadas y aplicadas por el manager:

- Un contacto inactivo no puede quedar como principal.
- Al seleccionar un nuevo contacto principal, el anterior debe dejar de serlo de forma transaccional.
- No se registran contactos sobre clientes inactivos.
- La consulta/edición valida que el contacto corresponda al cliente indicado en la ruta.

### Direcciones de cliente

Una dirección puede ser fiscal, de entrega o ambas. El modelo incluye etiqueta, destinatario, calle, exterior/interior, colonia, CP, municipio/alcaldía, estado, país, referencias y banderas de estado/uso.

Reglas críticas:

- Solo direcciones activas pueden ser predeterminadas.
- Por cliente puede existir **una** predeterminada fiscal activa y **una** predeterminada de entrega activa.
- Si una dirección se marca predeterminada, el manager retira ese carácter de las demás antes de aplicar el cambio y deja auditoría `client_address.default_changed`.
- La liberación temporal y el `flush()` previo evitan colisiones de índices únicos MySQL durante el reemplazo de predeterminadas.
- No se registran direcciones para clientes inactivos.

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

Características:

- Búsqueda y paginación administrativa de usuarios (20 por página).
- No se permite que un usuario cambie sus propios roles desde el formulario de edición.
- Alta con contraseña temporal y obligación de cambio.
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
    </div>
{% endblock %}
```

### Sidebar y navegación

El sidebar se adapta a móvil mediante `ui--sidebar_controller.js`, backdrop y clase de estado. Cada enlace se condiciona por `is_granted(...)`; no debe mostrarse una opción que terminará en 403. Al añadir un módulo:

1. Registrar sus permisos por migración.
2. Asignarlos al administrador inicial en esa misma migración de modo idempotente.
3. Agregar navegación con el permiso de consulta apropiado.
4. Definir el `app_navigation_active` de sus vistas.

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

El estado más reciente verificado en local fue:

```text
Database: printflow_app
Executed migrations: 5
Current / Latest: DoctrineMigrations\Version20260721230000
Previous: DoctrineMigrations\Version20260721224500
```

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

## 12. Próximo bloque planificado: catálogo de productos y servicios

No está iniciado. El siguiente bloque debe ser el catálogo que alimente después el cotizador, no el cotizador directamente.

### Alcance propuesto para su diseño

- Categorías de productos/servicios.
- Unidades de medida.
- Conceptos vendibles reutilizables.
- Estado activo/inactivo para no alterar cotizaciones históricas.
- Base para reglas de precio, pero sin precipitar una estructura de costos que aún no se haya levantado con el negocio.

### Decisiones que deben tomarse antes de implementarlo

- Si producto y servicio son la misma entidad con un tipo, o catálogos distintos.
- Qué campos intervienen realmente en una cotización (medidas, cantidades, acabados, material, operación, desperdicio, etc.).
- Si los precios son fijos, por unidad, por rangos o por fórmula.
- Cómo se conservará una cotización histórica cuando el catálogo cambie: por snapshot de concepto/precio, nunca por lectura retroactiva del catálogo vigente.
- Permisos y quién puede modificar precios.

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

### Reglas de producción

- No subir `.env.local`, `var/`, `vendor/` local ni archivos de credenciales a Git.
- No deshabilitar SSL de Composer de manera permanente. Si se hizo una prueba local con certificados, revertirla.
- No ejecutar `doctrine:schema:update --force` en producción.
- Ejecutar migraciones desde el repositorio y hacer backup de MySQL antes de un cambio sensible.
- Validar permisos de escritura solo en `var/` y directorios de carga explícitos.
- Tras cambios en roles/permisos, cerrar sesión e iniciar de nuevo para verificar la autorización efectiva.

---

## 14. Cambios confirmados recientes

Estas correcciones ya se probaron de forma funcional y forman parte del estado vigente:

1. **Direcciones de cliente:** contactos y direcciones funcionan correctamente.
2. **Permisos de direcciones:** los cuatro `clients.addresses.*` existen y están asignados a `ROLE_ADMIN`.
3. **Voter:** se corrigió para aceptar permisos con más de dos segmentos. Esto eliminó el 403 de direcciones para administradores.
4. **Formulario de cliente:** en edición deben mostrarse ambos accesos, contactos y direcciones, condicionados por sus permisos respectivos.
5. **Tema de formularios:** se corrigió el bloque `checkbox_label` para utilizar `block('form_label')`; los formularios de contactos y direcciones ya funcionan con checkboxes.
6. **Migraciones:** la base local está al día en `Version20260721230000`.
7. **Diagrama:** el formato preferido es Mermaid. El archivo draw.io generado anteriormente se descartó por calidad visual y no es referencia de diseño.
8. **Notificaciones y confirmaciones:** se incorporaron Notyf y SweetAlert2 como dependencias locales gestionadas por AssetMapper. Se registraron con `importmap:require`, sus archivos viven bajo `assets/vendor/`, `importmap.php` conserva el mapeo de versiones y sus CSS se importan desde `assets/app.js`. No se usan CDN, npm ni variables globales (`window.Notyf` / `window.Swal`).

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
```

---

## 17. Documentos relacionados

Existen como referencia previa del proyecto:

- `PrintFlow_Documentacion_Tecnica_Acceso_y_Usuarios.md` / `.docx`: detalle del flujo de acceso, usuarios y seguridad.
- `Guia_Despliegue_Produccion_PrintFlow_Hostinger.docx`: guía operativa de despliegue para Hostinger.
- `Cotizacion_Mayo_260626_134428.pdf`: propuesta de alcance original de la plataforma de cotización y trazabilidad. Es referencia de negocio, no especificación técnica definitiva.

---

## 18. Mantenimiento de este documento

Actualizarlo al cerrar cada bloque funcional con:

1. Módulo y objetivo entregado.
2. Tablas, relaciones, migraciones y permisos nuevos.
3. Rutas y archivos creados/modificados.
4. Reglas de negocio no obvias.
5. Eventos de auditoría.
6. Pruebas realizadas y decisiones pendientes.

Así el documento conserva contexto real sin obligar a reconstruir decisiones anteriores cada vez que se retome el desarrollo.
