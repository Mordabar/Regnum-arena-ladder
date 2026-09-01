/**
 * Config del sitio publico.
 *
 * El panel de administracion tiene la suya (`tailwind.admin.config.js`), con su
 * propio sistema de diseno. Esta cubre todo lo demas.
 *
 * Sobre `content`: Tailwind solo genera las clases que encuentra como texto en
 * estos archivos. Lo que no aparezca aqui desaparece del CSS compilado, asi que
 * hay que incluir tambien los <script> de las vistas (anaden clases en tiempo de
 * ejecucion) y el JavaScript suelto de public/js.
 *
 *   npx tailwindcss -i resources/css/site.css -o public/css/site.css --minify
 */
/** @type {import('tailwindcss').Config} */
export default {
  content: [
    './resources/views/**/*.blade.php',
    './resources/js/**/*.js',
    './public/js/**/*.js',
    './app/**/*.php',
  ],
  theme: {
    extend: {},
  },
  plugins: [],
};
