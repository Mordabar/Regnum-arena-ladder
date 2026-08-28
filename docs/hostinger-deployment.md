# Despliegue en Hostinger

Subir los archivos no basta: Laravel necesita dependencias, variables de entorno, base de datos, permisos y un document root correcto.

## Estructura recomendada

- Sube el proyecto completo fuera de `public_html`, por ejemplo en `domains/tu-dominio/arena`.
- Configura el document root del dominio para que apunte a `arena/public`.
- No expongas `.env`, `app`, `vendor`, `storage` ni `database` como archivos publicos.

Si el plan no permite cambiar el document root, mueve solo el contenido de `public` a `public_html` y ajusta en `public_html/index.php` las rutas a `vendor/autoload.php` y `bootstrap/app.php`.

## Preparacion

Desde la terminal del proyecto en el servidor:

```bash
composer install --no-dev --optimize-autoloader
cp .env.example .env
php artisan key:generate
```

Configura en `.env` como minimo:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://tu-dominio.com

DB_CONNECTION=mysql
DB_HOST=...
DB_PORT=3306
DB_DATABASE=...
DB_USERNAME=...
DB_PASSWORD=...
```

Conserva tambien las credenciales de Discord y la ruta administrativa usadas por el proyecto.

## Base de datos y cache

```bash
php artisan migrate --force
php artisan storage:link
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

Las migraciones `2026_07_06_000001_add_arena_modes.php` y `2026_07_06_000002_create_arena_seasons.php` conservan los datos actuales, crean Alpha como temporada activa y preparan el Salon de la Fama.

## Permisos y tareas programadas

El usuario de PHP debe poder escribir en `storage` y `bootstrap/cache`.

Configura un cron cada minuto:

```cron
* * * * * cd /ruta/absoluta/al/proyecto && php artisan schedule:run >> /dev/null 2>&1
```

## Comprobacion

```bash
php artisan migrate:status
php artisan about
php artisan test
```

Comprueba despues el login de Discord, una cola 2v2, una cola 3v3, la subida de evidencias y el panel de modalidades en la configuracion administrativa.
