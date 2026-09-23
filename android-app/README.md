# Arena Ladder para Android

Una Trusted Web Activity: la app no lleva copia del sitio, abre
https://regnumarenaladder.top en Chrome a pantalla completa y sin barra de
direcciones. Los avisos del sitio funcionan igual que en el navegador, y cada
cambio que se sube a la web llega a la app sin reinstalar nada.

- `src/AndroidManifest.xml`, `src/res/`: nombre, icono y enlaces del sitio.
- `src/smali/.../Lanzador*.smali`: el lanzador (busca Chrome, abre la sesion
  de Custom Tabs y lanza el sitio como app; si algo falla, abre el navegador).
- `build.sh`: compila y firma. Necesita `keystore.properties` (no esta en git).
- La otra mitad de la relacion es `public/.well-known/assetlinks.json`: lleva la
  huella SHA-256 de la clave de firma. Si cambia la clave, hay que cambiarla.

Para una version nueva: sube `versionCode` y `versionName` en `src/apktool.yml`
y ejecuta `./build.sh`.
