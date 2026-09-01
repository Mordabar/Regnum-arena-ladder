/**
 * Visor 3D de guerreros para el Arena Ladder.
 *
 * Un solo modulo para las tres pantallas donde aparece un personaje: el lobby,
 * el asistente de creacion y la sala de combate. Reglas que se cumplen aqui y
 * no hay que repetir en cada vista:
 *
 *   - Three.js se descarga solo cuando de verdad hace falta, y una sola vez.
 *   - Si el navegador no tiene WebGL, la vista cae en un emblema plano en vez
 *     de dejar un hueco negro.
 *   - El bucle de render se para cuando el visor sale de pantalla o la pestana
 *     pasa a segundo plano. En movil eso es la diferencia entre una bateria que
 *     dura y una que no.
 *   - Con "reducir movimiento" activado el guerrero se queda quieto.
 *   - Si existe /models/{reino}-{subclase}.glb se carga ese modelo; si no, se
 *     construye una silueta por codigo. Asi se pueden ir subiendo modelos de
 *     uno en uno sin romper nada.
 */
window.ArenaChampion = (function () {
  'use strict';

  var THREE_URL = '/js/three.min.js';
  var LOADER_URL = '/js/three-gltf-loader.js';

  var REALM_COLOR = { ignis: 0xd3642f, alsius: 0x79b5d6, syrtis: 0x8eb34a };
  var REALM_GLYPH = { ignis: '◆', alsius: '✹', syrtis: '❀' };

  /**
   * Las seis subclases del ladder se agrupan en tres siluetas. No es pereza:
   * un caballero y un barbaro comparten arquetipo (armadura y arma cuerpo a
   * cuerpo), y lo que los distingue de verdad es el modelo real que se subira
   * despues. Mientras tanto la silueta acierta el arquetipo, que es lo que el
   * jugador necesita reconocer de un vistazo.
   */
  var ARCHETYPE = {
    knight: 'melee', barbarian: 'melee',
    hunter: 'ranged', marksman: 'ranged',
    conjurer: 'caster', warlock: 'caster'
  };

  function archetypeOf(subclass) {
    return ARCHETYPE[subclass] || 'melee';
  }

  /* Medidas de la silueta por defecto, por si hay que encuadrar antes de que
     exista el modelo. */
  var FRAME_HEIGHT = 2.45;
  var FRAME_WIDTH = 2.4;
  var FRAME_CENTER = 1.1;
  /** Cuanto se eleva la camara sobre el centro del guerrero, en alturas suyas. */
  var LIFT_RATIO = 0.12;

  var reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var threePromise = null;
  var loaderPromise = null;
  var modelCache = {};

  function loadScript(src) {
    return new Promise(function (resolve, reject) {
      var s = document.createElement('script');
      s.src = src;
      s.async = true;
      s.onload = resolve;
      s.onerror = function () { reject(new Error('No se pudo cargar ' + src)); };
      document.head.appendChild(s);
    });
  }

  function needThree() {
    if (window.THREE) { return Promise.resolve(); }
    if (!threePromise) { threePromise = loadScript(THREE_URL); }
    return threePromise;
  }

  function needLoader() {
    if (window.THREE && window.THREE.GLTFLoader) { return Promise.resolve(); }
    if (!loaderPromise) { loaderPromise = loadScript(LOADER_URL); }
    return loaderPromise;
  }

  function hasWebGL() {
    try {
      var c = document.createElement('canvas');
      return !!(window.WebGLRenderingContext && (c.getContext('webgl') || c.getContext('experimental-webgl')));
    } catch (e) {
      return false;
    }
  }

  /* ─────────── silueta procedural: el hueco donde entra el .glb ─────────── */

  function mat(hex, rough, metal) {
    return new THREE.MeshStandardMaterial({
      color: new THREE.Color(hex),
      roughness: rough === undefined ? 0.62 : rough,
      metalness: metal === undefined ? 0.35 : metal,
      flatShading: true
    });
  }

  function buildChampion(realm, subclass) {
    var kind = archetypeOf(subclass);
    var accent = REALM_COLOR[realm] || REALM_COLOR.ignis;
    var g = new THREE.Group();

    var armor = mat(0x3a2b20, 0.55, 0.55);
    var cloth = mat(0x241a14, 0.9, 0.05);
    var trim = mat(accent, 0.35, 0.7);
    var skin = mat(0x9a7355, 0.85, 0.0);

    var plinth = new THREE.Mesh(new THREE.CylinderGeometry(1.02, 1.16, 0.18, 8), mat(0x241a18, 0.95, 0.05));
    plinth.position.y = 0.09;
    g.add(plinth);

    [-0.19, 0.19].forEach(function (x) {
      var leg = new THREE.Mesh(new THREE.CylinderGeometry(0.115, 0.09, 0.78, 6), cloth);
      leg.position.set(x, 0.57, 0);
      g.add(leg);
      var boot = new THREE.Mesh(new THREE.BoxGeometry(0.24, 0.16, 0.32), armor);
      boot.position.set(x, 0.25, 0.03);
      g.add(boot);
    });

    var torso = new THREE.Mesh(new THREE.CylinderGeometry(0.34, 0.27, 0.72, 8), armor);
    torso.position.y = 1.3;
    g.add(torso);

    var belt = new THREE.Mesh(new THREE.CylinderGeometry(0.29, 0.29, 0.09, 8), trim);
    belt.position.y = 0.98;
    g.add(belt);

    var cape = new THREE.Mesh(
      new THREE.CylinderGeometry(0.33, 0.46, 1.05, 10, 1, true, Math.PI * 0.62, Math.PI * 0.76),
      mat(accent, 0.88, 0.05)
    );
    cape.material.side = THREE.DoubleSide;
    cape.position.set(0, 1.12, -0.07);
    g.add(cape);

    [-0.42, 0.42].forEach(function (x) {
      var pad = new THREE.Mesh(new THREE.SphereGeometry(0.2, 7, 5), kind === 'melee' ? trim : armor);
      pad.scale.set(1, 0.72, 1);
      pad.position.set(x, 1.55, 0);
      g.add(pad);
      var arm = new THREE.Mesh(new THREE.CylinderGeometry(0.085, 0.07, 0.62, 6), cloth);
      arm.position.set(x * 1.05, 1.16, 0);
      arm.rotation.z = x > 0 ? -0.13 : 0.13;
      g.add(arm);
    });

    var neck = new THREE.Mesh(new THREE.CylinderGeometry(0.1, 0.12, 0.14, 6), skin);
    neck.position.y = 1.71;
    g.add(neck);

    var head = new THREE.Mesh(new THREE.IcosahedronGeometry(0.21, 0), skin);
    head.position.y = 1.9;
    g.add(head);

    if (kind === 'melee') {
      var helm = new THREE.Mesh(new THREE.SphereGeometry(0.235, 8, 6, 0, Math.PI * 2, 0, Math.PI * 0.62), armor);
      helm.position.y = 1.92;
      g.add(helm);
      var crest = new THREE.Mesh(new THREE.BoxGeometry(0.05, 0.24, 0.34), trim);
      crest.position.set(0, 2.06, -0.02);
      g.add(crest);
    } else if (kind === 'ranged') {
      var hood = new THREE.Mesh(new THREE.SphereGeometry(0.27, 8, 6, 0, Math.PI * 2, 0, Math.PI * 0.58), mat(accent, 0.9, 0.05));
      hood.position.set(0, 1.9, -0.03);
      hood.rotation.x = -0.16;
      g.add(hood);
      var peak = new THREE.Mesh(new THREE.ConeGeometry(0.16, 0.3, 6), mat(accent, 0.9, 0.05));
      peak.position.set(0, 1.98, -0.19);
      peak.rotation.x = 0.75;
      g.add(peak);
    } else {
      var circlet = new THREE.Mesh(new THREE.TorusGeometry(0.2, 0.026, 6, 16), trim);
      circlet.position.y = 1.96;
      circlet.rotation.x = Math.PI / 2;
      g.add(circlet);
    }

    if (kind === 'melee') {
      var blade = new THREE.Mesh(new THREE.BoxGeometry(0.075, 1.15, 0.03), mat(0xc9cdd4, 0.3, 0.9));
      blade.position.set(0.52, 1.32, 0.14);
      blade.rotation.z = -0.1;
      g.add(blade);
      var guard = new THREE.Mesh(new THREE.BoxGeometry(0.3, 0.06, 0.07), trim);
      guard.position.set(0.5, 0.82, 0.14);
      g.add(guard);
      if (subclass === 'barbarian') {
        // Sin escudo y con hacha: el barbaro se reconoce por eso.
        var axe = new THREE.Mesh(new THREE.BoxGeometry(0.34, 0.26, 0.05), mat(0xc9cdd4, 0.35, 0.85));
        axe.position.set(0.66, 1.72, 0.14);
        g.add(axe);
      } else {
        var shield = new THREE.Mesh(new THREE.CylinderGeometry(0.29, 0.24, 0.05, 6), trim);
        shield.position.set(-0.52, 1.22, 0.16);
        shield.rotation.set(Math.PI / 2, 0, 0.12);
        g.add(shield);
      }
    } else if (kind === 'ranged') {
      var bow = new THREE.Mesh(new THREE.TorusGeometry(0.44, 0.026, 5, 18, Math.PI * 1.05), mat(0x6b4a2c, 0.8, 0.1));
      bow.position.set(0.6, 1.3, 0.1);
      bow.rotation.set(0, 0, Math.PI * 1.48);
      g.add(bow);
      var string = new THREE.Mesh(new THREE.CylinderGeometry(0.007, 0.007, 0.84, 4), mat(0xd8cbb4, 0.7, 0.1));
      string.position.set(0.49, 1.3, 0.1);
      g.add(string);
    } else {
      var staff = new THREE.Mesh(new THREE.CylinderGeometry(0.032, 0.032, 1.7, 6), mat(0x5c4026, 0.85, 0.1));
      staff.position.set(0.55, 1.15, 0.1);
      staff.rotation.z = -0.06;
      g.add(staff);
      var orb = new THREE.Mesh(
        new THREE.IcosahedronGeometry(0.14, 0),
        new THREE.MeshStandardMaterial({
          color: new THREE.Color(accent), emissive: new THREE.Color(accent),
          emissiveIntensity: 1.5, roughness: 0.3
        })
      );
      orb.position.set(0.5, 1.99, 0.1);
      g.add(orb);
      var halo = new THREE.PointLight(accent, 2.6, 2.1, 2);
      halo.position.copy(orb.position);
      g.add(halo);
    }

    return g;
  }

  /* ─────────── carga de modelos reales ─────────── */

  function modelUrl(realm, subclass) {
    return '/models/' + realm + '-' + subclass + '.glb';
  }

  /**
   * Que modelos existen se sabe ANTES de pedir nada: el servidor lista los
   * archivos de public/models al pintar la pagina. Comprobarlo con una peticion
   * por personaje llenaria la consola de 404 y gastaria un viaje de ida y vuelta
   * cada vez que alguien cambia de guerrero en el rail.
   */
  function hasModel(realm, subclass) {
    var available = window.arenaChampionModels;
    if (!available || !available.length) { return false; }
    return available.indexOf(realm + '-' + subclass) !== -1;
  }

  function tryLoadModel(realm, subclass) {
    if (!hasModel(realm, subclass)) { return Promise.resolve(null); }

    var url = modelUrl(realm, subclass);

    if (Object.prototype.hasOwnProperty.call(modelCache, url)) {
      return Promise.resolve(modelCache[url] ? modelCache[url].clone(true) : null);
    }

    return needLoader().then(function () {
      return new Promise(function (resolve) {
        new THREE.GLTFLoader().load(url, function (gltf) {
          modelCache[url] = gltf.scene;
          resolve(gltf.scene.clone(true));
        }, undefined, function () {
          modelCache[url] = null;
          resolve(null);
        });
      });
    }).catch(function () { return null; });
  }

  /** Encaja cualquier modelo en la misma altura y centro que la silueta. */
  function normalize(object) {
    var box = new THREE.Box3().setFromObject(object);
    var size = box.getSize(new THREE.Vector3());
    var center = box.getCenter(new THREE.Vector3());
    var scale = size.y > 0 ? 2.1 / size.y : 1;

    var wrapper = new THREE.Group();
    object.position.set(-center.x * scale, -box.min.y * scale, -center.z * scale);
    object.scale.setScalar(scale);
    wrapper.add(object);
    return wrapper;
  }

  /* ─────────── el visor ─────────── */

  function mount(canvas, options) {
    options = options || {};
    var host = canvas.parentElement;

    if (!hasWebGL()) {
      paintFallback(host, options.realm);
      return {
        set: function (realm) { paintFallback(host, realm); },
        dispose: function () {},
        available: false
      };
    }

    var api = { available: true };
    var renderer, scene, camera, rim, ring, champion, raf = null;
    var visible = true, spin = 0, grow = 0, token = 0;
    var pointer = { x: 0 };
    var current = { realm: options.realm || 'ignis', subclass: options.subclass || 'knight' };

    needThree().then(function () {
      build();
      setChampion(current.realm, current.subclass);
      watchVisibility();
      hideFallback(host);
    }).catch(function () {
      paintFallback(host, current.realm);
      api.available = false;
    });

    function build() {
      renderer = new THREE.WebGLRenderer({ canvas: canvas, antialias: window.innerWidth > 720, alpha: true });
      // Por encima de 2 no se nota y en movil cuesta el doble de pixeles.
      renderer.setPixelRatio(Math.min(window.devicePixelRatio, window.innerWidth > 720 ? 2 : 1.6));

      scene = new THREE.Scene();
      scene.fog = new THREE.Fog(0x0d0906, 6, 15);

      camera = new THREE.PerspectiveCamera(34, 1, 0.1, 100);

      scene.add(new THREE.HemisphereLight(0xffd9a0, 0x120c08, 0.55));

      var key = new THREE.DirectionalLight(0xffe0b0, 1.15);
      key.position.set(2.6, 4.2, 3.4);
      scene.add(key);

      var fill = new THREE.DirectionalLight(0x8fb6d8, 0.35);
      fill.position.set(-3.2, 1.8, 2);
      scene.add(fill);

      rim = new THREE.PointLight(0xd3642f, 12, 12, 2);
      rim.position.set(0, 2.1, -2.4);
      scene.add(rim);

      var floor = new THREE.Mesh(
        new THREE.CircleGeometry(3.1, 40),
        new THREE.MeshStandardMaterial({ color: 0x1a120d, roughness: 0.95, metalness: 0.05 })
      );
      floor.rotation.x = -Math.PI / 2;
      scene.add(floor);

      ring = new THREE.Mesh(
        new THREE.RingGeometry(1.28, 1.4, 48),
        new THREE.MeshBasicMaterial({ color: 0xd3642f, transparent: true, opacity: 0.55, side: THREE.DoubleSide })
      );
      ring.rotation.x = -Math.PI / 2;
      ring.position.y = 0.012;
      scene.add(ring);

      resize();
      window.addEventListener('resize', resize);

      // El contenedor cambia de alto sin que cambie la ventana: un clamp() con
      // vh, un panel que se despliega, el rail que pasa a una columna. Sin esto
      // el lienzo se queda con la medida que tenia al arrancar y el guerrero
      // aparece cortado por abajo.
      if ('ResizeObserver' in window) {
        new ResizeObserver(resize).observe(host);
      }

      if (options.parallax !== false) {
        host.addEventListener('pointermove', function (e) {
          var r = canvas.getBoundingClientRect();
          pointer.x = ((e.clientX - r.left) / r.width - 0.5) * 2;
        });
      }
    }

    function resize() {
      if (!renderer) { return; }
      var r = host.getBoundingClientRect();
      var w = Math.max(1, r.width), h = Math.max(1, r.height);
      renderer.setSize(w, h, false);
      camera.aspect = w / h;
      camera.updateProjectionMatrix();
      frame();
      draw();
    }

    /**
     * Coloca la camara a la distancia justa para que el guerrero entero quepa,
     * sea cual sea la forma del hueco.
     *
     * Antes la distancia era un numero fijo con un parche para pantallas
     * estrechas, y eso cortaba la cabeza o el pedestal en cuanto el contenedor
     * cambiaba de proporcion (el escenario ancho y bajo de la cola, el cuadrado
     * del movil). Ahora se mide el modelo y se resuelve por trigonometria, lo
     * que ademas encuadra bien cualquier .glb que se suba despues, tenga la
     * altura que tenga.
     */
    function frame() {
      if (!camera) { return; }

      var height = FRAME_HEIGHT;
      var width = FRAME_WIDTH;
      var center = FRAME_CENTER;

      if (champion) {
        var box = new THREE.Box3().setFromObject(champion);
        if (box.isEmpty() === false) {
          var size = box.getSize(new THREE.Vector3());
          height = Math.max(size.y, 0.5) * 1.16;
          width = Math.max(size.x, size.z, 0.5) * 1.16;
          center = box.min.y + size.y / 2;
        }
      }

      // La camara se eleva un poco para mirar al guerrero desde arriba, y eso
      // desplaza el encuadre hacia abajo: hay que contarlo dos veces en la
      // altura o la cabeza se sale por el borde superior.
      var lift = height * LIFT_RATIO;
      var vFov = camera.fov * Math.PI / 180;
      var distForHeight = (height / 2 + lift) / Math.tan(vFov / 2);
      var hFov = 2 * Math.atan(Math.tan(vFov / 2) * camera.aspect);
      var distForWidth = (width / 2) / Math.tan(hFov / 2);
      var dist = Math.max(distForHeight, distForWidth);

      camera.position.set(0, center + lift, dist);
      camera.lookAt(0, center, 0);
      camera.updateProjectionMatrix();
    }

    function setChampion(realm, subclass) {
      current.realm = realm;
      current.subclass = subclass;
      if (!scene) { return; }

      var mine = ++token;
      var color = REALM_COLOR[realm] || REALM_COLOR.ignis;
      rim.color = new THREE.Color(color);
      ring.material.color = new THREE.Color(color);

      swap(buildChampion(realm, subclass));

      tryLoadModel(realm, subclass).then(function (model) {
        if (model && mine === token) { swap(normalize(model)); }
      });
    }

    function swap(group) {
      if (champion) { scene.remove(champion); disposeTree(champion); }
      champion = group;
      grow = 0;
      scene.add(champion);

      // Se encuadra con el tamano FINAL, no con el de la animacion de entrada.
      // Midiendo al 82% la camara se quedaba corta y, al terminar de crecer, el
      // guerrero se salia por arriba y por abajo.
      champion.scale.setScalar(1);
      frame();
      champion.scale.setScalar(0.82);

      start();
    }

    function tick() {
      raf = null;
      if (champion) {
        if (reduceMotion) {
          champion.rotation.y = 0.35;
        } else {
          spin += 0.0042;
          champion.rotation.y = spin + pointer.x * 0.34;
          champion.position.y = Math.sin(spin * 3.1) * 0.014;
        }
        if (grow < 1) {
          grow = Math.min(1, grow + 0.06);
          champion.scale.setScalar(0.82 + (1 - Math.pow(1 - grow, 3)) * 0.18);
        }
      }
      if (ring && !reduceMotion) {
        ring.material.opacity = 0.42 + Math.sin(Date.now() * 0.0015) * 0.16;
      }
      draw();

      // Con movimiento reducido y el modelo ya colocado no hay nada que animar:
      // se deja de pedir cuadros en vez de girar en vacio.
      if (visible && !(reduceMotion && grow >= 1)) {
        raf = requestAnimationFrame(tick);
      }
    }

    function draw() {
      if (renderer && scene && camera) { renderer.render(scene, camera); }
    }

    function start() {
      if (raf === null && visible) { raf = requestAnimationFrame(tick); }
    }

    function stop() {
      if (raf !== null) { cancelAnimationFrame(raf); raf = null; }
    }

    function watchVisibility() {
      if ('IntersectionObserver' in window) {
        new IntersectionObserver(function (entries) {
          visible = entries[0].isIntersecting && document.visibilityState !== 'hidden';
          if (visible) { start(); } else { stop(); }
        }, { threshold: 0.05 }).observe(host);
      }
      document.addEventListener('visibilitychange', function () {
        visible = document.visibilityState !== 'hidden';
        if (visible) { start(); } else { stop(); }
      });
    }

    function disposeTree(obj) {
      obj.traverse(function (node) {
        if (node.geometry) { node.geometry.dispose(); }
        if (node.material) {
          (Array.isArray(node.material) ? node.material : [node.material]).forEach(function (m) { m.dispose(); });
        }
      });
    }

    api.set = function (realm, subclass) {
      paintFallback(host, realm);
      setChampion(realm, subclass);
    };
    api.dispose = function () {
      stop();
      window.removeEventListener('resize', resize);
      if (champion) { disposeTree(champion); }
      if (renderer) { renderer.dispose(); }
    };

    return api;
  }

  /**
   * El emblema del reino es lo que hay ANTES de que el visor arranque, no un
   * plan B que aparece tarde. Asi la pagina se ve entera sin JavaScript, sin
   * WebGL o mientras se descarga Three.js, y solo se retira cuando hay algo
   * mejor que ensenar.
   */
  function hideFallback(host) {
    var el = host.querySelector('[data-champion-fallback]');
    if (el) { el.hidden = true; }
  }

  function paintFallback(host, realm) {
    var glyph = host.querySelector('[data-champion-glyph]');
    if (glyph) { glyph.textContent = REALM_GLYPH[realm] || REALM_GLYPH.ignis; }
  }

  return {
    mount: mount,
    realmColor: REALM_COLOR,
    realmGlyph: REALM_GLYPH,
    archetypeOf: archetypeOf
  };
})();
