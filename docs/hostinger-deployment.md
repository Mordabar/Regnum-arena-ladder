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

## Avisos del navegador (Web Push)

Es lo que hace que al jugador le llegue el cruce **con la pestaña cerrada**.
No es un adorno sobre el sondeo: el navegador congela las pestañas que no
estan delante -primero espacia los temporizadores a uno por minuto, luego los
para del todo-, asi que la pagina no se entera de nada hasta que se vuelve a
mirar, que es justo cuando el aviso ya no sirve. El push lo entrega el sistema
operativo, sin la pagina abierta.

Requisitos: **HTTPS** (ya lo hay) y que `public/sw.js` se sirva desde la raiz
del dominio. Un service worker solo controla su carpeta y las de debajo: en
`/js/sw.js` solo controlaria `/js/`.

### Puesta en marcha, una sola vez

```bash
php artisan arena:push-keys
```

Enseña tres lineas. Pegalas en el `.env` de produccion:

```
VAPID_PUBLIC_KEY=...
VAPID_PRIVATE_KEY=...
VAPID_SUBJECT=mailto:tu-correo@ejemplo.com
```

Luego:

```bash
php artisan migrate --force      # crea push_subscriptions
php artisan config:clear
```

**La clave publica no se cambia despues.** Cada navegador se suscribe contra
ella, y si cambia, el servicio de push rechaza todos los envios con un 401 y
nadie recibe nada: hay que volver a pedir permiso a todo el mundo. El comando
avisa y pide confirmacion si ya hay claves puestas.

Sin las claves el sitio funciona igual. Simplemente no avisa con la pestaña
cerrada, y el codigo lo comprueba antes de intentarlo en vez de reventar.

### Como comprobar que va

1. Entra al sitio y dale al interruptor de alertas. El navegador pide permiso.
2. En las herramientas de desarrollo, pestaña *Application → Service Workers*,
   tiene que salir `sw.js` activado.
3. En *Application → Push Messaging*, el boton *Push* simula un aviso: debe
   aparecer una notificacion del sistema.
4. Con dos cuentas: entra a cola con las dos, deja una pestaña en segundo
   plano y cruza. El aviso tiene que llegar sin tocar esa pestaña.

Si no llega, mira en ese orden: si hay claves en el `.env` (`php artisan
about`), si el navegador tiene permiso concedido, y si hay filas en
`push_subscriptions` para ese usuario. Una suscripcion con `fallos` subiendo
es que el servicio de push esta rechazando los envios.

### Que sube al servidor

Ademas de las vistas y `public/build/`:

- `public/sw.js` — en la raiz publica, obligatorio.
- `app/Support/VapidKeys.php`, `app/Support/Base64Url.php`
- `app/Services/WebPushService.php`, `app/Services/AvisosPendientesService.php`
- `app/Http/Controllers/AvisosController.php`, `app/Models/PushSubscription.php`
- `app/Console/Commands/PushKeysCommand.php`
- `config/services.php`, `bootstrap/app.php`, `routes/web_main.php`
- la migracion `2026_09_23_000001_create_push_subscriptions_table.php`
