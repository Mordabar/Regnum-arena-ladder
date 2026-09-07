/**
 * Decodificador Draco para el visor de guerreros.
 *
 * Los modelos que llegan traen la malla partida en muchas piezas, con una
 * costura de textura por cada trozo. El simplificador no puede colapsar un
 * borde que es frontera, asi que se atasca a media reduccion y los utghar se
 * quedaban en 32.000 triangulos y cerca de 1,5 MB. Draco comprime la geometria
 * tal cual esta, sin tocar la forma, y eso si baja el peso a la tercera parte.
 *
 * Three.js trae el enganche pero no el decodificador. Este es el minimo que
 * pide GLTFLoader: `preload` y `decodeDracoFile`. El modulo de Draco y su wasm
 * estan vendorizados al lado, como Three.js: nada se descarga de fuera.
 */
window.ArenaDracoLoader = (function () {
    var TIPOS = {
        position: 'POSITION',
        normal: 'NORMAL',
        color: 'COLOR',
        uv: 'TEX_COORD',
        uv2: 'TEX_COORD',
        skinIndex: 'GENERIC',
        skinWeight: 'GENERIC',
        tangent: 'GENERIC'
    };

    function crear(rutaModulo, rutaWasm) {
        var modulo = null;

        // Se carga una sola vez y se comparte: el lobby monta varios visores y
        // cada uno pediria su propia copia del wasm.
        function cargar() {
            if (modulo) { return modulo; }

            modulo = new Promise(function (resolve, reject) {
                traerGuion(rutaModulo).then(function () {
                    return fetch(rutaWasm).then(function (r) {
                        if (!r.ok) { throw new Error('No se pudo traer ' + rutaWasm); }
                        return r.arrayBuffer();
                    });
                }).then(function (wasm) {
                    return window.DracoDecoderModule({ wasmBinary: wasm });
                }).then(resolve, function (error) {
                    // Se deja listo para reintentar en vez de quedarse clavado.
                    modulo = null;
                    reject(error);
                });
            });

            return modulo;
        }

        function decodificar(draco, buffer, atributos, tipos) {
            var decoder = new draco.Decoder();
            var bufferDraco = new draco.DecoderBuffer();
            bufferDraco.Init(new Int8Array(buffer), buffer.byteLength);

            var tipoGeometria = decoder.GetEncodedGeometryType(bufferDraco);
            var malla;

            if (tipoGeometria === draco.TRIANGULAR_MESH) {
                malla = new draco.Mesh();
                var estado = decoder.DecodeBufferToMesh(bufferDraco, malla);
                if (!estado.ok() || malla.ptr === 0) {
                    limpiar(draco, [bufferDraco, decoder, malla]);
                    throw new Error('Draco no pudo decodificar la malla.');
                }
            } else {
                limpiar(draco, [bufferDraco, decoder]);
                throw new Error('El visor solo carga mallas de triangulos.');
            }

            var geometria = new THREE.BufferGeometry();

            // Indices: tres por cara.
            var numCaras = malla.num_faces();
            var indices = new Uint32Array(numCaras * 3);
            var cara = new draco.DracoInt32Array();

            for (var i = 0; i < numCaras; i++) {
                decoder.GetFaceFromMesh(malla, i, cara);
                indices[i * 3] = cara.GetValue(0);
                indices[i * 3 + 1] = cara.GetValue(1);
                indices[i * 3 + 2] = cara.GetValue(2);
            }

            draco.destroy(cara);
            geometria.setIndex(new THREE.BufferAttribute(indices, 1));

            Object.keys(atributos).forEach(function (nombre) {
                var tipoDraco = TIPOS[nombre] || 'GENERIC';
                var id = decoder.GetAttributeByUniqueId(malla, atributos[nombre]);
                if (!id || id.ptr === 0) { return; }

                var atributo = leerAtributo(draco, decoder, malla, id, tipos[nombre] || Float32Array);
                geometria.setAttribute(nombre, new THREE.BufferAttribute(atributo.array, atributo.itemSize));
            });

            limpiar(draco, [bufferDraco, malla, decoder]);
            return geometria;
        }

        /* Cada tipo de dato tiene su lector en Draco: pedir float donde el
           modelo guardo enteros devuelve basura silenciosa. */
        function leerAtributo(draco, decoder, malla, atributo, TipoArray) {
            var itemSize = atributo.num_components();
            var numPuntos = malla.num_points();
            var total = numPuntos * itemSize;
            var lector, arrayDraco;

            if (TipoArray === Float32Array) {
                arrayDraco = new draco.DracoFloat32Array();
                decoder.GetAttributeFloatForAllPoints(malla, atributo, arrayDraco);
            } else if (TipoArray === Int8Array) {
                arrayDraco = new draco.DracoInt8Array();
                decoder.GetAttributeInt8ForAllPoints(malla, atributo, arrayDraco);
            } else if (TipoArray === Int16Array) {
                arrayDraco = new draco.DracoInt16Array();
                decoder.GetAttributeInt16ForAllPoints(malla, atributo, arrayDraco);
            } else if (TipoArray === Int32Array) {
                arrayDraco = new draco.DracoInt32Array();
                decoder.GetAttributeInt32ForAllPoints(malla, atributo, arrayDraco);
            } else if (TipoArray === Uint8Array) {
                arrayDraco = new draco.DracoUInt8Array();
                decoder.GetAttributeUInt8ForAllPoints(malla, atributo, arrayDraco);
            } else if (TipoArray === Uint16Array) {
                arrayDraco = new draco.DracoUInt16Array();
                decoder.GetAttributeUInt16ForAllPoints(malla, atributo, arrayDraco);
            } else {
                arrayDraco = new draco.DracoUInt32Array();
                decoder.GetAttributeUInt32ForAllPoints(malla, atributo, arrayDraco);
            }

            var salida = new TipoArray(total);
            for (var i = 0; i < total; i++) { salida[i] = arrayDraco.GetValue(i); }

            draco.destroy(arrayDraco);
            return { array: salida, itemSize: itemSize };
        }

        function limpiar(draco, objetos) {
            objetos.forEach(function (o) { if (o) { draco.destroy(o); } });
        }

        return {
            // GLTFLoader lo llama al detectar la extension: adelanta la carga.
            preload: function () { cargar().catch(function () {}); return this; },

            decodeDracoFile: function (buffer, alListo, atributos, tipos) {
                cargar().then(function (draco) {
                    alListo(decodificar(draco, buffer, atributos || {}, tipos || {}));
                }).catch(function (error) {
                    console.error(error);
                });
            },

            dispose: function () { return this; }
        };
    }

    function traerGuion(src) {
        if (window.DracoDecoderModule) { return Promise.resolve(); }

        return new Promise(function (resolve, reject) {
            var el = document.createElement('script');
            el.src = src;
            el.onload = resolve;
            el.onerror = function () { reject(new Error('No se pudo cargar ' + src)); };
            document.head.appendChild(el);
        });
    }

    return { create: crear };
})();
