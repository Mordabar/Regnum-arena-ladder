# Despliegue en Hostinger

Subir los archivos no basta: Laravel necesita dependencias, variables de entorno, base de datos, permisos y un document root correcto.

## Estructura

Asi es como esta montado ahora mismo: el proyecto entero vive dentro de
`public_html` y el `.htaccess` de la raiz reescribe cada peticion a `/public/`.

```apache
RewriteEngine On
RewriteCond %{REQUEST_URI} !^/public/
RewriteRule ^(.*)$ /public/$1 [L,QSA]
```

Funciona sin tocar el document root, que es lo que el plan no deja cambiar. La
contrapartida es que `.env`, `app`, `vendor`, `storage` y `database` quedan
dentro del arbol servido: los protege el mismo `.htaccess`, asi que **no lo
borres ni lo sustituyas** al subir una version nueva.

Si algun dia se puede cambiar el document root, lo limpio es apuntarlo a
`public/` y quitar el `.htaccess` de la raiz.

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

> **Importante: migrar en el mismo despliegue que sube el codigo.**
> El soporte de modalidades (2v2 / 3v3) necesita la columna `arena_mode` en
> `queues`, `matches` y `parties`. Mientras esa columna falte,
> `isMatchesSchemaReady()` devuelve `false` a proposito: la cola queda cerrada
> con un mensaje claro en vez de fallar con errores de SQL. Si por lo que sea el
> `migrate` no llego a correr, se nota asi:
>
> ```bash
> php artisan migrate:status | grep arena_mode
> php artisan tinker --execute="dd(app(App\Services\ArenaMatchmakingService::class)->isMatchesSchemaReady());"
> ```
>
> Con `true` el sistema esta listo. Correr `php artisan migrate --force` lo
> resuelve; la migracion es idempotente y se puede repetir sin riesgo.

### Modalidades tras el despliegue

Las migraciones dejan encendida la modalidad que ya estaba corriendo (2v2 en el
caso normal) y **3v3 y 1v1 apagados**. Estrenar una modalidad es una decision
explicita: se activan desde *Panel admin → Reglas del ladder → Modalidades
abiertas*. Las tres pueden convivir y comparten el mismo ladder.

El duelo 1v1 se comporta como las otras salvo en tres cosas, que estan escritas
en la pantalla del panel: no tiene party, publica el nombre del rival desde el
cruce, y su emparejamiento prefiere el mismo tipo de personaje -arquero contra
arquero, mago contra mago, guerrero contra guerrero- sin quitarle la ultima
palabra al MMR.

La migracion `2026_09_16_000001_register_one_v_one_mode.php` solo siembra el
interruptor apagado. Es idempotente y nunca pisa el valor si ya existe: correrla
otra vez sobre un servidor donde el duelo ya esta abierto lo deja abierto.

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
./vendor/bin/pest
```

### Probar contra MySQL, no solo contra SQLite

El banco de pruebas corre en SQLite por comodidad, y SQLite se traga cosas que
MySQL rechaza. Asi se colo el fallo del rechazo de reportes: `matches.status`
era un ENUM heredado sin `disputed`, MySQL respondia "Data truncated" y tumbaba
la operacion, mientras la suite seguia en verde porque SQLite guarda cualquier
texto.

La suite entera se puede correr contra MySQL sin tocar nada del proyecto,
pasandole la conexion por variables de entorno:

```bash
mysql -e "CREATE DATABASE arena_test CHARACTER SET utf8mb4;"

DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_DATABASE=arena_test \
DB_USERNAME=tu_usuario DB_PASSWORD=tu_clave \
./vendor/bin/pest
```

Comprueba despues el login de Discord, una cola 2v2, una cola 3v3, la subida de evidencias y el panel de modalidades en la configuracion administrativa.
