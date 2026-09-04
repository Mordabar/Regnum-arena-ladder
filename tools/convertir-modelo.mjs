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
// Fraccion de vertices que se conserva. Los modelos llegan con 60.000
// triangulos; con esto quedan unos 12.000, que es lo que pide el README.
const RECORTE = 0.15;
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
    // Se cuantiza, no se comprime con Draco ni con Meshopt: esos dos necesitan
    // un decodificador aparte que habria que descargar, y el cargador de
    // Three.js que lleva el proyecto entiende la cuantizacion de serie.
    run(bin('gltf-transform'), ['optimize', crudo, ligero,
        '--simplify-ratio', String(RECORTE),
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
    if (kb > 1200) {
        console.warn(`  Ojo: pasa del listo para movil (1200 KB). Revisa el recorte.`);
    }
} finally {
    rmSync(trabajo, { recursive: true, force: true });
}

function run(cmd, args) {
    execFileSync(cmd, args, { stdio: ['ignore', 'pipe', 'pipe'], maxBuffer: 1024 * 1024 * 64 });
}

function readObj(ruta) {
    return execFileSync('cat', [ruta], { maxBuffer: 1024 * 1024 * 256 }).toString();
}
