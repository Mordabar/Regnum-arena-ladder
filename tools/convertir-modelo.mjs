#!/usr/bin/env node
/**
 * Convierte un modelo suelto (base.obj + texturas PNG) en el .glb que espera el
 * visor del lobby.
 *
 * Los modelos llegan como los saca el generador: 60.000 triangulos y texturas
 * de 2048, unos 8 MB por guerrero. En el lobby puede haber tres a la vez y en
 * movil eso no se sostiene, asi que aqui se decima la malla, se baja la textura
 * y se comprime la geometria con Draco.
 *
 * Uso: node tools/convertir-modelo.mjs <carpeta-del-zip> <nombre-destino>
 *   node tools/convertir-modelo.mjs /tmp/enano_arquero alsius-dwarf-male-archer
 */
import { execFileSync } from 'node:child_process';
import { existsSync, mkdirSync, mkdtempSync, rmSync, statSync, copyFileSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import sharp from 'sharp';
import { join, resolve } from 'node:path';

const [entrada, nombre] = process.argv.slice(2);

if (!entrada || !nombre) {
    console.error('Uso: node tools/convertir-modelo.mjs <carpeta> <nombre-destino>');
    process.exit(1);
}

// Cuantos triangulos y cuanta textura se conservan. Con esto un guerrero pesa
// menos de 1 MB, que es lo que pide public/models/README.md.
// A cuantos triangulos se deja cada guerrero. Es un numero fijo y no una
// fraccion: los paquetes llegan con 60.000 triangulos unos y 120.000 otros, y
// recortar el mismo porcentaje a todos dejaba a la mitad pesando el doble.
const TRIANGULOS = 9000;
const TEXTURA = 1024;

const raiz = resolve(process.cwd());
const destino = join(raiz, 'public', 'models', nombre + '.glb');
const bin = (cmd) => join(raiz, 'node_modules', '.bin', cmd);

const obj = join(entrada, 'base.obj');
if (!existsSync(obj)) {
    console.error('No encuentro base.obj en ' + entrada);
    process.exit(1);
}

const trabajo = mkdtempSync(join(tmpdir(), 'modelo-'));

try {
    // El OBJ viene sin material. Se le escribe uno que apunte a las texturas
    // que trae el paquete, o el modelo saldria de un solo color plano.
    const mapas = {
        map_Kd: 'texture_diffuse.png',
        map_Bump: 'texture_normal.png',
    };

    let mtl = 'newmtl material\nKd 1.000 1.000 1.000\n';
    for (const [clave, archivo] of Object.entries(mapas)) {
        const origen = join(entrada, archivo);
        if (!existsSync(origen)) { continue; }
        copyFileSync(origen, join(trabajo, archivo));
        mtl += `${clave} ${archivo}\n`;
    }

    writeFileSync(join(trabajo, 'base.mtl'), mtl);

    // El paquete trae el mapa de rugosidad y metalicidad ya combinado, que es
    // justo lo que pide glTF. Sin el, la armadura sale igual de mate que la
    // piel y el guerrero parece de plastilina.
    // El mapa de rugosidad y metalicidad baja a la mitad: son valores suaves,
    // sin detalle fino que perder, y a 1024 costaba mas que la propia figura.
    const pbr = join(entrada, 'texture_pbr.png');
    let extras = [];

    if (existsSync(pbr)) {
        const pbrPequeno = join(trabajo, 'pbr.png');
        await sharp(pbr).resize(512, 512, { fit: 'fill' }).toFile(pbrPequeno);
        extras = ['--metallicRoughnessOcclusionTexture', pbrPequeno];
    }

    // El OBJ nombra su material al principio; sin esto obj2gltf no lo asocia.
    const objConMaterial = 'mtllib base.mtl\nusemtl material\n' + readObj(obj);
    writeFileSync(join(trabajo, 'base.obj'), objConMaterial);

    const crudo = join(trabajo, 'crudo.glb');
    run(bin('obj2gltf'), ['-i', join(trabajo, 'base.obj'), '-o', crudo, '--binary', ...extras]);

    // Se decima primero: simplificar despues de comprimir no serviria de nada.
    const ligero = join(trabajo, 'ligero.glb');
    const recorte = Math.min(1, TRIANGULOS / contarCaras(obj));
    // Se cuantiza, no se comprime con Draco ni con Meshopt: esos dos necesitan
    // un decodificador aparte que habria que descargar, y el cargador de
    // Three.js que lleva el proyecto entiende la cuantizacion de serie.
    run(bin('gltf-transform'), ['optimize', crudo, ligero,
        '--simplify-ratio', recorte.toFixed(4),
        '--simplify-error', '1',
        '--texture-size', String(TEXTURA),
        '--texture-compress', 'webp',
        '--compress', 'quantize',
        '--no-prune-attributes',
    ]);

    mkdirSync(join(raiz, 'public', 'models'), { recursive: true });
    copyFileSync(ligero, destino);

    const kb = Math.round(statSync(destino).size / 1024);
    console.log(`${nombre}.glb  ${kb} KB`);

    // Algunos paquetes llegan con la malla partida en muchas piezas y el
    // simplificador se atasca a medio camino: no puede colapsar un borde que es
    // frontera. Esos pesan mas y no hay recorte que lo arregle, asi que el aviso
    // esta donde de verdad empieza a doler en movil.
    if (kb > 1700) {
        console.warn(`  Ojo: ${kb} KB es mucho para movil. Mira si la malla venia partida.`);
    }
} finally {
    rmSync(trabajo, { recursive: true, force: true });
}

function run(cmd, args) {
    execFileSync(cmd, args, { stdio: ['ignore', 'pipe', 'pipe'], maxBuffer: 1024 * 1024 * 64 });
}

/** Cuantas caras trae el OBJ, para saber cuanto hay que recortar. */
function contarCaras(ruta) {
    const salida = execFileSync('sh', ['-c', `grep -c '^f ' ${JSON.stringify(ruta)}`]).toString().trim();
    return Math.max(1, Number(salida) || 1);
}

function readObj(ruta) {
    return execFileSync('cat', [ruta], { maxBuffer: 1024 * 1024 * 256 }).toString();
}
