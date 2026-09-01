# Modelos 3D de guerreros

Cada archivo se llama `{reino}-{subclase}.glb` y el visor lo carga solo si
existe. Mientras falte, ese guerrero se dibuja con la silueta generada por
codigo, asi que los modelos se pueden subir de uno en uno sin tocar codigo ni
esperar a tenerlos todos.

Combinaciones posibles (18 en total):

    ignis|alsius|syrtis  x  knight|barbarian|hunter|marksman|conjurer|warlock

Ejemplos: `ignis-knight.glb`, `alsius-conjurer.glb`, `syrtis-marksman.glb`.

## Que tiene que cumplir cada modelo

- 8.000 a 15.000 triangulos. Un solo material por modelo.
- Atlas de textura de 1024x1024 (2048 solo si es un modelo destacado).
- Comprimido con Draco y las texturas en KTX2: por debajo de 900 KB.
- Mirando hacia +Z, de pie sobre el origen (Y = 0 a sus pies).
- La altura da igual: el visor escala y centra cualquier modelo al encuadrarlo.
- Una animacion idle de unos 2 segundos, en bucle, si se quiere movimiento.

## Como comprobar que funciona

Deja el archivo aqui y recarga el lobby. Si el nombre es correcto, la silueta
se sustituye sola. Si no aparece, revisa el nombre: tiene que coincidir con la
clave del reino y de la subclase tal y como estan en la base de datos.
