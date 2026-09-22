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

Lo mas rapido es el propio sitio: al tocar el boton de avisos, el servidor le
manda a ese dispositivo un push DE VERDAD y el dispositivo confirma que le
llego. Si sale "Recibido. Asi te llegaran los cruces…", el camino entero
funciona -servidor, Google/Mozilla/Apple, dispositivo- y llegara con la
pestaña cerrada. Si no, el aviso dice en que tramo se quedo.

Desde el SSH:

```bash
php artisan arena:push-check              # claves, tabla, sw.js, suscritos,
                                          # bot de Discord y fallos recientes
php artisan arena:push-check --user=<id>  # manda un aviso real y enseña
                                          # que responde el servicio de push
```

"Fallos en navegadores" enseña los ultimos intentos fallidos de los jugadores,
con el motivo real que dio su navegador: no hace falta pedirle a nadie que
abra la consola.

### Que se puede prometer, por plataforma

- **Android (Chrome, Edge, Samsung)**: llega con el navegador cerrado. Lo
  entrega el sistema.
- **Escritorio (Chrome, Edge, Firefox)**: llega con la pestaña cerrada
  mientras el navegador siga abierto o en segundo plano. En Windows, Chrome y
  Edge siguen vivos al cerrar la ventana si esta marcado "Seguir ejecutando
  aplicaciones en segundo plano" (viene marcado). Con el navegador cerrado del
  todo no llega nada: ahi solo alcanza el mensaje directo de Discord.
- **iPhone / iPad**: SOLO con el sitio añadido a la pantalla de inicio
  (Compartir → Añadir a pantalla de inicio) y abierto desde ese icono, iOS
  16.4 o posterior. En una pestaña de Safari no existe el push; el boton lo
  explica al tocarlo.
- **El modo concentracion de Windows** y el "no molestar" del movil ocultan
  las notificaciones aunque lleguen. La prueba de activacion lo detecta: el
  dispositivo confirma la entrega, pero la persona no la ve.

### Que sube al servidor

Ademas de las vistas y `public/build/`:

- `public/sw.js` — en la raiz publica, obligatorio.
- `public/manifest.webmanifest`, `public/images/icono-192.png`,
  `public/images/icono-512.png` — sin el manifiesto no hay push en iPhone.
- `app/Support/VapidKeys.php`, `app/Support/Base64Url.php`
- `app/Services/WebPushService.php`, `app/Services/AvisosPendientesService.php`
- `app/Http/Controllers/AvisosController.php`, `app/Models/PushSubscription.php`
- `app/Console/Commands/PushKeysCommand.php`, `app/Console/Commands/PushCheckCommand.php`
- `config/services.php`, `bootstrap/app.php`, `routes/web_main.php`
- la migracion `2026_09_23_000001_create_push_subscriptions_table.php`

Despues de subir: `php artisan view:clear && php artisan route:clear &&
php artisan config:clear`. Sin `route:clear`, una cache de rutas vieja deja
fuera las rutas nuevas y el sitio da 500 a quien tiene sesion.
