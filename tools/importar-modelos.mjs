#!/usr/bin/env node
/**
 * Importa los paquetes de modelos tal y como llegan y los deja listos para el
 * visor del lobby.
 *
 * Los zips vienen nombrados en castellano -ENANO_ALSIRIO_HOMBRE__GUERRERO- y el
 * visor los busca por reino, raza, sexo y arquetipo. Traducir eso a mano en cada
 * tanda es donde se cuelan las erratas, asi que se deduce del nombre y se avisa
 * de lo que no se entienda en vez de adivinar.
 *
 * Uso: node tools/importar-modelos.mjs <zip|carpeta> [...]
 */
import { execFileSync } from 'node:child_process';
import { existsSync, mkdtempSync, readdirSync, rmSync, statSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { basename, join, resolve } from 'node:path';

// Los plurales aparecen de vez en cuando en los nombres ("UTGHAR_ALSIRIOS"),
// asi que se aceptan: un nombre que el importador no entiende cuesta una vuelta
// entera de preguntar y reenviar.
const REINOS = {
    alsirio: 'alsius', alsiria: 'alsius', alsirios: 'alsius', alsirias: 'alsius', alsius: 'alsius',
    igneo: 'ignis', ignea: 'ignis', igneos: 'ignis', igneas: 'ignis', ignis: 'ignis',
    syrtiano: 'syrtis', syrtiana: 'syrtis', syrtianos: 'syrtis', syrtianas: 'syrtis', syrtis: 'syrtis',
};
const RAZAS = {
    enano: 'dwarf', enana: 'dwarf',
    lamai: 'lamai',
    nordo: 'nordo', norda: 'nordo',
    utghar: 'utghar',
    esquelio: 'esquelio', esquelia: 'esquelio',
    alturian: 'alturian', alturiana: 'alturian',
    molok: 'molok',
    // Los elfos llegan con el reino en el nombre; la clave los distingue.
    elfo_oscuro: 'dark_elf', elfa_oscura: 'dark_elf',
    elfo_del_bosque: 'wood_elf', elfa_del_bosque: 'wood_elf',
    semielfo: 'half_elf', semielfa: 'half_elf',
};
const SEXOS = { hombre: 'male', mujer: 'female' };
const ARQUETIPOS = { arquero: 'archer', arquera: 'archer', guerrero: 'warrior', guerrera: 'warrior', mago: 'mage', maga: 'mage' };

const raiz = resolve(process.cwd());
const entradas = process.argv.slice(2);

if (!entradas.length) {
    console.error('Uso: node tools/importar-modelos.mjs <zip|carpeta> [...]');
    process.exit(1);
}

const paquetes = entradas.flatMap((entrada) => {
    const ruta = resolve(entrada);
    if (!existsSync(ruta)) { throw new Error('No existe ' + ruta); }
    if (statSync(ruta).isDirectory()) {
        return readdirSync(ruta).filter((f) => f.endsWith('.zip')).map((f) => join(ruta, f));
    }
    return [ruta];
});

let hechos = 0;
const dudosos = [];

for (const zip of paquetes) {
    const nombre = deducirNombre(basename(zip));
    if (!nombre) { dudosos.push(basename(zip)); continue; }

    const trabajo = mkdtempSync(join(tmpdir(), 'paquete-'));
    try {
        execFileSync('unzip', ['-o', '-q', zip, '-d', trabajo]);
        execFileSync('node', [join(raiz, 'tools', 'convertir-modelo.mjs'), trabajo, nombre], { stdio: 'inherit' });
        hechos += 1;
    } finally {
        rmSync(trabajo, { recursive: true, force: true });
    }
}

console.log(`\n${hechos} modelo(s) listos en public/models.`);

if (dudosos.length) {
    console.log('\nNo he sabido leer estos nombres, dime a que corresponden:');
    dudosos.forEach((d) => console.log('  ' + d));
    process.exitCode = 1;
}

/**
 * De "563f6571-ENANA_ALSIRIA_MUJER__ARQUERA.zip" a "alsius-dwarf-female-archer".
 */
function deducirNombre(archivo) {
    const limpio = archivo
        .replace(/\.zip$/i, '')
        .replace(/^[0-9a-f]{6,}-/i, '')
        .toLowerCase();

    const piezas = limpio.split(/_+/).filter(Boolean);

    let reino = null, raza = null, sexo = null, arquetipo = null;

    // La raza puede ocupar varias piezas ("elfo_del_bosque"), asi que se prueban
    // las combinaciones largas antes que las sueltas.
    for (let largo = 3; largo >= 1; largo -= 1) {
        for (let i = 0; i + largo <= piezas.length; i += 1) {
            const clave = piezas.slice(i, i + largo).join('_');
            if (!raza && RAZAS[clave]) { raza = RAZAS[clave]; }
        }
    }

    for (const pieza of piezas) {
        if (!reino && REINOS[pieza]) { reino = REINOS[pieza]; }
        if (!sexo && SEXOS[pieza]) { sexo = SEXOS[pieza]; }
        if (!arquetipo && ARQUETIPOS[pieza]) { arquetipo = ARQUETIPOS[pieza]; }
    }

    if (!reino || !raza || !sexo || !arquetipo) { return null; }

    return `${reino}-${raza}-${sexo}-${arquetipo}`;
}
