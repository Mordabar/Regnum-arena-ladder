#!/usr/bin/env bash
#
# Diagnostico de SOLO LECTURA del servidor. No cambia nada, no toca la base de
# datos, no escribe ningun fichero. Se ejecuta antes de desplegar para saber
# desde donde se parte.
#
# Uso, desde la carpeta del proyecto en Hostinger:
#
#   bash bin/revisar-produccion.sh
#
# Copia y pega la salida entera: no imprime ninguna contraseña ni token.

set -uo pipefail
cd "$(dirname "$0")/.."

linea() { printf '\n──────── %s\n' "$1"; }

linea "DONDE ESTAMOS"
pwd
echo "usuario: $(whoami)"

linea "PHP"
php -v 2>&1 | head -2
echo "extensiones que hacen falta:"
for e in pdo_mysql mbstring openssl tokenizer xml ctype json fileinfo gd; do
    php -m 2>/dev/null | grep -qix "$e" && echo "  ok  $e" || echo "  NO  $e"
done

linea "LARAVEL"
php artisan --version 2>&1 | head -2

linea "GIT"
if [ -d .git ]; then
    echo "rama:    $(git rev-parse --abbrev-ref HEAD 2>&1)"
    echo "commit:  $(git log -1 --format='%h %ad %s' --date=short 2>&1)"
    echo "remoto:  $(git remote get-url origin 2>&1)"
    echo
    echo "cambios locales sin commitear:"
    git status --porcelain 2>&1 | head -20
    [ -z "$(git status --porcelain 2>/dev/null)" ] && echo "  (ninguno)"
else
    echo "NO es un repositorio git: el despliegue tendra que ser por FTP."
fi

linea "CONFIGURACION (sin secretos)"
if [ -f .env ]; then
    # Solo se imprime la CLAVE de cada ajuste y si tiene valor, nunca el valor.
    echo "claves presentes en .env:"
    grep -oE '^[A-Z_]+=' .env 2>/dev/null | tr -d '=' | while read -r k; do
        v=$(grep "^${k}=" .env | cut -d= -f2-)
        if [ -z "$v" ]; then echo "  $k = (vacio)"; else echo "  $k = (con valor)"; fi
    done | head -40
    echo
    echo "los tres que importan para el despliegue:"
    for k in APP_ENV APP_DEBUG APP_URL DB_CONNECTION CACHE_STORE QUEUE_CONNECTION ARENA_ADMIN_PATH; do
        v=$(grep "^${k}=" .env 2>/dev/null | cut -d= -f2-)
        echo "  $k = ${v:-(sin definir)}"
    done
else
    echo "NO hay .env. La aplicacion no puede arrancar sin el."
fi

linea "BASE DE DATOS"
php artisan db:show --json 2>/dev/null \
    | php -r '
        $d = json_decode(stream_get_contents(STDIN), true);
        if (!$d) { echo "no se pudo leer (revisa las credenciales de la base)\n"; exit; }
        printf("motor:  %s %s\n", $d["name"] ?? "?", $d["version"] ?? "");
        // "tables" es una lista, no un numero: contarla.
        printf("tablas: %s\n", is_array($d["tables"] ?? null) ? count($d["tables"]) : ($d["tables"] ?? "?"));
        if (is_array($d["tables"] ?? null)) {
            $filas = array_sum(array_map(fn ($t) => (int) ($t["rows"] ?? 0), $d["tables"]));
            printf("filas:  %s en total\n", number_format($filas));
        }
    ' 2>/dev/null || echo "no se pudo leer (revisa las credenciales de la base)"

linea "MIGRACIONES PENDIENTES"
php artisan migrate:status 2>&1 | grep -i "pending" | head -20
echo "(si no sale nada aqui arriba, no hay ninguna pendiente)"
echo
echo "ultimas aplicadas:"
php artisan migrate:status 2>&1 | grep -i "ran" | tail -5

linea "PERMISOS DE ESCRITURA"
for d in storage bootstrap/cache storage/app storage/logs storage/framework/views; do
    [ -w "$d" ] && echo "  ok  $d" || echo "  NO  $d  <- hace falta escritura"
done

linea "CRON"
crontab -l 2>/dev/null | grep -i "schedule\|artisan" || echo "  no hay ninguna tarea de artisan en el cron de este usuario"

linea "ESPACIO EN DISCO"
df -h . 2>/dev/null | tail -2

linea "FIN"
echo "Pega esta salida entera en el chat."
