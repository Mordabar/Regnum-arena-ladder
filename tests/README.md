Cobertura automatica del Arena Ladder. Todo vive en `tests/Feature`.

## Como correrlos

    composer test          # SQLite en memoria. Rapido, es el del dia a dia.
    composer test:mysql    # MySQL/MariaDB. El que se parece a produccion.

## Por que hay dos

La suite corre en SQLite porque tarda segundos en vez de minutos, pero SQLite
es mas permisivo que MySQL y deja pasar cosas que en produccion revientan:

- **No comprueba las longitudes de columna.** Un valor de 32 caracteres entra
  sin rechistar en un `varchar(24)`; MySQL lo rechaza con un 1406 y tumba la
  peticion. Paso una vez, con un token de reporte.
- **Compara texto en binario.** MySQL, con una colacion `_ci`, ignora
  mayusculas al comparar: un `whereIn('value', [...])` puede darse por bueno
  con un texto que el usuario escribio distinto.
- **Ignora `lockForUpdate()`.** El compilador de SQLite no emite nada, asi que
  ninguna correccion de concurrencia esta cubierta por los tests en SQLite.

`composer test:mysql` levanta una base limpia y corre la suite entera contra
ella. Conviene pasarlo **antes de desplegar**, no solo cuando algo falla.

Los tests que no pueden comprobarse en SQLite -las longitudes de columna- se
saltan ahi con el motivo escrito, en vez de pasar en verde sin haber mirado
nada. Si ves `2 skipped`, eso es lo que son.

## Dos trampas del proyecto que tienen su propia red

- `PlantillasBladeTest` compila todas las vistas y prohibe nombrar `@php`
  dentro de un comentario `{{-- --}}`: Blade compila las directivas que
  encuentra ahi dentro, asi que **documentar** la trampa bastaba para dejar una
  apertura de PHP sin cerrar y romper la pagina entera.
- `AdminStylesheetTest` y `SiteStylesheetTest` comprueban que las clases que
  usan las vistas existen de verdad en el CSS compilado.
