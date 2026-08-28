# Regnum Arena Ladder MVP Handoff Guide

This document explains the current production shape of the project and the minimum host setup required to operate it safely.

## MVP features implemented

- Discord login and Laravel session
- Player registration and player management
- Random 2v2 queue by realm
- Premade / party 2v2 queue
- Conjurer role declaration with support-role restriction
- Real match creation with zone assignment
- Accept / reject flow for all 4 players
- Match expiration and requeue handling
- Result reporting with 2 screenshots
- Rival confirmation or rejection of the report
- Match dispute state
- Admin resolution, void, abandonment penalty, and support-infraction resolution
- PL/MMR scoring persistence in `match_results`
- Public ladder and player history pages
- Admin panel for matches, players, testing, and runtime settings
- Debug testing lab over real tables
- Scheduler-driven maintenance with `ladder:*` commands

## Routes you can use right now

### Public / player

- `/`
- `/lobby`
- `/queue`
- `/matches`
- `/matches/{id}`
- `/ladder`
- `/ladder/player/{id}`

### Admin

The admin path is configurable through `ARENA_ADMIN_PATH`.

- `/{ARENA_ADMIN_PATH}`
- `/{ARENA_ADMIN_PATH}/matches`
- `/{ARENA_ADMIN_PATH}/matches/{id}`
- `/{ARENA_ADMIN_PATH}/players`
- `/{ARENA_ADMIN_PATH}/settings`
- `/{ARENA_ADMIN_PATH}/testing`

## Important MVP scope note

The current codebase closes a solid MVP around `2v2`, with both random and premade flows already active.

The following items remain outside the current shipped MVP:

- native Discord button webhook handling
- automatic in-game abandonment detection without admin review
- richer moderation timeline and audit logs
- stronger anti-farm logic across similar but not exact team rematches

## Database rollout

Run migrations from the real Laravel project root:

```bash
cd /home/USER/domains/YOUR-DOMAIN/public_html
php artisan migrate --force
```

Important migrations in the current ladder stack include:

- `2026_03_18_100000_add_mvp_runtime_tables_and_fields.php`
- `2026_03_18_100001_normalize_match_results_pl_columns.php`
- `2026_03_24_000000_ensure_queue_team_fields_exist.php`
- `2026_03_25_000000_relax_legacy_match_team_columns.php`
- `2026_04_06_000001_add_phase_one_concurrency_indexes.php`
- `2026_04_13_000001_update_copy_defaults_to_2v2.php`

## Filesystem setup

Result reporting stores screenshots in the `public` disk.

Run:

```bash
php artisan storage:link
```

If the host does not allow symlinks, configure the web server so `/storage` maps to `storage/app/public`.

## Required environment variables

Add these in production `.env`:

```env
APP_URL=https://your-domain.com
APP_DEBUG=false

DISCORD_CLIENT_ID=
DISCORD_CLIENT_SECRET=
DISCORD_BOT_TOKEN=

DISCORD_GUILD_ID=
DISCORD_ALERTS_CHANNEL_ID=
ADMIN_DISCORD_IDS=123456789012345678,987654321098765432
ARENA_ADMIN_PATH=lowly-control-room
```

Notes:

- `ADMIN_DISCORD_IDS` is the fastest way to guarantee creator access to the admin panel.
- `ARENA_ADMIN_PATH` controls the admin URL prefix.
- The bot token is still read from ENV, not from database settings.

## Recommended cron

Run Laravel's scheduler once per minute:

```bash
* * * * * cd /home/USER/domains/YOUR-DOMAIN/public_html && php artisan schedule:run >> /dev/null 2>&1
```

`schedule:run` dispatches:

- `ladder:tick` every minute
- `ladder:daily-maintenance` once per day

Legacy `ladder:*` commands still exist for backward compatibility, but should not be scheduled directly anymore.

## Discord app and bot checklist

### OAuth app

Create a Discord application and set:

- OAuth redirect URL: `https://your-domain.com/auth/discord/callback`
- scopes: `identify`, `email`

### Bot

Invite the bot to your server with permissions for:

- Create DM
- Send Messages
- Embed Links

The current MVP sends DM-style notifications and status updates, but it does not yet receive Discord component callbacks from buttons. Players can always continue from the website if the DM button flow is not yet wired.

## Production verification checklist

Run these checks in order:

1. Log in through Discord.
2. Register one or more characters.
3. Open `/ladder` and confirm the table loads.
4. Open `/{ARENA_ADMIN_PATH}` with your creator account and confirm access works.
5. Open `/{ARENA_ADMIN_PATH}/settings` and save runtime values once.
6. Open `/queue` and enqueue at least 4 players across 2 realms.
7. Confirm a match is created and visible in `/matches`.
8. Accept all 4 players and verify the match becomes `in_progress`.
9. Submit a report with 2 screenshots.
10. Confirm the report from the rival side.
11. Verify:
   - `matches.status = completed`
   - `matches.winner_team` and `winner_realm` are filled
   - `match_reports.status` changed to `confirmed` or `admin_resolved`
   - `match_results` has 4 rows
   - player `pl_points` and `mmr` changed
   - `/ladder` reflects the new ranking

## Queue lab workflow

For realistic sandbox validation without extra external logins:

1. Go to `/{ARENA_ADMIN_PATH}/testing`.
2. Create the shared testing roster by realm.
3. Enqueue bots by realm.
4. Execute matchmaking.
5. Inspect generated `pending_acceptance` matches.
6. Accept pending matches from the testing controls.
7. Resolve `in_progress` matches from the lab controls.
8. Check `/ladder` and the admin match view after each transition.

This lab uses real `users`, `players`, `queues`, `matches`, `match_reports`, and `match_results`. The sandbox accounts are tagged through the `@queue-lab.test` domain and `queue-lab-*` Discord IDs so cleanup does not touch real users.

## Automated regression base

The repository includes a Pest-based regression harness for the current MVP:

- `tests/Feature/LadderRankingTest.php`
- `tests/Feature/AdminPlayerSafeguardsTest.php`
- `tests/Feature/MatchQueueCleanupTest.php`
- `tests/Feature/PremadeQueueFlowTest.php`
- `tests/Feature/MatchPenaltyWorkflowTest.php`

When PHP CLI and dev dependencies are available, run:

```bash
vendor/bin/pest
```

These tests cover:

- public ladder tie-break ordering
- admin safeguards for active queue states
- queue cleanup when a match leaves the active lifecycle
- premade 2v2 queue flow
- abandonment and support-infraction penalties

## Remaining work after MVP

These items are next-phase improvements, not blockers for the current MVP:

- native Discord interaction callbacks
- stronger anti-farm logic across similar but not exact team rematches
- richer moderation timeline and audit logs
- better upload image inspection and OCR helpers

In production, prefer SSH and run:

```bash
php artisan optimize:clear
```

### `/queue` says schema is not ready

You did not run migrations in production, or the server still has stale cache.

Run:

```bash
php artisan migrate --force
php artisan optimize:clear
```

### Screenshots do not open

You likely missed:

```bash
php artisan storage:link
```

### Admin panel returns 403

Your Discord ID is not in `ADMIN_DISCORD_IDS` and your `users.is_admin` flag is false.

### Cron logs still mention `ladder` namespace

Update the host cron command to use one of the commands listed above. The old server command is outdated.

## Suggested release sequence

1. Deploy code
2. Run migrations
3. Run `storage:link`
4. Set ENV variables
5. Clear caches
6. Validate `/admin`
7. Validate `/tests/mvp-flow`
8. Validate `/queue` and `/matches`
9. Turn `APP_DEBUG=false`
10. Enable cron
