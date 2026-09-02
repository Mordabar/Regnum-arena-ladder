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

  /**
   * Las rutas llegan con version desde el servidor (?v=fecha de modificacion).
   * Hace falta porque los estaticos se cachean un ano como inmutables: sin
   * version, actualizar Three.js no llegaria nunca a quien ya lo tiene.
   */
  var ASSETS = window.arenaChampionAssets || {};
  var THREE_URL = ASSETS.three || '/js/three.min.js';
  var LOADER_URL = ASSETS.loader || '/js/three-gltf-loader.js';

  var REALM_COLOR = { ignis: 0xd3642f, alsius: 0x79b5d6, syrtis: 0x8eb34a };
  var REALM_GLYPH = { ignis: '◆', alsius: '✹', syrtis: '❀' };

  /**
   * Cada subclase tiene su silueta, no solo su arquetipo.
   *
   * El arma se reconoce de lejos y la armadura de cerca: el tirador va de
   * dorado y el cazador de verde aunque los dos lleven arco, y el conjurador
   * viste claro frente al brujo, que viste oscuro. Sin eso, seis subclases se
   * veian como tres munecos repetidos.
   */
  var SUBCLASS = {
    knight:    { weapon: 'sword',  head: 'helm',    armor: 0x4a4f57, trim: 0xb9c2cc, cloth: 0x2b2f36 },
    barbarian: { weapon: 'axe',    head: 'bare',    armor: 0x5a3a24, trim: 0x8a5a30, cloth: 0x3a2418 },
    hunter:    { weapon: 'bow',    head: 'hood',    armor: 0x3f5a2e, trim: 0x8eb34a, cloth: 0x2a3a20 },
    marksman:  { weapon: 'bow',    head: 'circlet', armor: 0x6b5320, trim: 0xd9b44a, cloth: 0x3d2f14 },
    conjurer:  { weapon: 'staff',  head: 'circlet', armor: 0xd8cdb8, trim: 0xf3ead6, cloth: 0xbfb39c },
    warlock:   { weapon: 'staff',  head: 'hood',    armor: 0x2a2233, trim: 0x6b4a86, cloth: 0x1b1622 }
  };

  /**
   * La raza cambia el maniqui: altura, anchura y un rasgo que la delata.
   *
   *   scale  cuanto mide respecto a un humano
   *   width  cuanto ensancha el torso y los hombros
   *   skin   color de la piel
   *   ears   'none' | 'pointed' | 'short' | 'big'
   *   horns  cuernos
   *   beard  barba
   */
  var RACE = {
    nordo:     { scale: 1.00, width: 1.00, skin: 0xc49a72, ears: 'none' },
    esquelio:  { scale: 1.00, width: 1.00, skin: 0xb98a5e, ears: 'none' },
    alturian:  { scale: 1.00, width: 1.00, skin: 0xc9a077, ears: 'none' },
    utghar:    { scale: 1.08, width: 1.22, skin: 0x8f7a55, ears: 'none', horns: true },
    dwarf:     { scale: 0.78, width: 1.30, skin: 0xc08f68, ears: 'none', beard: true },
    molok:     { scale: 1.16, width: 1.28, skin: 0x3d342c, ears: 'none', tusks: true },
    dark_elf:  { scale: 1.02, width: 0.92, skin: 0x8a6ea3, ears: 'pointed' },
    wood_elf:  { scale: 1.04, width: 0.90, skin: 0xe8d8c4, ears: 'pointed' },
    half_elf:  { scale: 1.00, width: 0.96, skin: 0xd3ab84, ears: 'short' },
    lamai:     { scale: 0.86, width: 0.94, skin: 0xd8b98c, ears: 'big' }
  };

  /**
   * El sexo cambia la silueta del maniqui, no su tamano: cintura mas estrecha,
   * caderas algo mas anchas y falda. Es lo minimo que hace falta para que se
   * distinga de lejos sin caer en la caricatura.
   */
  var GENDER = {
    male:   { waist: 1.00, hips: 1.00, chest: 1.00, skirt: false },
    female: { waist: 0.80, hips: 1.10, chest: 0.94, skirt: true }
  };

  function genderOf(gender) {
    return GENDER[gender] || GENDER.male;
  }

  var DEFAULT_RACE_BY_REALM = { ignis: 'esquelio', alsius: 'nordo', syrtis: 'alturian' };

  function subclassOf(subclass) {
    return SUBCLASS[subclass] || SUBCLASS.knight;
  }

  /**
   * Que razas puede tener cada reino. Es la misma tabla que el servidor:
   * si aqui no se comprobase, un dato torcido (un enano guardado en Syrtis)
   * haria que la ficha dijese "Alturiano" mientras el modelo dibuja un enano
   * de manual, y el jugador leeria una cosa y veria otra.
   */
  var REALM_RACES = {
    alsius: ['nordo', 'utghar', 'dwarf', 'lamai'],
    ignis: ['esquelio', 'dark_elf', 'molok', 'lamai'],
    syrtis: ['alturian', 'wood_elf', 'half_elf', 'lamai']
  };

  function raceOf(race, realm) {
    var allowed = REALM_RACES[realm];

    if (race && RACE[race] && (!allowed || allowed.indexOf(race) !== -1)) {
      return RACE[race];
    }

    return RACE[DEFAULT_RACE_BY_REALM[realm]] || RACE.nordo;
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

  var webglSupported = null;

  /**
   * Comprobar WebGL cuesta un contexto, y el navegador solo deja tener unos
   * pocos abiertos a la vez. Se comprueba UNA vez, y el contexto de prueba se
   * cierra a proposito: si no, cada visor de la pagina se comia uno de los
   * pocos contextos disponibles solo para preguntar si podia usarlos.
   */
  function hasWebGL() {
    if (webglSupported !== null) { return webglSupported; }

    try {
      var canvas = document.createElement('canvas');
      var gl = window.WebGLRenderingContext
        && (canvas.getContext('webgl') || canvas.getContext('experimental-webgl'));

      if (gl) {
        var lose = gl.getExtension('WEBGL_lose_context');
        if (lose) { lose.loseContext(); }
      }

      webglSupported = !!gl;
    } catch (e) {
      webglSupported = false;
    }

    return webglSupported;
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

  function buildChampion(realm, subclass, race, gender) {
    var kit = subclassOf(subclass);
    var body = raceOf(race, realm);
    var sex = genderOf(gender);
    var accent = REALM_COLOR[realm] || REALM_COLOR.ignis;

    var g = new THREE.Group();
    var w = body.width;

    var armor = mat(kit.armor, 0.55, 0.45);
    var trim = mat(kit.trim, 0.4, 0.6);
    var cloth = mat(kit.cloth, 0.9, 0.05);
    var skin = mat(body.skin, 0.85, 0.0);
    var realmMat = mat(accent, 0.8, 0.15);

    /* Pedestal: no es del guerrero, marca el reino. */
    var plinth = new THREE.Mesh(new THREE.CylinderGeometry(1.02, 1.16, 0.18, 8), mat(0x241a18, 0.95, 0.05));
    plinth.position.y = 0.09;
    g.add(plinth);

    var figure = new THREE.Group();

    /* Piernas y botas */
    [-0.19 * w, 0.19 * w].forEach(function (x) {
      var leg = new THREE.Mesh(new THREE.CylinderGeometry(0.115 * w, 0.09 * w, 0.78, 6), cloth);
      leg.position.set(x, 0.57, 0);
      figure.add(leg);
      var boot = new THREE.Mesh(new THREE.BoxGeometry(0.24 * w, 0.16, 0.32), armor);
      boot.position.set(x, 0.25, 0.03);
      figure.add(boot);
    });

    /* Torso */
    var torso = new THREE.Mesh(
      new THREE.CylinderGeometry(0.34 * w * sex.chest, 0.27 * w * sex.waist, 0.72, 8),
      armor
    );
    torso.position.y = 1.3;
    figure.add(torso);

    var belt = new THREE.Mesh(
      new THREE.CylinderGeometry(0.29 * w * sex.waist, 0.29 * w * sex.hips, 0.09, 8),
      trim
    );
    belt.position.y = 0.98;
    figure.add(belt);

    if (sex.skirt) {
      // Falda corta sobre las piernas: la prenda pesa mas que cualquier otro
      // rasgo a la hora de leer la silueta de lejos.
      var skirt = new THREE.Mesh(
        new THREE.CylinderGeometry(0.3 * w * sex.hips, 0.44 * w * sex.hips, 0.44, 10, 1, true),
        cloth
      );
      skirt.material.side = THREE.DoubleSide;
      skirt.position.y = 0.79;
      figure.add(skirt);
    }

    /* Capa del reino: es lo unico que pinta el color del reino en el cuerpo,
       para que la armadura pueda decir la subclase sin pelearse con el. */
    var cape = new THREE.Mesh(
      new THREE.CylinderGeometry(0.33 * w, 0.46 * w, 1.05, 10, 1, true, Math.PI * 0.62, Math.PI * 0.76),
      realmMat
    );
    cape.material.side = THREE.DoubleSide;
    cape.position.set(0, 1.12, -0.07);
    figure.add(cape);

    /* Hombros y brazos. El caballero lleva hombreras grandes; el barbaro,
       hombro desnudo; los magos, tela. */
    [-0.42 * w * sex.chest, 0.42 * w * sex.chest].forEach(function (x) {
      var padMat = subclass === 'knight' ? trim : (subclass === 'barbarian' ? skin : armor);
      var pad = new THREE.Mesh(new THREE.SphereGeometry(0.2 * w, 7, 5), padMat);
      pad.scale.set(1, subclass === 'knight' ? 0.8 : 0.7, 1);
      pad.position.set(x, 1.55, 0);
      figure.add(pad);

      var arm = new THREE.Mesh(
        new THREE.CylinderGeometry(0.085 * w, 0.07 * w, 0.62, 6),
        subclass === 'barbarian' ? skin : cloth
      );
      arm.position.set(x * 1.05, 1.16, 0);
      arm.rotation.z = x > 0 ? -0.13 : 0.13;
      figure.add(arm);

      // Manos a la vista. Con yelmo, la cara casi no se ve y la raza se perdia:
      // las manos son la otra superficie de piel que queda, y bastan para
      // distinguir a un molok de un elfo del bosque a simple vista.
      var hand = new THREE.Mesh(new THREE.IcosahedronGeometry(0.085, 0), skin);
      hand.position.set(x * 1.12, 0.85, 0.02);
      figure.add(hand);
    });

    /* Cabeza */
    var neck = new THREE.Mesh(new THREE.CylinderGeometry(0.1, 0.12, 0.14, 6), skin);
    neck.position.y = 1.71;
    figure.add(neck);

    var head = new THREE.Mesh(new THREE.IcosahedronGeometry(0.21, 0), skin);
    head.position.y = 1.9;
    figure.add(head);

    addEars(figure, body, skin);
    if (body.horns) { addHorns(figure, mat(0xd8cbb4, 0.7, 0.1)); }
    if (body.tusks) { addTusks(figure, mat(0xe0d6c2, 0.6, 0.1)); }
    // La barba solo en los personajes masculinos; en los femeninos se cambia
    // por una melena, que cumple la misma funcion (reconocer a la raza) sin
    // dar por hecho como debe verse una enana.
    if (body.beard) {
      if (gender === 'female') {
        addHair(figure, mat(0xb9a382, 0.95, 0.0));
      } else {
        addBeard(figure, mat(0xb9a382, 0.95, 0.0));
      }
    }

    addHeadgear(figure, kit, armor, trim, realmMat);
    addWeapon(figure, kit, subclass, armor, trim, accent, w);

    // La raza escala al guerrero entero menos el pedestal: un enano de verdad
    // se ve bajo al lado de un molok, no solo distinto de cara.
    figure.scale.setScalar(body.scale);
    g.add(figure);

    // El encuadre mide al guerrero, no al pedestal: el disco es mas ancho que
    // el propio personaje y, contandolo, la camara retrocedia tanto que el
    // guerrero quedaba pequeno en un mar de suelo.
    g.userData.figure = figure;

    return g;
  }

  function addEars(figure, body, skin) {
    var shape = body.ears;
    if (!shape || shape === 'none') { return; }

    var length = shape === 'big' ? 0.3 : (shape === 'pointed' ? 0.26 : 0.15);
    var radius = shape === 'big' ? 0.1 : 0.06;

    [-1, 1].forEach(function (side) {
      var ear = new THREE.Mesh(new THREE.ConeGeometry(radius, length, 5), skin);
      ear.position.set(side * 0.19, shape === 'big' ? 2.02 : 1.95, -0.02);
      // Las grandes apuntan arriba y afuera; las puntiagudas, hacia atras.
      ear.rotation.z = side * (shape === 'big' ? -0.35 : -1.05);
      ear.rotation.x = shape === 'big' ? -0.1 : 0.35;
      figure.add(ear);
    });
  }

  function addHorns(figure, hornMat) {
    [-1, 1].forEach(function (side) {
      var horn = new THREE.Mesh(new THREE.ConeGeometry(0.06, 0.34, 5), hornMat);
      horn.position.set(side * 0.16, 2.05, -0.02);
      horn.rotation.z = side * -0.55;
      figure.add(horn);
    });
  }

  function addTusks(figure, tuskMat) {
    // Colmillos hacia arriba. La piel oscura del molok apenas se ve bajo el
    // yelmo; los colmillos si, y no dependen de la iluminacion.
    [-1, 1].forEach(function (side) {
      var tusk = new THREE.Mesh(new THREE.ConeGeometry(0.035, 0.2, 4), tuskMat);
      tusk.position.set(side * 0.1, 1.87, 0.16);
      tusk.rotation.x = -0.25;
      figure.add(tusk);
    });
  }

  function addHair(figure, hairMat) {
    var hair = new THREE.Mesh(
      new THREE.CylinderGeometry(0.24, 0.2, 0.44, 8, 1, true),
      hairMat
    );
    hair.material.side = THREE.DoubleSide;
    hair.position.set(0, 1.78, -0.03);
    figure.add(hair);
  }

  function addBeard(figure, beardMat) {
    // Por delante del yelmo y colgando de la barbilla: metida entre la cabeza y
    // el casco no se veia, que es justo lo unico que hace reconocible a un enano.
    var beard = new THREE.Mesh(new THREE.ConeGeometry(0.22, 0.52, 6), beardMat);
    beard.position.set(0, 1.72, 0.14);
    beard.rotation.set(Math.PI, 0, 0);
    figure.add(beard);

    var moustache = new THREE.Mesh(new THREE.BoxGeometry(0.3, 0.07, 0.08), beardMat);
    moustache.position.set(0, 1.87, 0.17);
    figure.add(moustache);
  }

  function addHeadgear(figure, kit, armor, trim, realmMat) {
    if (kit.head === 'helm') {
      var helm = new THREE.Mesh(new THREE.SphereGeometry(0.235, 8, 6, 0, Math.PI * 2, 0, Math.PI * 0.62), armor);
      helm.position.y = 1.92;
      figure.add(helm);
      var crest = new THREE.Mesh(new THREE.BoxGeometry(0.05, 0.24, 0.34), realmMat);
      crest.position.set(0, 2.06, -0.02);
      figure.add(crest);
      return;
    }

    if (kit.head === 'hood') {
      var hood = new THREE.Mesh(new THREE.SphereGeometry(0.27, 8, 6, 0, Math.PI * 2, 0, Math.PI * 0.58), trim);
      hood.position.set(0, 1.9, -0.03);
      hood.rotation.x = -0.16;
      figure.add(hood);
      var peak = new THREE.Mesh(new THREE.ConeGeometry(0.16, 0.3, 6), trim);
      peak.position.set(0, 1.98, -0.19);
      peak.rotation.x = 0.75;
      figure.add(peak);
      return;
    }

    if (kit.head === 'circlet') {
      var circlet = new THREE.Mesh(new THREE.TorusGeometry(0.2, 0.026, 6, 16), trim);
      circlet.position.y = 1.96;
      circlet.rotation.x = Math.PI / 2;
      figure.add(circlet);
    }
    // 'bare': el barbaro va a cara descubierta, y eso ya lo distingue.
  }

  function addWeapon(figure, kit, subclass, armor, trim, accent, w) {
    if (kit.weapon === 'sword') {
      var blade = new THREE.Mesh(new THREE.BoxGeometry(0.075, 1.15, 0.03), mat(0xc9cdd4, 0.3, 0.9));
      blade.position.set(0.52 * w, 1.32, 0.14);
      blade.rotation.z = -0.1;
      figure.add(blade);
      var guard = new THREE.Mesh(new THREE.BoxGeometry(0.3, 0.06, 0.07), trim);
      guard.position.set(0.5 * w, 0.82, 0.14);
      figure.add(guard);

      // Escudo: es lo que separa al caballero del barbaro de un vistazo.
      var shield = new THREE.Mesh(new THREE.CylinderGeometry(0.3, 0.25, 0.05, 6), trim);
      shield.position.set(-0.54 * w, 1.22, 0.16);
      shield.rotation.set(Math.PI / 2, 0, 0.12);
      figure.add(shield);
      var boss = new THREE.Mesh(new THREE.SphereGeometry(0.08, 7, 5), mat(accent, 0.5, 0.5));
      boss.position.set(-0.54 * w, 1.22, 0.2);
      figure.add(boss);
      return;
    }

    if (kit.weapon === 'axe') {
      // Hacha a dos manos: mango largo y cabeza ancha, agarrada con las dos.
      var haft = new THREE.Mesh(new THREE.CylinderGeometry(0.045, 0.045, 1.75, 6), mat(0x5c4026, 0.9, 0.05));
      haft.position.set(0.42 * w, 1.28, 0.2);
      haft.rotation.z = -0.22;
      figure.add(haft);

      var headA = new THREE.Mesh(new THREE.BoxGeometry(0.12, 0.46, 0.04), mat(0xb9c0c8, 0.35, 0.85));
      headA.position.set(0.62 * w, 2.0, 0.2);
      headA.rotation.z = -0.22;
      figure.add(headA);
      var edge = new THREE.Mesh(new THREE.ConeGeometry(0.24, 0.34, 3), mat(0xd2d8de, 0.3, 0.9));
      edge.position.set(0.74 * w, 2.0, 0.2);
      edge.rotation.set(Math.PI / 2, 0, -1.79);
      figure.add(edge);
      return;
    }

    if (kit.weapon === 'bow') {
      var bow = new THREE.Mesh(new THREE.TorusGeometry(0.44, 0.026, 5, 18, Math.PI * 1.05), mat(0x6b4a2c, 0.8, 0.1));
      bow.position.set(0.6 * w, 1.3, 0.1);
      bow.rotation.set(0, 0, Math.PI * 1.48);
      figure.add(bow);
      var string = new THREE.Mesh(new THREE.CylinderGeometry(0.007, 0.007, 0.84, 4), mat(0xd8cbb4, 0.7, 0.1));
      string.position.set(0.49 * w, 1.3, 0.1);
      figure.add(string);

      var quiver = new THREE.Mesh(new THREE.CylinderGeometry(0.09, 0.09, 0.46, 6), trim);
      quiver.position.set(-0.3 * w, 1.42, -0.22);
      quiver.rotation.z = 0.34;
      figure.add(quiver);
      return;
    }

    // Baculo: el orbe se enciende con el color de la subclase, no del reino,
    // porque es lo que separa al conjurador del brujo cuando los dos son del
    // mismo reino.
    var staff = new THREE.Mesh(new THREE.CylinderGeometry(0.032, 0.032, 1.7, 6), mat(0x5c4026, 0.85, 0.1));
    staff.position.set(0.55 * w, 1.15, 0.1);
    staff.rotation.z = -0.06;
    figure.add(staff);

    var glow = subclass === 'warlock' ? 0x9a6ad0 : 0xf6efdd;
    var orb = new THREE.Mesh(
      new THREE.IcosahedronGeometry(0.14, 0),
      new THREE.MeshStandardMaterial({
        color: new THREE.Color(glow), emissive: new THREE.Color(glow),
        emissiveIntensity: 1.5, roughness: 0.3
      })
    );
    orb.position.set(0.5 * w, 1.99, 0.1);
    figure.add(orb);

    var halo = new THREE.PointLight(glow, 2.6, 2.1, 2);
    halo.position.copy(orb.position);
    figure.add(halo);
  }

  /* ─────────── carga de modelos reales ─────────── */

  /**
   * Nombres de archivo que valen para este guerrero, del mas concreto al mas
   * general. Asi se puede subir un modelo por raza cuando se tenga, y mientras
   * tanto sirve uno solo para todo el reino y la subclase.
   */
  function modelCandidates(realm, subclass, race, gender) {
    var sex = gender || 'male';
    var names = [];

    if (race) {
      // Cuerpo y armadura completos, lo mas concreto que puede haber.
      names.push(realm + '-' + race + '-' + sex + '-' + subclass);
      // Solo el cuerpo: la raza y el sexo mandan, y la ropa la pone el reino.
      // Este es el nivel de los 24 modelos base (12 combinaciones de reino y
      // raza, por dos sexos).
      names.push(realm + '-' + race + '-' + sex);
    }

    // Respaldo antiguo: un modelo por reino y subclase, sin raza ni sexo.
    names.push(realm + '-' + subclass);
    return names;
  }

  /**
   * Que modelos existen se sabe ANTES de pedir nada: el servidor lista los
   * archivos de public/models al pintar la pagina. Comprobarlo con una peticion
   * por personaje llenaria la consola de 404 y gastaria un viaje de ida y vuelta
   * cada vez que alguien cambia de guerrero en el rail.
   */
  function pickModel(realm, subclass, race, gender) {
    var available = window.arenaChampionModels;
    if (!available || !available.length) { return null; }

    var names = modelCandidates(realm, subclass, race, gender);
    for (var i = 0; i < names.length; i++) {
      if (available.indexOf(names[i]) !== -1) { return '/models/' + names[i] + '.glb'; }
    }
    return null;
  }

  function tryLoadModel(realm, subclass, race, gender) {
    var url = pickModel(realm, subclass, race, gender);
    if (!url) { return Promise.resolve(null); }

    if (Object.prototype.hasOwnProperty.call(modelCache, url)) {
      return Promise.resolve(modelCache[url] ? borrowFromCache(modelCache[url]) : null);
    }

    return needLoader().then(function () {
      return new Promise(function (resolve) {
        new THREE.GLTFLoader().load(url, function (gltf) {
          modelCache[url] = gltf.scene;
          resolve(borrowFromCache(gltf.scene));
        }, undefined, function () {
          modelCache[url] = null;
          resolve(null);
        });
      });
    }).catch(function () { return null; });
  }

  /**
   * Copia de trabajo de un modelo cacheado.
   *
   * `clone()` de Three comparte la geometria y los materiales con el original,
   * asi que liberar la copia al cambiar de guerrero destruiria los recursos del
   * modelo guardado: el cache dejaria de ahorrar nada y cada cambio volveria a
   * subir buffers y a compilar shaders. Se marca la copia como prestada para
   * que no se libere.
   */
  function borrowFromCache(source) {
    var copy = source.clone(true);
    copy.userData.borrowed = true;
    return copy;
  }

  /** Encaja cualquier modelo en la misma altura y centro que la silueta. */
  function normalize(object) {
    object.updateMatrixWorld(true);
    var box = new THREE.Box3().setFromObject(object);
    var size = box.getSize(new THREE.Vector3());
    var center = box.getCenter(new THREE.Vector3());
    var scale = size.y > 0 ? 2.1 / size.y : 1;

    var wrapper = new THREE.Group();
    object.position.set(-center.x * scale, -box.min.y * scale, -center.z * scale);
    object.scale.setScalar(scale);
    wrapper.add(object);
    wrapper.userData.borrowed = object.userData.borrowed;
    return wrapper;
  }

  /* ─────────── el visor ─────────── */

  function mount(canvas, options) {
    options = options || {};
    var host = canvas.parentElement;

    if (!hasWebGL()) {
      markFallbackState(host, 'unsupported');
      paintFallback(host, options.realm);
      return {
        set: function (realm) { paintFallback(host, realm); },
        dispose: function () {},
        available: false
      };
    }

    var api = { available: true };
    var renderer, scene, camera, rim, ring, champion, raf = null;
    var observer = null, resizeObserver = null, onPointerMove = null;
    var visible = true, spin = 0, grow = 0, token = 0;
    var pointer = { x: 0 };
    var current = {
      realm: options.realm || 'ignis',
      subclass: options.subclass || 'knight',
      race: options.race || null,
      gender: options.gender || null
    };

    markFallbackState(host, 'loading');

    needThree().then(function () {
      build();
      setChampion(current.realm, current.subclass, current.race, current.gender);
      watchVisibility();
      hideFallback(host);
    }).catch(function () {
      markFallbackState(host, 'unsupported');
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
        resizeObserver = new ResizeObserver(resize);
        resizeObserver.observe(host);
      }

      if (options.parallax !== false) {
        onPointerMove = function (e) {
          var r = canvas.getBoundingClientRect();
          pointer.x = ((e.clientX - r.left) / r.width - 0.5) * 2;
        };
        host.addEventListener('pointermove', onPointerMove);
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
        // Sin esto la caja se mide con matrices sin actualizar y sale vacia: el
        // guerrero vive dentro de un subgrupo escalado por la raza, y ese
        // escalado no cuenta hasta que las matrices se recalculan.
        champion.updateMatrixWorld(true);
        var subject = champion.userData.figure || champion;
        var box = new THREE.Box3().setFromObject(subject);
        if (box.isEmpty() === false) {
          var size = box.getSize(new THREE.Vector3());
          // Se encuadra contra la altura de un humano, no contra la del modelo:
          // si la camara se ajustase a cada uno, un enano llenaria el cuadro
          // igual que un molok y la estatura dejaria de significar nada. Solo
          // se agranda cuando el guerrero es mas alto que la referencia.
          height = Math.max(FRAME_HEIGHT, size.y * 1.24);
          width = Math.max(FRAME_WIDTH * 0.8, size.x * 1.2, size.z * 1.2);
          center = box.min.y + height / 2;
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

      // En un escenario muy apaisado el guerrero se quedaba clavado en el
      // centro con dos franjas de suelo vacio a los lados, y el nombre le
      // caia justo debajo. Se desplaza a la derecha para que el rotulo tenga
      // su propio sitio: la composicion pasa a estar decidida en vez de ser
      // el resultado de centrarlo todo.
      // El desplazamiento se mide contra el guerrero, no contra el ancho de la
      // escena: en un escenario muy apaisado (el de la cola es 4:1) un
      // porcentaje del ancho visible lo mandaba fuera del cuadro.
      var visibleWidth = 2 * dist * Math.tan(hFov / 2);
      var shift = camera.aspect > 1.8
        ? Math.min(visibleWidth * 0.14, width * 0.42)
        : 0;

      camera.position.set(-shift, center + lift, dist);
      camera.lookAt(-shift, center, 0);
      camera.updateProjectionMatrix();
    }

    function setChampion(realm, subclass, race, gender) {
      current.realm = realm;
      current.subclass = subclass;
      current.race = race || null;
      current.gender = gender || null;
      if (!scene) { return; }

      var mine = ++token;
      var color = REALM_COLOR[realm] || REALM_COLOR.ignis;
      rim.color = new THREE.Color(color);
      ring.material.color = new THREE.Color(color);

      swap(buildChampion(realm, subclass, current.race, current.gender));

      tryLoadModel(realm, subclass, current.race, current.gender).then(function (model) {
        if (model && mine === token) { swap(normalize(model)); }
      });
    }

    function swap(group) {
      if (champion) {
        scene.remove(champion);
        // Lo prestado no se destruye: sus recursos son del modelo cacheado.
        if (!champion.userData.borrowed) { disposeTree(champion); }
      }
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

    function onVisibilityChange() {
      visible = document.visibilityState !== 'hidden';
      if (visible) { start(); } else { stop(); }
    }

    function watchVisibility() {
      if ('IntersectionObserver' in window) {
        observer = new IntersectionObserver(function (entries) {
          visible = entries[0].isIntersecting && document.visibilityState !== 'hidden';
          if (visible) { start(); } else { stop(); }
        }, { threshold: 0.05 });
        observer.observe(host);
      }
      document.addEventListener('visibilitychange', onVisibilityChange);
    }

    function disposeTree(obj) {
      obj.traverse(function (node) {
        if (node.geometry) { node.geometry.dispose(); }
        if (node.material) {
          (Array.isArray(node.material) ? node.material : [node.material]).forEach(function (m) { m.dispose(); });
        }
      });
    }

    api.set = function (realm, subclass, race, gender) {
      paintFallback(host, realm);
      setChampion(realm, subclass, race, gender);
    };
    api.dispose = function () {
      stop();
      window.removeEventListener('resize', resize);
      document.removeEventListener('visibilitychange', onVisibilityChange);
      if (onPointerMove) { host.removeEventListener('pointermove', onPointerMove); }
      if (observer) { observer.disconnect(); }
      if (resizeObserver) { resizeObserver.disconnect(); }
      // Se saca al guerrero de la escena ANTES de liberar el resto: si es una
      // copia prestada del cache, barrer la escena entera destruiria tambien
      // los recursos del modelo guardado.
      if (champion && scene) { scene.remove(champion); }
      if (champion && !champion.userData.borrowed) { disposeTree(champion); }
      if (scene) { disposeTree(scene); }
      if (renderer) {
        renderer.dispose();
        // Devuelve el contexto al navegador en vez de esperar al recolector.
        var ctx = renderer.getContext();
        var lose = ctx && ctx.getExtension && ctx.getExtension('WEBGL_lose_context');
        if (lose) { lose.loseContext(); }
      }
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

  /**
   * Mientras se descarga Three.js el emblema del reino se queda, pero SIN el
   * aviso de "no disponible": son 600 KB y en una conexion lenta la pagina
   * pasaba varios segundos afirmando algo que era mentira. El aviso solo
   * aparece cuando de verdad no se puede dibujar.
   */
  function markFallbackState(host, state) {
    var el = host.querySelector('[data-champion-fallback]');
    if (el) { el.dataset.championState = state; }
  }

  function paintFallback(host, realm) {
    var glyph = host.querySelector('[data-champion-glyph]');
    if (glyph) { glyph.textContent = REALM_GLYPH[realm] || REALM_GLYPH.ignis; }
  }

  return {
    mount: mount,
    realmColor: REALM_COLOR,
    realmGlyph: REALM_GLYPH,
    // Se expone para poder inspeccionar una silueta sin montar un visor
    // entero: es lo que usan las pruebas visuales.
    build: buildChampion,
    subclasses: SUBCLASS,
    races: RACE,
    genders: GENDER
  };
})();
