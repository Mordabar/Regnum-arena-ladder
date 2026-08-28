# Regnum Arena Ladder MVP v1 Rules

This document defines the current MVP contract so backend, views, and data model all share the same 2v2 ruleset.

## Scope included in MVP v1

- Discord login and session
- Player registration
- Random 2v2 queue
- Premade / party 2v2 queue
- Real 2v2 match creation between two teams from different realms
- Match acceptance by the 2 players on each team
- Zone assignment
- Basic result report with 2 screenshots
- PL/MMR update
- Simple public ladder
- Basic anti-abuse actions

## Scope deferred to MVP v1.1

- Repeat-opponent dampening improvements
- Top-ladder bonus / penalty tuning
- Advanced dispute tooling
- Native Discord interactive buttons and webhook handling
- Advanced zone rotation rules

## Closed product rules

1. MVP v1 ships with `random 2v2` and `premade 2v2`.
2. A valid team is always 2 players from the same realm.
3. A match is always `team_a vs team_b`, never a 3-realm free-for-all.
4. Team labels `team_a` and `team_b` are internal only. Players never see them.
5. A team can include at most 1 support conjurer.
6. A second conjurer is allowed only if queued as offensive.
7. The DM / match notification shows:
   - zone
   - your team members
   - rival realm only
   - match code
   - report token
8. Rival player names remain hidden until the result is confirmed or disputed by admin.
9. Match acceptance window is 5 minutes.
10. If any player rejects or the acceptance timer expires, the match is cancelled.
11. If a match is cancelled before start, non-offending players may be requeued.
12. The hunt window is 30 minutes after full acceptance.
13. Leaving during the hunt window is treated as abandonment and triggers a 12-hour lock for the offending player.
14. A single valid report with 2 screenshots is enough to process the result.
15. The rival side receives a confirm / reject prompt after a valid report.
16. A rejected report moves the match to `disputed` for manual review.
17. External interruption produces `void` and no ladder change.
18. PL is public and MMR is hidden.
19. MVP v1 keeps the current underdog / favorite scoring curve with hard caps.
20. The exact-party daily limit for premade duos is 3 matches.
21. False conjurer-role declaration produces:
   - match void
   - loss for offending side
   - trust score penalty

## Canonical Match model for MVP v1

One match stores two teams of 2 players:

- `team_a_realm`
- `team_b_realm`
- `team_a` JSON payload
- `team_b` JSON payload
- `queue_mode`
- `zone`
- `status`
- `match_code`
- `report_token`
- `winner_team`
- `winner_realm`
- acceptance / start / completion timestamps

Each player entry inside `team_a` or `team_b` should contain:

- `player_id`
- `character_name`
- `subclass`
- `realm`
- `discord_id`
- `conjurer_role`

## Match statuses in MVP v1

- `pending_acceptance`
- `accepted`
- `in_progress`
- `completed`
- `cancelled`
- `void`
- `disputed`

## Notes

- The runtime source of truth is already aligned to 2v2.
- Premade and random both use the same 2-team match model.
