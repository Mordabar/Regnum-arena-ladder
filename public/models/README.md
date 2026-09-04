# Modelos 3D de guerreros

El visor busca el archivo mas concreto que exista y, si no hay ninguno, dibuja
la silueta generada por codigo. Se pueden subir de uno en uno sin tocar codigo.

## Nombres de archivo, del mas concreto al mas general

1. `{reino}-{raza}-{sexo}-{subclase}.glb` — cuerpo y equipo de esa subclase.
2. `{reino}-{raza}-{sexo}-{arquetipo}.glb` — **este es el nivel que se esta
   usando**: un caballero y un barbaro visten de guerrero, asi que un solo
   modelo cubre las dos subclases de su arquetipo.
3. `{reino}-{raza}-{sexo}.glb` — solo el cuerpo, con la ropa del reino.
4. `{reino}-{arquetipo}.glb` y `{reino}-{subclase}.glb` — respaldos antiguos.

## Arquetipos

| Arquetipo | Subclases que cubre     |
|-----------|-------------------------|
| `warrior` | `knight`, `barbarian`   |
| `archer`  | `hunter`, `marksman`    |
| `mage`    | `conjurer`, `warlock`   |

Con 3 arquetipos por raza y sexo quedan cubiertas las 6 subclases: 24 modelos
por reino en vez de 48.

## Razas por reino

| Reino  | Razas                                  |
|--------|----------------------------------------|
| Alsius | nordo, utghar, dwarf, lamai            |
| Ignis  | esquelio, dark_elf, molok, lamai       |
| Syrtis | alturian, wood_elf, half_elf, lamai    |

Sexos: `male`, `female`.

Ejemplos: `alsius-dwarf-male-warrior.glb`, `alsius-lamai-female-mage.glb`.

## Como se importan

Los paquetes llegan como los saca el generador: `base.obj` de unos 60.000
triangulos mas texturas de 2048, cerca de 8 MB por guerrero. En el lobby puede
haber tres a la vez, asi que hay que recortarlos antes de subirlos:

```
node tools/importar-modelos.mjs <zip|carpeta de zips> [...]
```

Deduce el nombre de destino del propio archivo
(`ENANO_ALSIRIO_HOMBRE__GUERRERO.zip` a `alsius-dwarf-male-warrior`) y avisa de
los que no sepa leer en vez de adivinar. Por dentro:

- recorta la malla a unos 9.000 triangulos, calculando el recorte contra lo que
  traiga cada paquete: llegan con 60.000 unos y 120.000 otros, y recortar el
  mismo porcentaje a todos dejaba a la mitad pesando el doble;
- baja el color y las normales a 1024, y el mapa de rugosidad y metalicidad a
  512, todo en WebP;
- cuantiza los atributos con `KHR_mesh_quantization`.

Se cuantiza y no se comprime con Draco ni con Meshopt a proposito: esos dos
necesitan un decodificador aparte que habria que vendorizar, y el cargador de
Three.js que lleva el proyecto entiende la cuantizacion de serie. Si algun dia
se vendoriza el decodificador de Draco, cambiar `--compress quantize` por
`draco` en `tools/convertir-modelo.mjs` deja cada modelo en menos de la mitad.

### Cuando un modelo no adelgaza

Algunos paquetes traen la malla partida en muchas piezas, con una costura de
textura por cada trozo. El simplificador no puede colapsar un borde que es
frontera, asi que se atasca a medio camino: los utghar se quedan en 32.000
triangulos por mucho que se pida menos, y pesan cerca de 1,5 MB. No hay recorte
que lo arregle desde aqui; se arreglaria en el modelo de origen, uniendo las
piezas antes de exportarlo, o vendorizando el decodificador de Draco.

## Que tiene que cumplir cada modelo

- Por debajo de 1,7 MB ya convertido. El importador avisa si se pasa.
- Mirando hacia +Z, de pie sobre el origen (Y = 0 a sus pies).
- La altura da igual: el visor escala y centra cualquier modelo al encuadrarlo.

## Como comprobar que funciona

Deja el archivo aqui y recarga el lobby. Si el nombre es correcto, la silueta
se sustituye sola. Si no aparece, revisa que el nombre coincida exactamente con
las claves de reino, raza, sexo y arquetipo que usa la base de datos.
