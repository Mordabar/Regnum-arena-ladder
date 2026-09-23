#!/usr/bin/env bash
# Compila y firma la app de Android de Arena Ladder.
#
#   ./build.sh
#
# Necesita Java y, junto a este fichero, keystore.properties con la firma
# (storeFile, storePassword, keyAlias, keyPassword). Esa clave NO esta en el
# repositorio: sin ella no se puede publicar una actualizacion que Android
# acepte encima de la instalada, asi que guardala bien.
#
# Deja el APK en ../public/apk/arena-ladder.apk. Al cambiar la clave hay
# que cambiar tambien la huella en ../public/.well-known/assetlinks.json.
set -euo pipefail
cd "$(dirname "$0")"

mkdir -p tools
[ -f tools/apktool.jar ] || curl -sSL -o tools/apktool.jar https://github.com/iBotPeaches/Apktool/releases/download/v2.10.0/apktool_2.10.0.jar
[ -f tools/uber-apk-signer.jar ] || curl -sSL -o tools/uber-apk-signer.jar https://github.com/patrickfav/uber-apk-signer/releases/download/v1.3.0/uber-apk-signer-1.3.0.jar

prop() { grep "^$1=" keystore.properties | cut -d= -f2-; }

rm -rf build && mkdir -p build
java -jar tools/apktool.jar b src -o build/sin-firmar.apk
java -jar tools/uber-apk-signer.jar -a build/sin-firmar.apk -o build/firmado \
    --ks "$(prop storeFile)" --ksAlias "$(prop keyAlias)" \
    --ksPass "$(prop storePassword)" --ksKeyPass "$(prop keyPassword)"
cp build/firmado/*-aligned-signed.apk ../public/apk/arena-ladder.apk
echo "Listo: public/apk/arena-ladder.apk"
