# Anuncios de actividad en Discord

El bot puede publicar en un canal del servidor mensajes generales que dan
ambiente y avisan de que hay con quien jugar. **Nunca nombran a nadie**: lo
personal (tu cruce, tu reporte) sigue llegando por DM y por los avisos del
navegador.

| Anuncio | Cuándo sale | Límite |
|---|---|---|
| ⚔️ *Duelo 1v1: hay alguien esperando rival* | Alguien entra en una cola que estaba vacía (dice su reino) | 1 cada 15 min por modalidad |
| 🔥 *Arranca un 2v2* | Un combate empieza (reinos y zona) | 1 cada 10 min en total |
| 📊 *Ahora mismo en la arena* | El cron, si hay gente en cola o combates en marcha | 1 cada 60 min |

Los bots del laboratorio de pruebas no cuentan ni disparan anuncios.

## Conectarlo

1. **Crea el canal** en el servidor, por ejemplo `#arena-en-vivo`.
2. **Da permiso al bot** en ese canal: *Ver canal*, *Enviar mensajes* e
   *Insertar enlaces* (los anuncios son embeds). Es el mismo bot que ya manda
   los DMs (`DISCORD_BOT_TOKEN`).
3. **Copia el ID del canal**: en Discord, Ajustes → Avanzado → Modo
   desarrollador; luego clic derecho sobre el canal → *Copiar ID del canal*.
4. **Añádelo al `.env` del servidor**:

       DISCORD_ANNOUNCEMENTS_CHANNEL_ID=123456789012345678

5. **Recarga la configuración**: `php artisan config:clear`
6. **Pruébalo**:

       php artisan arena:anuncios            # dice qué falta
       php artisan arena:anuncios --probar   # manda un mensaje al canal

## Ajustes

- **Apagarlos sin tocar el servidor**: Panel → Configuración → *Anuncios de
  actividad*.
- **Frecuencias** (minutos), en el `.env` si hicieran falta:
  `DISCORD_ANNOUNCE_QUEUE_MINUTES`, `DISCORD_ANNOUNCE_MATCH_MINUTES`,
  `DISCORD_ANNOUNCE_PULSE_MINUTES`.

Los mensajes salen después de responder al jugador, así que un Discord lento
nunca hace esperar a nadie.
