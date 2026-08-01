# Configuración de correo y recuperación de contraseña

Esta guía describe cómo configurar el envío de correos mediante SMTP en PrintFlow y habilitar el flujo de recuperación de contraseña al desplegar el sistema en Hostinger o en el dominio de un cliente.

> **Importante:** nunca subas contraseñas, credenciales SMTP ni `DATABASE_URL` reales al repositorio. Las credenciales deben guardarse únicamente en `.env.local` o en variables de entorno del servidor.

---

## 1. Validar las dependencias instaladas

Desde la raíz del proyecto:

```bash
composer show symfony/mailer
composer show symfonycasts/reset-password-bundle
```

Si alguno de los paquetes no está instalado, ejecuta:

```bash
composer require symfony/mailer symfonycasts/reset-password-bundle
```

Después vuelve a validar:

```bash
composer show symfony/mailer
composer show symfonycasts/reset-password-bundle
```

Cuando Composer pregunte si deseas agregar configuración de Docker, puedes responder:

```text
n
```

Docker no es necesario para usar el SMTP de Hostinger en producción.

---

## 2. Configurar las variables privadas del servidor

Abre o crea el archivo `.env.local` en la raíz del proyecto:

```bash
nano .env.local
```

Agrega las variables adaptadas al dominio y correo del cliente:

```dotenv
APP_URL="https://dominio-del-cliente.com"

MAILER_DSN="smtps://USUARIO_CODIFICADO:CONTRASENA_CODIFICADA@smtp.hostinger.com:465"

MAILER_FROM_ADDRESS="contacto@dominio-del-cliente.com"
MAILER_FROM_NAME="Nombre de la empresa"
```

Ejemplo para PrintFlow:

```dotenv
APP_URL="https://printflow.teramorphosis.com"

MAILER_DSN="smtps://contacto%40teramorphosis.com:CONTRASENA_CODIFICADA@smtp.hostinger.com:465"

MAILER_FROM_ADDRESS="contacto@teramorphosis.com"
MAILER_FROM_NAME="PrintLab"
```

### Codificación de caracteres especiales

El usuario y la contraseña del DSN forman parte de una URL, por lo que algunos caracteres deben codificarse:

```text
@  → %40
$  → %24
#  → %23
+  → %2B
/  → %2F
:  → %3A
%  → %25
```

Ejemplo:

```text
Clave$2026
```

se convierte en:

```text
Clave%242026
```

Guarda y cierra Nano con:

```text
Ctrl + O
Enter
Ctrl + X
```

---

## 3. Configurar Symfony Mailer

Verifica que exista:

```bash
ls config/packages/mailer.yaml
```

El archivo debe contener:

```yaml
framework:
    mailer:
        dsn: '%env(MAILER_DSN)%'
```

En el archivo `.env` versionado puede conservarse únicamente un valor genérico:

```dotenv
###> symfony/mailer ###
MAILER_DSN=null://null
###< symfony/mailer ###
```

La configuración real debe permanecer en `.env.local`.

---

## 4. Configurar la URL pública

Verifica que exista:

```bash
ls config/packages/routing.yaml
```

Asegúrate de que la sección del router incluya:

```yaml
framework:
    router:
        utf8: true
        default_uri: '%env(APP_URL)%'
```

No elimines otras opciones existentes dentro de `framework.router`.

Esta configuración permite generar enlaces absolutos correctos dentro de los correos de recuperación.

---

## 5. Limpiar y validar la configuración

Ejecuta:

```bash
php bin/console cache:clear --env=prod
php bin/console lint:yaml config
php bin/console debug:config framework mailer --env=prod
```

Verifica que Symfony detecte las variables:

```bash
php bin/console debug:dotenv MAILER
```

> La salida de `debug:dotenv` puede mostrar datos sensibles. No la compartas ni la guardes en documentación pública.

También puedes limpiar nuevamente la caché con:

```bash
php bin/console cache:clear --env=prod
```

---

## 6. Probar el envío SMTP

Envía una prueba a un correo diferente al remitente:

```bash
php bin/console mailer:test correo-prueba@gmail.com \
    --from=contacto@dominio-del-cliente.com \
    --subject="Prueba SMTP de PrintFlow" \
    --body="El correo SMTP de PrintFlow quedó configurado correctamente."
```

Ejemplo usado en PrintFlow:

```bash
php bin/console mailer:test reyescamo.m@gmail.com \
    --from=contacto@teramorphosis.com \
    --subject="Prueba SMTP de PrintFlow" \
    --body="El correo SMTP de PrintFlow quedó configurado correctamente."
```

Si el mensaje no llega:

1. Verifica la contraseña y su codificación.
2. Limpia la caché de producción.
3. Revisa la carpeta de spam.
4. Ejecuta la prueba con detalle:

```bash
php bin/console mailer:test correo-prueba@gmail.com \
    --from=contacto@dominio-del-cliente.com \
    --subject="Prueba SMTP" \
    --body="Prueba" -vvv
```

---

## 7. Preparar el entorno de desarrollo para MakerBundle

En producción, los comandos `make:*` normalmente no están disponibles porque MakerBundle es una dependencia de desarrollo.

Para ejecutar el generador, usa:

```bash
APP_ENV=dev php bin/console cache:clear
APP_ENV=dev php bin/console make:reset-password
```

El entorno `dev` necesita acceso a la base de datos. Si aparece:

```text
Environment variable not found: "DATABASE_URL"
```

agrega en `.env.local` o `.env.dev.local` una conexión válida para ese servidor:

```dotenv
DATABASE_URL="mysql://USUARIO:CONTRASENA_CODIFICADA@localhost:3306/NOMBRE_BASE_DATOS?charset=utf8mb4"
```

Ejemplo de estructura:

```dotenv
DATABASE_URL="mysql://usuario:CONTRASENA@localhost:3306/base_printflow?charset=utf8mb4"
```

> No copies nombres de usuario, contraseñas ni bases de datos de otro cliente. Cada instalación debe usar sus propias credenciales.

---

## 8. Generar el módulo de recuperación

Ejecuta:

```bash
APP_ENV=dev php bin/console make:reset-password
```

Respuestas recomendadas:

```text
Ruta de redirección después del cambio:
app_login

Correo remitente:
contacto@dominio-del-cliente.com

Nombre del remitente:
Nombre de la empresa

Generar pruebas PHPUnit:
no
```

El comando genera, entre otros:

```text
src/Controller/ResetPasswordController.php
src/Entity/ResetPasswordRequest.php
src/Repository/ResetPasswordRequestRepository.php
src/Form/ResetPasswordRequestFormType.php
src/Form/ChangePasswordFormType.php
templates/reset_password/
config/packages/reset_password.yaml
```

---

## 9. Generar y aplicar la migración

Genera la migración:

```bash
APP_ENV=dev php bin/console make:migration
```

Revisa el archivo creado dentro de `migrations/`.

Para la relación con `users`, se recomienda conservar borrado en cascada:

```sql
FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
```

Después aplica la migración en producción:

```bash
php bin/console doctrine:migrations:migrate --env=prod
```

Confirma únicamente después de verificar que `DATABASE_URL` apunta a la base correcta del cliente.

---

## 10. Permitir acceso público a la recuperación

En `config/packages/security.yaml`, agrega la ruta pública antes de la regla general:

```yaml
security:
    access_control:
        - { path: ^/login$, roles: PUBLIC_ACCESS }
        - { path: ^/reset-password, roles: PUBLIC_ACCESS }
        - { path: ^/, roles: ROLE_USER }
```

El orden es importante: la regla pública debe aparecer antes de `^/`.

Limpia la caché:

```bash
php bin/console cache:clear --env=prod
```

---

## 11. Activar el enlace en el inicio de sesión

En `templates/security/login.html.twig`, utiliza:

```twig
<a
    href="{{ path('app_forgot_password_request') }}"
    class="pf-auth__recovery-link"
>
    ¿Olvidaste tu contraseña?
</a>
```

Después:

```bash
php bin/console cache:clear --env=prod
```

La pantalla generada puede probarse en:

```text
https://dominio-del-cliente.com/reset-password
```

---

## 12. Archivos que deben guardarse en Git

Deben versionarse:

```text
composer.json
composer.lock
symfony.lock
config/bundles.php
config/packages/mailer.yaml
config/packages/reset_password.yaml
config/packages/routing.yaml
config/packages/security.yaml
migrations/
src/Controller/ResetPasswordController.php
src/Entity/ResetPasswordRequest.php
src/Form/ChangePasswordFormType.php
src/Form/ResetPasswordRequestFormType.php
src/Repository/ResetPasswordRequestRepository.php
templates/reset_password/
templates/security/login.html.twig
```

También puede guardarse en `.env` el valor genérico:

```dotenv
MAILER_DSN=null://null
```

Nunca deben versionarse:

```text
.env.local
.env.prod.local
credenciales SMTP reales
DATABASE_URL real
contraseñas del correo
archivos error_log
```

Valida que `.env.local` esté ignorado:

```bash
git check-ignore .env.local
```

---

## 13. Lista de verificación final

Antes de entregar la instalación:

- El dominio público está configurado en `APP_URL`.
- El correo remitente pertenece al dominio del cliente.
- `MAILER_DSN` funciona con las credenciales actuales.
- El correo de prueba llega correctamente.
- Las rutas de recuperación son públicas.
- La migración se aplicó a la base correcta.
- El enlace “¿Olvidaste tu contraseña?” está activo.
- El enlace recibido abre el dominio de producción.
- El usuario puede establecer una contraseña nueva.
- `.env.local` no está incluido en Git.
- Ninguna contraseña aparece en documentación, commits o registros compartidos.
