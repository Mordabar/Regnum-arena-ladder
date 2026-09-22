#!/usr/bin/env bash
#
# Despliegue de la rama de funcionalidades premium en Hostinger.
#
# Lo que hace, en este orden y parandose al primer fallo:
#
#   1. Respalda la base de datos ANTES de tocar nada.
#   2. Respalda las zonas del mapa, que son trabajo manual del admin.
#   3. Trae el codigo nuevo.
#   4. Aplica las migraciones.
#   5. Rehace las cachés y comprueba que el sitio responde.
#
# No corre los tests: la suite usa RefreshDatabase, que vaciaria la base de
# produccion. Los tests se pasan en local, contra una base de usar y tirar.
#
# Uso:
#   bash bin/desplegar.sh                          # la rama premium
#   RAMA=main bash bin/desplegar.sh                # otra rama

set -euo pipefail
cd "$(dirname "$0")/.."

RAMA="${RAMA:-feat/funcionalidades-premium}"
SELLO="$(date +%Y%m%d-%H%M%S)"
RESPALDOS="storage/app/respaldos"

paso() { printf '\n▸ %s\n' "$1"; }
error() { printf '\n✖ %s\n' "$1" >&2; exit 1; }

mkdir -p "$RESPALDOS"

# ─────────────────────────────────────────────────────── 1. Respaldo de la base
paso "Respaldando la base de datos"

leer_env() { grep "^${1}=" .env 2>/dev/null | cut -d= -f2- | tr -d '"'"'"''; }

DB_NAME="$(leer_env DB_DATABASE)"
DB_USER="$(leer_env DB_USERNAME)"
DB_PASS="$(leer_env DB_PASSWORD)"
DB_HOST="$(leer_env DB_HOST)"
DB_HOST="${DB_HOST:-127.0.0.1}"

[ -n "$DB_NAME" ] || error "No se pudo leer DB_DATABASE del .env."

VOLCADO="$RESPALDOS/base-$SELLO.sql"

if MYSQL_PWD="$DB_PASS" mysqldump -h "$DB_HOST" -u "$DB_USER" \
        --single-transaction --quick --no-tablespaces "$DB_NAME" > "$VOLCADO" 2>/dev/null; then
    gzip -f "$VOLCADO"
    echo "  guardado en ${VOLCADO}.gz ($(du -h "${VOLCADO}.gz" | cut -f1))"
else
    rm -f "$VOLCADO"
    error "El respaldo de la base fallo. NO se sigue: sin copia no se migra."
fi

# ──────────────────────────────────────── 2. Respaldo de las zonas del mapa
paso "Respaldando las zonas del mapa"

# Sin `tinker`: es dependencia de desarrollo y `composer install --no-dev` la
# quita, asi que en produccion no existe. Se arranca el framework a mano.
cat > /tmp/respaldar-zonas.php <<'PHP'
<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

try {
    $zonas = app(App\Services\ArenaZoneService::class)->configuracionParaElMapa();

    if ($zonas === []) {
        echo "  (no hay zonas todavia)\n";
        exit(0);
    }

    $destino = storage_path('app/respaldos/zonas-' . getenv('SELLO') . '.json');
    file_put_contents($destino, json_encode($zonas, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    echo '  ' . count($zonas) . " zonas guardadas\n";
} catch (Throwable $e) {
    echo "  (la tabla de zonas aun no existe: normal en el primer despliegue)\n";
}
PHP

cp /tmp/respaldar-zonas.php bin/.respaldar-zonas.php
SELLO="$SELLO" php bin/.respaldar-zonas.php 2>/dev/null \
    || echo "  (no se pudo: se continua, la base ya esta respaldada)"
rm -f bin/.respaldar-zonas.php /tmp/respaldar-zonas.php

# ────────────────────────────────────────────────────────── 3. Traer el codigo
paso "Trayendo el codigo de la rama $RAMA"

if [ ! -d .git ]; then
    error "Esto no es un repositorio git. Sube los ficheros por FTP y vuelve a ejecutar desde el paso 4."
fi

if [ -n "$(git status --porcelain)" ]; then
    echo "  hay cambios locales sin commitear; se guardan aparte por si acaso:"
    git stash push -u -m "antes-de-desplegar-$SELLO" || error "No se pudieron guardar los cambios locales."
    echo "  recuperables con: git stash list"
fi

git fetch origin "$RAMA" || error "No se pudo traer la rama del remoto."
git checkout -B "$RAMA" "origin/$RAMA" || error "No se pudo cambiar a la rama."
echo "  ahora en: $(git log -1 --format='%h %s')"

# ───────────────────────────────────────────────────────── 4. Dependencias
paso "Instalando dependencias de produccion"
composer install --no-dev --optimize-autoloader --no-interaction \
    || error "composer install fallo."

# ───────────────────────────────────────────────────────── 5. Migraciones
paso "Migraciones pendientes"
php artisan migrate:status 2>/dev/null | grep -i pending || echo "  ninguna"

paso "Aplicando migraciones"
php artisan migrate --force || error "Las migraciones fallaron. La base esta respaldada en ${VOLCADO}.gz"

# ───────────────────────────────────────────────────────── 6. Cachés
paso "Rehaciendo las cachés"
php artisan config:clear
php artisan cache:clear
php artisan view:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache

# ───────────────────────────────────────────────────────── 7. Comprobacion
paso "Comprobando que el sitio responde"

URL="$(leer_env APP_URL)"
URL="${URL:-https://regnumarenaladder.top}"

for ruta in "/" "/ladder" "/salon-de-la-fama" "/como-funciona"; do
    codigo=$(curl -s -o /dev/null -w '%{http_code}' -m 20 "${URL}${ruta}" || echo "sin respuesta")
    if [ "$codigo" = "200" ]; then
        echo "  200  ${ruta}"
    else
        echo "  $codigo  ${ruta}   <- REVISAR"
    fi
done

paso "Listo"
echo "Respaldo de la base:  ${VOLCADO}.gz"
echo "Respaldo de zonas:    $RESPALDOS/zonas-$SELLO.json"
echo
echo "Si algo salio mal, para volver atras:"
echo "  gunzip -c ${VOLCADO}.gz | mysql -u $DB_USER -p $DB_NAME"
echo "  git checkout <el commit anterior>"
