/**
 * Comprueba que las hojas compiladas no se dejan ninguna clase que usen las
 * plantillas.
 *
 * Tailwind solo genera lo que encuentra como texto en los archivos de `content`.
 * Si una plantilla queda fuera de esos globs, sus clases desaparecen del CSS y
 * la pantalla sale sin estilo — y no siempre se nota en local (paso con la
 * paginacion del panel: con pocos datos solo hay una pagina y el bloque ni se
 * renderiza).
 *
 * En vez de reimplementar el escapado de Tailwind, que es donde fallan las
 * comprobaciones hechas a mano, se compila dos veces con la misma config: una
 * normal y otra con TODAS las clases de las plantillas en la lista blanca. Si
 * las dos salidas son identicas, el escaneo no se deja nada.
 *
 *   node tools/check-css-coverage.mjs
 */
import { execFileSync } from 'node:child_process';
import { readFileSync, writeFileSync, readdirSync, statSync, mkdtempSync } from 'node:fs';
import { join, resolve } from 'node:path';
import { tmpdir } from 'node:os';

const root = resolve(import.meta.dirname, '..');

const targets = [
  {
    name: 'sitio publico',
    config: 'tailwind.config.js',
    input: 'resources/css/site.css',
    output: 'public/css/site.css',
    sources: (f) => (f.endsWith('.blade.php') || f.endsWith('.js'))
      && !f.includes('/admin/') && !f.includes('layouts/admin') && !f.includes('vendor/pagination/admin'),
    roots: ['resources/views', 'public/js'],
  },
  {
    name: 'panel admin',
    config: 'tailwind.admin.config.js',
    input: 'resources/css/admin.css',
    output: 'public/css/admin.css',
    sources: (f) => f.endsWith('.blade.php')
      && (f.includes('/admin/') || f.includes('layouts/admin') || f.includes('vendor/pagination/admin')),
    roots: ['resources/views'],
  },
];

const walk = (dir, out = []) => {
  for (const entry of readdirSync(dir)) {
    const full = join(dir, entry);
    if (statSync(full).isDirectory()) walk(full, out);
    else out.push(full);
  }
  return out;
};

const classesIn = (file) => {
  const src = readFileSync(file, 'utf8');
  const found = new Set();
  for (const m of src.matchAll(/class="([^"]*)"/g)) {
    // Las interpolaciones de Blade se resuelven en tiempo de ejecucion.
    for (const token of m[1].replace(/\{\{[\s\S]*?\}\}/g, ' ').split(/\s+/)) {
      if (token && !token.includes('$') && !token.includes('@') && !token.endsWith('-')) found.add(token);
    }
  }
  for (const m of src.matchAll(/classList\.(?:add|remove|toggle)\(\s*'([^']+)'/g)) {
    if (!m[1].endsWith('-')) found.add(m[1]);
  }
  return found;
};

const tmp = mkdtempSync(join(tmpdir(), 'css-coverage-'));
let failed = false;

for (const target of targets) {
  const classes = new Set();
  for (const dir of target.roots) {
    for (const file of walk(join(root, dir))) {
      if (target.sources(file.replace(root, ''))) {
        for (const c of classesIn(file)) classes.add(c);
      }
    }
  }

  const configPath = join(tmp, target.name.replace(/\W+/g, '-') + '.config.mjs');
  writeFileSync(configPath, `import base from '${join(root, target.config)}';\n`
    + `export default { ...base, safelist: ${JSON.stringify([...classes].sort(), null, 1)} };\n`);

  const probe = join(tmp, target.name.replace(/\W+/g, '-') + '.css');
  execFileSync(join(root, 'node_modules/.bin/tailwindcss'),
    ['-c', configPath, '-i', join(root, target.input), '-o', probe, '--minify'],
    { cwd: root, stdio: 'pipe' });

  const compiled = readFileSync(join(root, target.output), 'utf8');
  const withEverything = readFileSync(probe, 'utf8');

  if (compiled === withEverything) {
    console.log(`ok  ${target.name}: ${classes.size} clases, ninguna ausente de ${target.output}`);
  } else {
    failed = true;
    console.error(`FALLO  ${target.name}: ${target.output} no contiene todo lo que usan las plantillas.`);
    console.error(`       Revisa "content" en ${target.config} y vuelve a compilar con: npm run build`);
    console.error(`       (compilado ${compiled.length} bytes, con lista blanca ${withEverything.length} bytes)`);
  }
}

process.exit(failed ? 1 : 0);
