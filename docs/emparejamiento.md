# Cómo se empareja la cola

Vale para las tres modalidades: 1v1, 2v2 y 3v3. El código está en
`app/Services/ArenaMatchmakingService.php`.

## Las dos promesas

En este orden, y el orden importa:

1. **Nadie se queda en cola habiendo alguien con quien jugar.** Más vale un
   cruce regular que quedarse mirando.
2. **Dentro de eso, los cruces son los mejores que se pueden armar.**

La segunda nunca se come a la primera. Si para que jueguen dos personas más hay
que romper el cruce más ajustado de la tabla, se rompe.

## Qué decide si un cruce es bueno

Cada cruce posible se puntúa, y cuanto más bajo mejor. La base es la
**diferencia de MMR** entre los dos bandos, y encima se suman recargos:

| Recargo | Cuánto | Por qué |
|---|---|---|
| Repetir un cruce de las últimas 24 h | 10000 | Que no te toque el mismo rival una y otra vez |
| Solaparse con una partida reciente | 900 | Lo mismo, pero cuando se repite parte del equipo |
| Composición dispar (2v2 y 3v3) | variable | Que no se enfrenten plantillas muy distintas |
| Estilo distinto (solo 1v1) | 8 o 20 | La preferencia de duelo: arquero contra arquero |

Todo está en la misma escala, que son puntos de MMR. Así "evitar una repetición
vale 900" quiere decir exactamente eso.

## Los seis pasos

1. **Puntuar una vez.** Cada cruce legal se puntúa una sola vez, y solo entre
   equipos cercanos en MMR (una ventana de 80 puestos). Mirar la cola entera
   contra la cola entera es lo que no escala.
2. **Barrer en orden.** Se recorren los cruces del mejor al peor y se toma el
   que tenga los dos lados libres.
3. **Rescatar a los sueltos.** El barrido puede dejar gente fuera: si se lleva
   primero el cruce más ajustado, los que quedan pueden ser todos del mismo
   reino y entre ellos no hay partida. Se deshace un cruce cuyos dos lados estén
   fuera de ese reino y se rehace con dos sueltos. Los sueltos se agrupan por
   **modalidad y reino**, porque las tres colas se reparten a la vez y un cruce
   de 2v2 no deja hueco a nadie que espere un duelo.
4. **Cambiar emparejado por banquillo.** Cuando no caben partidas para todos,
   quien sobra no tiene por qué ser el que peor encajaba.
5. **Intercambiar rivales entre cruces vecinos** mientras el conjunto mejore.
6. **Repartir tres cruces a la vez** cuando tocando dos no se mejora. Hay
   repartos atascados donde ningún intercambio entre dos ayuda y moviendo tres
   sí. Solo se prueba con los peores cruces, que son los únicos con algo que
   ganar.

Los pasos 4, 5 y 6 se alternan, porque cada uno abre jugadas a los otros.

## Por qué el paso 3 basta

El grafo de cruces posibles es "todos contra todos menos los de tu reino". Si
quedan dos sueltos de reinos distintos, el paso anterior ya los casó. Si todos
los sueltos son del mismo reino y aún cabe otra partida, forzosamente existe un
cruce hecho con sus **dos** lados fuera de ese reino, y deshacerlo da sitio a
dos sueltos. Cuando no existe ese cruce es que ya no caben más partidas.

La excepción teórica es el veto de "una cuenta no juega contra sí misma", que
quita alguna arista suelta. En la práctica no se llega, porque entrar a la cola
bloquea la cuenta entera.

## Varios jugadores a la vez

El emparejamiento corre **dentro de la petición HTTP** de quien entra a la cola.
No hay worker de colas en un hosting compartido, así que no hay a dónde
mandarlo.

Para que cinco personas entrando a la vez no lancen cinco barridos en paralelo
sobre las mismas filas, hay un **turno único** (un candado de caché). El primero
barre; los demás esperan **un segundo** y, si no les toca, se van. Quien no
consiga el turno no pierde nada: su fila está guardada y la coge el barrido
siguiente o el reloj del minuto. Lo único que se pierde es la respuesta
inmediata "ya tienes rival".

Esperar poco es deliberado. Cada petición que espera es un proceso del servidor
parado sin hacer nada, y en un compartido hay pocos: con seis segundos de espera
y ocho entradas a la vez, siete procesos se quedaban cuarenta segundos en total
para acabar sin emparejar a nadie. Con un segundo, ocho barridos simultáneos
sobre 120 en cola salen todos por debajo de 0,8 s.

El candado **caduca solo a los 30 segundos**, para que un proceso muerto a media
faena no deje la cola congelada. Antes eran dos minutos, y dos minutos sin
emparejar a nadie es una cola que crece y vuelve a matar al siguiente proceso.

Y si la cola pasa de **250 esperando**, el reparto no se hace dentro de la
petición: se deja al reloj del minuto, que corre por línea de comandos y no
tiene límite de tiempo. Repartir una cola de cientos mientras alguien mira una
página en blanco es la forma de que el servidor corte la petición a medias —y,
peor, de que la corte con el turno cogido.

## Cuánto aguanta

Medido en el entorno de desarrollo, con la cola llena de golpe y sin partidas
previas. Son tiempos de una sola pasada del emparejamiento:

| En cola | 1v1 | 2v2 | 3v3 |
|---:|---:|---:|---:|
| 6 | 0,03 s | 0,02 s | 0,01 s |
| 12 | 0,02 s | 0,01 s | 0,02 s |
| 30 | 0,07 s | 0,04 s | 0,04 s |
| 60 | 0,18 s | 0,11 s | 0,13 s |
| 120 | 0,51 s | 0,30 s | 0,34 s |
| 240 | 1,34 s | 0,86 s | 0,87 s |
| 480 | 3,49 s | 2,15 s | 2,25 s |
| 900 | 8,58 s | 4,91 s | 5,15 s |

El 1v1 es el caso más caro para una misma población, porque hay un "equipo" por
persona en vez de uno cada dos o tres.

Memoria y consultas a la base, en el caso más caro (1v1):

| En cola | Memoria pico | Consultas |
|---:|---:|---:|
| 30 | 22 MB | 101 |
| 120 | 28 MB | 370 |
| 240 | 32 MB | 731 |
| 480 | 46 MB | 1 450 |
| 900 | 70 MB | 2 719 |

Son unas **tres consultas por partida creada**, que es lo razonable: bloquear las
filas de cola, insertar el enfrentamiento y marcar las colas.

**Lectura práctica:** hasta 120 personas en cola a la vez, el emparejamiento no
se nota. A 240 empieza a notarse pero sigue lejos de cualquier límite. El
servidor de Hostinger tiene su propio tope de tiempo por petición (30 s es lo
habitual) y de memoria (256 MB), así que el margen es holgado incluso en el peor
caso probado.

Lo que conviene vigilar es el **número de consultas**, que crece con las partidas
creadas, porque en un hosting compartido la base está en otra máquina y cada ida
y vuelta se paga. Por eso está el límite de 250: pasado ese tamaño, esas
consultas las hace el cron y no una persona esperando.

**Cifra para producción:** un hosting compartido es dos o tres veces más lento
que donde se tomaron estas medidas. Contando eso, **200 en cola a la vez es un
techo cómodo** y 400 el techo duro. Por encima de 250 el reparto ya no se hace
en la petición, así que lo que nota el jugador es que el rival tarda hasta un
minuto en aparecer, no que la página se caiga.

Ojo: estos números son de una cola **llena de golpe**. En funcionamiento normal
la cola se vacía sola con cada barrido, así que lo que se empareja en cada
pasada es lo que haya entrado desde la anterior, no toda la comunidad junta.

## Cómo se comprueba que empareja bien

"Bien" no es una opinión. Para colas pequeñas se puede calcular el reparto
**óptimo** probándolos todos, y contra eso se compara. En 1 000 colas al azar de
4 a 12 jugadores:

- **990 repartos óptimos exactos** (99 %).
- **0 colas dejando gente fuera** teniendo rival legal.
- La peor desviación fueron 78 puntos de MMR repartidos entre seis partidas,
  unos 13 por partida: menos de lo que mueve un solo combate.

Y la promesa nº 1 se comprobó aparte, con las colas que de verdad la ponen a
prueba: **200 colas de 40 a 600 jugadores**, con un solo reino llevándose hasta
el 95 %, el MMR desde totalmente plano hasta muy disperso, cuentas compartidas
entre reinos y las tres modalidades mezcladas en la misma cola. **Cero partidas
perdidas, cero cruces ilegales, cero mezclas de modalidad** — y comprobado en
los dos motores, SQLite y MySQL.

Los casos que importan están fijados en `tests/Feature/EmparejamientoTest.php`,
cada uno con el número exacto que debe salir y el porqué.
