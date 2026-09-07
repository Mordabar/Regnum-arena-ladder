# Alpha 2 — Anuncio y changelog

Dos piezas. El **anuncio** es para todo el mundo y busca que la gente entre a
probar. El **changelog** es la lista completa, para quien quiera el detalle.

---

## 1. ANUNCIO (mensaje principal de Discord)

> # ⚔️ REGNUM ARENA LADDER — ALPHA 2
>
> Hemos reconstruido el ladder de arriba abajo. Esto es lo que os vais a
> encontrar.
>
> **🗿 Vuestros guerreros, en 3D**
> **60 modelos** de los tres reinos. Nordos, lamai, enanos, utghar, esquelios,
> elfos oscuros, moloks, alturianos, semielfos y elfos del bosque. Cada raza,
> cada sexo, con el equipo de guerrero, arquero o mago. Lo montáis al crear el
> personaje y lo veis antes de confirmar.
>
> **⚔️ 2v2 y 3v3**
> Dos modalidades, un solo ladder. Cambiáis de una a otra con un clic, sobre
> vuestro propio guerrero, y las colas y los grupos se adaptan solos.
>
> **🏟️ Todo en una pantalla**
> Elegís guerrero, entráis a cola, os salta el cruce, peleáis y reportáis sin
> movimiento de una página a otra. Con su reloj y el mapa de la zona a un clic.
>
> **🔔 No hace falta estar mirando**
> Os avisamos con sonido cuando aparece rival, cuando alguien os invita a
> grupo y cuando hay un reporte esperando. La pantalla se actualiza sola.
>
> **🛡️ Ninguna partida se queda colgada**
> Si nadie reporta, se anula. Si vuestro rival no contesta, se confirma sola.
> Las disputas caducan. Nadie pierde puntos por algo que se quedó a medias.
>
> **📊 Un ranking que cuadra**
> Corregido de raíz un fallo que repartía puntos que nadie había ganado. El
> ladder de hoy responde partida por partida.
>
> Y por debajo: móvil revisado de punta a punta, sonido arreglado en iPhone,
> anonimato del rival hasta que acaba el combate, y un panel de moderación
> que permite arreglar cualquier cosa sin tocar la base de datos.
>
> 👉 **regnumarenaladder.top**
>
> Changelog completo en el hilo. Es una alpha: si algo falla, contadlo aquí y
> se arregla.

---

## 2. CHANGELOG COMPLETO (para el hilo)

Discord corta los mensajes en 2000 caracteres, asi que el changelog va en tres.
Los cortes limpios estan entre MODALIDADES/GUERREROS/LOBBY (primero),
CRUCE/COMBATE/PARTIDAS/GRUPOS (segundo) y AVISOS en adelante (tercero).


> # 📜 ALPHA 2 · Changelog
>
> **⚔️ MODALIDADES**
> · 2v2 y 3v3 conviviendo, con un único ladder compartido.
> · La modalidad se elige sobre tu propio guerrero, y los botones de entrar y
>   de invitar cambian con ella.
> · Cada modalidad se puede encender y apagar por separado desde el panel.
> · Los grupos se ajustan al tamaño de la modalidad: 2 o 3 huecos.
>
> **🗿 GUERREROS EN 3D**
> · 60 modelos, los tres reinos completos, por raza, sexo y arquetipo.
> · 12 razas: nordo, utghar, enano, lamai, esquelio, elfo oscuro, molok,
>   alturiano, elfo del bosque, semielfo.
> · Cada raza solo ofrece las subclases que le corresponden en el juego.
> · Nombre, raza y sexo se pueden cambiar después. Reino y subclase no.
> · Se ven en el lobby, en el creador, en el cruce, en el combate, en la ficha
>   del ladder y en la portada.
> · Los guerreros sin modelo aún salen con una silueta propia por subclase.
>
> **🏟️ LOBBY Y ARENA, UNA SOLA PANTALLA**
> · Lobby y arena fusionados. Se elige guerrero y se entra a combatir sin
>   cambiar de página.
> · Todas las acciones del guerrero -editar, eliminar, entrar, invitar,
>   reglas- viven sobre la propia figura.
> · Tu escuadra en un cajón lateral, que en móvil se abre solo cuando hace
>   falta.
> · Sin JavaScript el lobby sigue funcionando: cambiar de guerrero, entrar a
>   cola, aceptar y rechazar son enlaces y formularios de verdad.
>
> **🎯 EL CRUCE**
> · El aviso de rival encontrado se apodera de la pantalla, con anillo de
>   cuenta atrás que se pone rojo en los últimos 20 segundos.
> · Las dos alineaciones enfrentadas, con quién ha aceptado y quién falta.
> · Aceptar te devuelve a la cola, no a otra página.
> · El nombre del rival no se ve hasta que acaba el combate. Su subclase sí,
>   porque hace falta para prepararse.
> · La zona asignada abre su mapa.
>
> **📝 EL COMBATE Y EL REPORTE**
> · Panel de combate con el reloj real que tienes para pelear y reportar.
> · El reporte se sube desde la misma pantalla, con sus capturas.
> · Confirmar o rechazar el reporte del rival, con motivo, también ahí.
> · Sin salidas: durante el combate no hay botones que te saquen del flujo.
>
> **🛡️ NINGUNA PARTIDA QUEDA ABIERTA**
> · Si nadie reporta antes de que venza el plazo, la partida se anula.
> · Si el rival deja pasar su plazo sin contestar, el reporte se da por bueno.
> · Las disputas caducan solas (48 horas por defecto, configurable).
> · Moderación puede corregir cualquier resultado después, y eso reajusta los
>   puntos de las partidas posteriores.
>
> **👥 GRUPOS E INVITACIONES**
> · Invitar abre una ventana y punto: tú ya eres el líder, solo buscas
>   compañero.
> · Las invitaciones llegan como aviso flotante, diciendo a qué modalidad te
>   invitan y con qué personaje tuyo.
> · Se pueden plegar sin contestarlas y volver a abrirlas cuando quieras.
> · La party se ve dentro del escenario, con la figura de cada integrante y
>   los huecos libres.
> · El panel dice por su nombre a quién falta por aceptar.
>
> **🔔 AVISOS Y TIEMPO REAL**
> · Sonido para cruce encontrado, invitación de grupo, party lista, comienzo
>   del combate, reporte pendiente y resultado confirmado.
> · La pantalla se actualiza sola, sin recargar y sin perder el scroll.
> · El aviso se reintenta al volver a la app, así que ya suena en iPhone tras
>   bloquear la pantalla.
> · Al encender las alertas suena un toque de prueba, para que sepas si el
>   móvil está en silencio.
>
> **📊 RANKING**
> · Corregido un fallo que regalaba puntos: perder estando a 0 PL ya no suma.
> · El podio de los tres reinos abre el ladder, en 3D.
> · Corregido el porcentaje de victorias, que llegaba a marcar 200%.
> · Buscador y filtros por reino y subclase.
>
> **📱 MÓVIL Y ACCESIBILIDAD**
> · Revisado de 360 a 1440 píxeles, pantalla por pantalla.
> · Corregido el desbordamiento horizontal que aparecía en tablet.
> · Las ventanas se cierran con Escape, atrapan el tabulador y devuelven el
>   foco.
> · Contraste revisado sobre las escenas 3D, que son fondo vivo.
> · Con "reducir movimiento" activado, el guerrero se queda quieto.
>
> **🛠️ PANEL DE MODERACIÓN**
> · Rediseñado con la identidad del sitio.
> · Borrar enfrentamientos elegidos, devolviendo a cada jugador lo que le
>   repartieron.
> · Revisar el ranking sin tocarlo, recalcularlo, o reiniciarlo entero.
> · Confirmar un reporte en nombre del rival, para desatascar.
> · Entorno de pruebas con bots que pueden reportar y contestar, para ensayar
>   el flujo completo sin dos cuentas.
>
> **⚡ POR DEBAJO**
> · Los modelos pesan 18 MB para los 60, con compresión Draco.
> · Nada se carga desde servidores externos: si un CDN cae, el sitio no se
>   queda cojo.
> · El 3D se detiene cuando no se ve, para no gastar batería.
> · 244 pruebas automáticas cubriendo combate, ranking y panel.
