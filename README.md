# PrintFlow

Proyecto base en **Symfony 7.4 LTS** preparado para validar compatibilidad técnica con un entorno de hosting compartido, tomando como referencia un despliegue futuro en **Hostinger Business**.

El objetivo de esta primera versión no es construir todavía los módulos productivos, sino dejar un esqueleto técnico mínimo, estable y verificable para confirmar que Symfony puede ejecutarse correctamente antes de avanzar con el desarrollo formal de la plataforma.

---

## 1. Objetivo del repositorio

Este repositorio contiene la preparación inicial del proyecto **PrintFlow**, una plataforma web orientada a procesos de cotización, trazabilidad productiva y administración interna.

La primera fase del desarrollo busca validar:

- Creación de un proyecto Symfony desde cero.
- Compatibilidad con PHP 8.2.
- Ejecución local mediante servidor PHP embebido.
- Ejecución local mediante Apache y dominio virtual.
- Preparación básica para despliegue posterior en Hostinger.
- Uso de Composer como gestor de dependencias.
- Configuración mínima de rutas, vistas y health check.

---

## 2. Stack técnico base

| Componente | Versión / Uso |
|---|---|
| PHP | 8.2.x |
| Symfony | 7.4 LTS |
| Composer | 2.x |
| Servidor local | Apache 2.4 / PHP built-in server |
| Motor de vistas | Twig |
| Sistema operativo de desarrollo | Windows |
| Hosting objetivo | Hostinger Business |
| Base de datos futura | MySQL / MariaDB |

> Nota técnica: se utiliza Symfony 7.4 porque permite trabajar con PHP 8.2 o superior, lo cual mantiene una base compatible con el entorno objetivo del hosting y evita depender de versiones más recientes de PHP que podrían no estar disponibles en el servidor final.

---

## 3. Estructura recomendada de carpetas en Windows

Durante la preparación local se propone trabajar con las siguientes rutas:

```text
C:\tools\php82
C:\Apache24
C:\dev
C:\PrintFlow
```

Crear carpetas base:

```powershell
mkdir C:\tools
mkdir C:\dev
```

La carpeta del proyecto queda en:

```text
C:\PrintFlow
```

---

## 4. Requisitos previos

Antes de ejecutar el proyecto, el equipo local debe contar con:

- PHP 8.2 Thread Safe.
- Apache 2.4 para Windows.
- Composer 2.x.
- Git.
- Visual Studio Code o editor equivalente.
- Extensiones PHP necesarias habilitadas.

Extensiones PHP requeridas o recomendadas:

```ini
extension=curl
extension=fileinfo
extension=intl
extension=mbstring
extension=mysqli
extension=openssl
extension=pdo_mysql
extension=zip
```

Configuración recomendada en `php.ini`:

```ini
extension_dir = "C:\tools\php82\ext"
date.timezone = America/Mexico_City
memory_limit = 512M
```

---

## 5. Instalación de PHP 8.2

Para desarrollo local con Apache en Windows se recomienda usar PHP **Thread Safe**, ya que Apache carga PHP como módulo mediante `php8apache2_4.dll`.

Ruta esperada:

```text
C:\tools\php82
```

Archivos importantes que deben existir:

```text
C:\tools\php82\php.exe
C:\tools\php82\php.ini
C:\tools\php82\php8apache2_4.dll
C:\tools\php82\ext\
```

Validar instalación:

```powershell
php -v
where.exe php
```

La ruta devuelta por `where.exe php` debe apuntar a:

```text
C:\tools\php82\php.exe
```

---

## 6. Configuración de certificados para Composer

Si Composer muestra un error similar a:

```text
OpenSSL failed with a 'certificate verify failed' error.
```

no se recomienda deshabilitar SSL como solución permanente. La solución correcta es configurar el archivo de certificados raíz `cacert.pem`.

Crear carpeta:

```powershell
mkdir C:\tools\certs
```

Guardar el certificado en:

```text
C:\tools\certs\cacert.pem
```

Configurar en `php.ini`:

```ini
curl.cainfo = "C:\tools\certs\cacert.pem"
openssl.cafile = "C:\tools\certs\cacert.pem"
```

Validar qué `php.ini` está usando PHP:

```powershell
php --ini
```

Validar configuración de OpenSSL:

```powershell
php -r "var_dump(openssl_get_cert_locations());"
```

Debe aparecer la ruta:

```text
C:\tools\certs\cacert.pem
```

---

## 7. Instalación de Apache 2.4

Ruta esperada:

```text
C:\Apache24
```

Archivos importantes:

```text
C:\Apache24\bin\httpd.exe
C:\Apache24\conf\httpd.conf
C:\Apache24\htdocs\
```

En `C:\Apache24\conf\httpd.conf`, validar:

```apache
Define SRVROOT "c:/Apache24"
ServerName localhost:80
```

Habilitar módulos requeridos:

```apache
LoadModule rewrite_module modules/mod_rewrite.so
LoadModule headers_module modules/mod_headers.so
```

Integrar PHP con Apache:

```apache
LoadModule php_module "C:/tools/php82/php8apache2_4.dll"
PHPIniDir "C:/tools/php82"

<FilesMatch \.php$>
    SetHandler application/x-httpd-php
</FilesMatch>

DirectoryIndex index.php index.html
```

Validar sintaxis:

```powershell
cd C:\Apache24\bin
.\httpd.exe -t
```

Resultado esperado:

```text
Syntax OK
```

Instalar Apache como servicio:

```powershell
.\httpd.exe -k install -n "Apache24"
.\httpd.exe -k start -n "Apache24"
```

---

## 8. Prueba PHP con Apache

Crear archivo temporal:

```text
C:\Apache24\htdocs\info.php
```

Contenido:

```php
<?php
phpinfo();
```

Abrir en navegador:

```text
http://localhost/info.php
```

Si se muestra la información de PHP, entonces Apache está ejecutando PHP correctamente.

Después de validar, eliminar el archivo:

```powershell
Remove-Item C:\Apache24\htdocs\info.php
```

> Importante: `phpinfo()` no debe quedar publicado en ambientes reales porque expone información sensible del servidor.

---

## 9. Instalación de Composer

Composer debe quedar disponible globalmente en PowerShell.

Validar instalación:

```powershell
composer -V
```

Resultado esperado:

```text
Composer version 2.x.x
```

Si el instalador gráfico falla por certificados, se puede usar instalación manual:

```powershell
mkdir C:\tools\composer
Invoke-WebRequest -Uri "https://getcomposer.org/download/latest-stable/composer.phar" -OutFile "C:\tools\composer\composer.phar"
```

Crear archivo:

```text
C:\tools\composer\composer.bat
```

Contenido:

```bat
@echo off
php "C:\tools\composer\composer.phar" %*
```

Agregar al `Path` de Windows:

```text
C:\tools\composer
```

Cerrar y abrir PowerShell nuevamente, después validar:

```powershell
composer -V
```

---

## 10. Extensiones recomendadas para Visual Studio Code

Extensiones útiles para desarrollo Symfony:

- PHP Intelephense
- Twig Language 2
- GitLens

Estas extensiones no son obligatorias, pero ayudan a trabajar con autocompletado, navegación de clases, plantillas Twig y control de versiones.

---

## 11. Verificación final del ambiente local

Ejecutar:

```powershell
php -v
composer -V
git --version
```

Validar módulos PHP:

```powershell
php -m
```

Confirmar que existan:

```text
curl
fileinfo
intl
mbstring
mysqli
openssl
pdo_mysql
zip
```

Validar Apache:

```powershell
cd C:\Apache24\bin
.\httpd.exe -t
```

Resultado esperado:

```text
Syntax OK
```

---

## 12. Creación del proyecto Symfony

### 12.1 Caso A: carpeta vacía

Si `C:\PrintFlow` está completamente vacía:

```powershell
cd C:\PrintFlow
composer create-project symfony/skeleton:"7.4.*" .
```

### 12.2 Caso B: la carpeta ya contiene `.git`

Si la carpeta ya tiene repositorio Git, Composer no permite crear el proyecto directamente con `.` porque la carpeta no está vacía. En ese caso se crea el proyecto en una carpeta temporal y luego se mueve el contenido:

```powershell
cd C:\PrintFlow

composer create-project symfony/skeleton:"7.4.*" _symfony_tmp

Get-ChildItem -Force .\_symfony_tmp | Move-Item -Destination . -Force

Remove-Item -Recurse -Force .\_symfony_tmp
```

Validar estructura:

```powershell
dir -Force
```

Estructura esperada:

```text
C:\PrintFlow
├── .git
├── bin
├── config
├── public
├── src
├── var
├── vendor
├── .env
├── .env.dev
├── .gitignore
├── composer.json
├── composer.lock
└── symfony.lock
```

---

## 13. Bloqueo de compatibilidad PHP/Symfony

Para evitar que Composer instale dependencias incompatibles con PHP 8.2:

```powershell
composer config platform.php 8.2.0
composer config extra.symfony.require "7.4.*"
```

Esto ayuda a mantener el proyecto alineado con el objetivo de compatibilidad del hosting.

---

## 14. Dependencias mínimas del proyecto

Instalar paquetes básicos para aplicación web con vistas:

```powershell
composer require twig symfony/asset symfony/apache-pack
```

Paquetes instalados:

| Paquete | Uso |
|---|---|
| `twig` | Motor de plantillas HTML |
| `symfony/asset` | Gestión de assets públicos |
| `symfony/apache-pack` | Soporte `.htaccess` para Apache |

---

## 15. Rutas mínimas sugeridas

Para validar rápidamente que Symfony está funcionando, se recomienda tener una ruta principal y una ruta de diagnóstico.

Archivo sugerido:

```text
src/Controller/HomeController.php
```

Ejemplo:

```php
<?php

namespace App\Controller;

use DateTimeImmutable;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Attribute\Route;

final class HomeController extends AbstractController
{
    #[Route('/', name: 'app_home', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('home/index.html.twig', [
            'app_name' => 'PrintFlow',
            'php_version' => PHP_VERSION,
            'symfony_version' => Kernel::VERSION,
            'environment' => $this->getParameter('kernel.environment'),
        ]);
    }

    #[Route('/health', name: 'app_health', methods: ['GET'])]
    public function health(): JsonResponse
    {
        return $this->json([
            'status' => 'ok',
            'app' => 'PrintFlow',
            'php_version' => PHP_VERSION,
            'symfony_version' => Kernel::VERSION,
            'environment' => $this->getParameter('kernel.environment'),
            'timestamp' => (new DateTimeImmutable())->format(DATE_ATOM),
        ]);
    }
}
```

---

## 16. Ejecución local con servidor PHP embebido

Validar información del proyecto:

```powershell
php bin/console about
```

Levantar servidor local:

```powershell
php -S 127.0.0.1:8000 -t public
```

Abrir en navegador:

```text
http://127.0.0.1:8000/
```

Ruta de diagnóstico:

```text
http://127.0.0.1:8000/health
```

Respuesta esperada:

```json
{
  "status": "ok",
  "app": "PrintFlow",
  "php_version": "8.2.x",
  "symfony_version": "7.4.x",
  "environment": "dev"
}
```

---

## 17. Configuración de Apache para dominio local

Dominio local sugerido:

```text
printflow.test
```

### 17.1 Habilitar Virtual Hosts

Editar:

```text
C:\Apache24\conf\httpd.conf
```

Buscar:

```apache
#Include conf/extra/httpd-vhosts.conf
```

Dejar así:

```apache
Include conf/extra/httpd-vhosts.conf
```

### 17.2 Configurar VirtualHost

Editar:

```text
C:\Apache24\conf\extra\httpd-vhosts.conf
```

Agregar:

```apache
<VirtualHost *:80>
    ServerName printflow.test
    DocumentRoot "C:/PrintFlow/public"

    <Directory "C:/PrintFlow/public">
        AllowOverride All
        Require all granted
        DirectoryIndex index.php
    </Directory>

    ErrorLog "logs/printflow-error.log"
    CustomLog "logs/printflow-access.log" common
</VirtualHost>
```

### 17.3 Configurar archivo hosts

Abrir Bloc de notas como administrador y editar:

```text
C:\Windows\System32\drivers\etc\hosts
```

Agregar:

```text
127.0.0.1 printflow.test
```

### 17.4 Reiniciar Apache

```powershell
cd C:\Apache24\bin
.\httpd.exe -k restart -n "Apache24"
```

Abrir:

```text
http://printflow.test
```

---

## 18. Comandos útiles de desarrollo

Limpiar caché:

```powershell
php bin/console cache:clear
```

Calentar caché en producción:

```powershell
php bin/console cache:warmup --env=prod
```

Listar rutas:

```powershell
php bin/console debug:router
```

Ver variables de entorno cargadas:

```powershell
php bin/console debug:dotenv
```

Ver configuración PHP utilizada por consola:

```powershell
php --ini
```

Ver módulos PHP activos:

```powershell
php -m
```

---

## 19. Preparación para Hostinger

Esta sección corresponde a una fase posterior, cuando se cuente con acceso al panel y credenciales del hosting.

Puntos a validar en Hostinger:

- Versión PHP disponible para el sitio.
- Acceso SSH.
- Comando disponible para Composer (`composer` o `composer2`).
- Directorio raíz real del dominio o subdominio.
- Posibilidad de apuntar el dominio a `public/`.
- Permisos de escritura para `var/cache` y `var/log`.
- Variables de entorno mediante `.env.local` o configuración del panel.
- Compatibilidad con MySQL/MariaDB.
- Límites de memoria y tiempo de ejecución.

Comando esperado para producción:

```bash
composer2 install --no-dev --optimize-autoloader
php bin/console cache:clear --env=prod
php bin/console cache:warmup --env=prod
```

La carpeta pública del proyecto debe ser:

```text
public/
```

Por seguridad, el servidor web no debe exponer directamente:

```text
.env
.env.local
src/
config/
vendor/
var/
composer.json
composer.lock
```

---

## 20. Variables de entorno

Symfony crea por defecto un archivo `.env`. Para valores locales o sensibles se debe usar:

```text
.env.local
```

`.env.local` no debe versionarse en Git.

Ejemplo futuro:

```env
APP_ENV=dev
APP_SECRET=change_me
DATABASE_URL="mysql://user:password@127.0.0.1:3306/printflow?serverVersion=8.0&charset=utf8mb4"
```

En producción:

```env
APP_ENV=prod
APP_DEBUG=0
```

---

## 21. Seguridad básica

Buenas prácticas iniciales:

- No versionar `.env.local`.
- No dejar archivos `phpinfo()` publicados.
- No deshabilitar SSL de Composer como solución permanente.
- No exponer carpetas internas del proyecto desde Apache.
- Usar `public/` como único document root.
- Mantener `composer.lock` versionado.
- Ejecutar `composer audit` periódicamente.
- Separar credenciales de desarrollo y producción.

---

## 22. Flujo Git recomendado

Inicializar repositorio si todavía no existe:

```powershell
git init
```

Revisar estado:

```powershell
git status
```

Agregar cambios:

```powershell
git add .
```

Commit inicial sugerido:

```powershell
git commit -m "chore: create minimal Symfony project"
```

Commits posteriores sugeridos:

```text
chore: configure local apache virtual host
chore: lock symfony and php compatibility
feat: add health check route
feat: add base layout and home page
```

---

## 23. Roadmap técnico inicial

### Fase 1 - Proyecto base Symfony

- Crear proyecto Symfony 7.4.
- Bloquear compatibilidad con PHP 8.2.
- Instalar Twig, Asset y Apache Pack.
- Crear ruta principal.
- Crear ruta `/health`.
- Validar ejecución local.
- Configurar Apache local con `printflow.test`.

### Fase 2 - Validación en Hostinger

- Validar PHP, Composer y SSH.
- Subir proyecto mínimo.
- Configurar document root hacia `public/`.
- Probar `/` y `/health`.
- Validar permisos de `var/`.
- Validar modo producción.

### Fase 3 - Pruebas críticas

- Conexión a base de datos.
- Generación de PDF.
- Envío de correo.
- Escritura de logs.
- Manejo de assets.

### Fase 4 - Arquitectura funcional

- Definir entidades principales.
- Diseñar módulos de cotización.
- Diseñar trazabilidad de órdenes.
- Definir usuarios, roles y permisos.
- Preparar estructura de servicios y repositorios.

---

## 24. Solución de problemas comunes

### Composer indica que el directorio no está vacío

Ocurre cuando se intenta crear el proyecto con `.` dentro de una carpeta que ya contiene archivos, incluyendo `.git`.

Solución:

```powershell
composer create-project symfony/skeleton:"7.4.*" _symfony_tmp
Get-ChildItem -Force .\_symfony_tmp | Move-Item -Destination . -Force
Remove-Item -Recurse -Force .\_symfony_tmp
```

### Composer falla por certificados SSL

Validar `php.ini`:

```powershell
php --ini
```

Configurar:

```ini
curl.cainfo = "C:\tools\certs\cacert.pem"
openssl.cafile = "C:\tools\certs\cacert.pem"
```

### Apache no reinicia

Validar sintaxis:

```powershell
cd C:\Apache24\bin
.\httpd.exe -t
```

### `printflow.test` no abre

Validar:

- Que Apache esté iniciado.
- Que `httpd-vhosts.conf` esté incluido.
- Que el archivo `hosts` tenga `127.0.0.1 printflow.test`.
- Que el `DocumentRoot` apunte a `C:/PrintFlow/public`.
- Que exista `public/index.php`.

### Symfony muestra 404

Validar rutas:

```powershell
php bin/console debug:router
```

Si no existe una ruta `/`, crear el controlador principal.

---

## 25. Estado actual del proyecto

Estado técnico esperado después de esta fase:

```text
Symfony instalado
PHP 8.2 configurado
Composer funcional
Apache funcional
Proyecto ejecutable en local
Dominio printflow.test configurado
Ruta /health disponible
Preparación inicial lista para validación en Hostinger
```

---

## 26. Licencia

Pendiente de definir.

---

## 27. Mantenedor

Proyecto en preparación técnica para **PrintFlow**.
