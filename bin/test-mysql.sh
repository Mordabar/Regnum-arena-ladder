#!/usr/bin/env bash
#
# Corre la suite entera contra MySQL/MariaDB, no contra SQLite.
#
# Por que existe: los tests van en SQLite en memoria porque son rapidos, pero
# SQLite no comprueba las longitudes de las columnas. Un valor de 32 caracteres
# en un varchar(24) pasa sin rechistar en los tests y revienta en produccion,
# que es MySQL. Ya paso una vez.
#
# Uso:
#   bin/test-mysql.sh                 # la suite entera
#   bin/test-mysql.sh tests/Feature/X # un fichero suelto
#
# Variables opcionales: MYSQL_HOST, MYSQL_PORT, MYSQL_USER, MYSQL_PASSWORD,
# MYSQL_DATABASE.

set -euo pipefail

HOST="${MYSQL_HOST:-127.0.0.1}"
PORT="${MYSQL_PORT:-3306}"
USER="${MYSQL_USER:-root}"
PASSWORD="${MYSQL_PASSWORD:-}"
DATABASE="${MYSQL_DATABASE:-arena_test}"

cd "$(dirname "$0")/.."

cliente=(mysql -h "$HOST" -P "$PORT" -u "$USER")
[ -n "$PASSWORD" ] && cliente+=("-p$PASSWORD")

if ! "${cliente[@]}" -e "SELECT 1" >/dev/null 2>&1; then
    echo "No se puede conectar a MySQL en $HOST:$PORT como $USER." >&2
    echo "Levanta el servidor o ajusta MYSQL_HOST / MYSQL_USER / MYSQL_PASSWORD." >&2
    exit 1
fi

echo "Preparando la base $DATABASE..."
"${cliente[@]}" -e "DROP DATABASE IF EXISTS \`$DATABASE\`;
                    CREATE DATABASE \`$DATABASE\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

echo "Corriendo la suite contra MySQL..."
DB_CONNECTION=mysql \
DB_HOST="$HOST" \
DB_PORT="$PORT" \
DB_DATABASE="$DATABASE" \
DB_USERNAME="$USER" \
DB_PASSWORD="$PASSWORD" \
php artisan test "$@"
