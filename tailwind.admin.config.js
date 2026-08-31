/**
 * Config aparte para el panel admin.
 *
 * El sitio publico se sirve con el CDN de Tailwind; el panel no. Compilamos su
 * hoja a public/css/admin.css y la versionamos en el repo, para que el
 * despliegue no necesite node y el panel se vea igual siempre.
 *
 *   npx tailwindcss -c tailwind.admin.config.js -i resources/css/admin.css \
 *     -o public/css/admin.css --minify
 */
/** @type {import('tailwindcss').Config} */
export default {
  content: [
    './resources/views/layouts/admin.blade.php',
    './resources/views/admin/**/*.blade.php',
    './resources/views/components/arena-zone-map.blade.php',
    './resources/views/components/admin/**/*.blade.php',
    './app/Support/AdminNavigation.php',
  ],
  theme: { extend: {} },
  plugins: [],
  corePlugins: { preflight: true },
};
