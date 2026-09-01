# Modelos 3D de guerreros

El visor busca el archivo mas concreto que exista y, si no hay ninguno, dibuja
la silueta generada por codigo. Se pueden subir de uno en uno sin tocar codigo.

## Nombres de archivo, del mas concreto al mas general

1. `{reino}-{raza}-{sexo}-{subclase}.glb` — cuerpo y armadura completos.
2. `{reino}-{raza}-{sexo}.glb` — **este es el nivel base**: el cuerpo con la
   ropa del reino. El arma y la armadura de la subclase quedan a cargo de la
   silueta hasta que exista el nivel 1.
3. `{reino}-{subclase}.glb` — respaldo antiguo, un modelo por reino y subclase.

## Los 24 modelos base (nivel 2)

12 combinaciones de reino y raza, por dos sexos. Los humanos son tres razas
distintas aunque compartan cuerpo, porque la ropa cambia con el reino.

| Reino  | Razas                                  |
|--------|----------------------------------------|
| Alsius | nordo, utghar, dwarf, lamai            |
| Ignis  | esquelio, dark_elf, molok, lamai       |
| Syrtis | alturian, wood_elf, half_elf, lamai    |

Sexos: `male`, `female`.

Ejemplos: `alsius-dwarf-male.glb`, `ignis-dark_elf-female.glb`,
`syrtis-wood_elf-male.glb`.

## Subclases (para el nivel 1)

`knight`, `barbarian`, `hunter`, `marksman`, `conjurer`, `warlock`.

## Que tiene que cumplir cada modelo

- 8.000 a 15.000 triangulos. Un solo material por modelo.
- Atlas de textura de 1024x1024 (2048 solo si es un modelo destacado).
- Comprimido con Draco y las texturas en KTX2: por debajo de 900 KB.
- Mirando hacia +Z, de pie sobre el origen (Y = 0 a sus pies).
- La altura da igual: el visor escala y centra cualquier modelo al encuadrarlo.
- Una animacion idle de unos 2 segundos, en bucle, si se quiere movimiento.

## Como comprobar que funciona

Deja el archivo aqui y recarga el lobby. Si el nombre es correcto, la silueta
se sustituye sola. Si no aparece, revisa que el nombre coincida exactamente con
las claves de reino, raza, sexo y subclase que usa la base de datos.
