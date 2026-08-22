# VotingGame — design

Status: **scaffold complete, rules pending.**

---

## 1. The game

> **AWAITING THE BRIEF.** The `## The game` section of the kickoff brief
> arrived as the unfilled template — the one-paragraph description of theme,
> core loop, player count, solo-first vs multiplayer-first, victory condition,
> and any mechanics already decided was never filled in.
>
> Nothing in this section is invented in the meantime. The repo name suggests
> voting is central, but a guess written down here would read as a decision
> three sessions from now, and design documents that quietly contain fiction
> are worse than design documents with a hole in them.
>
> **This section gets written the moment that paragraph lands**, and the
> engine rules follow from it.

### Open questions (answer with the paragraph, or as a follow-up)

These are the questions the paragraph usually settles. Anything it does not
cover, I will ask about individually rather than assume.

1. **Theme.** What is being voted on, by whom, and in what setting?
2. **Core loop.** What does one player do on one turn, in one sentence?
3. **Player count and shape.** Solo-first (with a scripted opponent) or
   multiplayer-first? Best-at count? Does it need to work at 2?
4. **Victory.** Points at the end, a race to a threshold, elimination,
   or a hidden-role style "your side wins"?
5. **Turn structure.** Sequential seat rotation, simultaneous commit-then-
   reveal, or phase-based rounds? *(This one has the largest effect on the
   engine and the polling UI — simultaneous play needs a barrier, sequential
   does not.)*
6. **Hidden information.** Is anything secret — hands, roles, sealed votes?
   Hidden information is cheap to add now and expensive to retrofit, because
   it sets where the public/private boundary sits in the state blob.
7. **Game length.** Target minutes per session, and roughly how many rounds.
8. **Mechanics already decided.** Anything you already know you want, even
   if it does not fit yet.

### Working assumptions baked into the scaffold

These are placeholders chosen so the plumbing could be finished and tested.
Each is a one-file change; none is a commitment.

| Assumption | Where it lives | Cost to change |
| --- | --- | --- |
| 2–6 players, no solo mode | `engine_default_config()` | trivial |
| 8 rounds, fixed | `config.total_rounds` | trivial |
| Sequential seat rotation | `engine_advance_turn()` | moderate — a simultaneous design replaces it |
| Highest score wins | `engine_score_player()` | trivial |
| Everything visible except an empty `private_state` | `engine_public_state()` | trivial to populate |

---

## 2. Architecture (locked — proven on this host)

### Server-authoritative, client presentation-only

`backend/engine.php` holds every rule as pure functions over the `$game` and
`$players` arrays. Endpoints do no rules work: they authenticate, lock, call
the engine, save, commit, bump. Anything the browser computes is an advisory
mirror — when the two disagree, the client is stale and the server is right.

### The mutation contract

Every mutating endpoint, without exception:

```
authenticate()                       per-seat player_token
begin_transaction()
load_game(…, forUpdate: true)        SELECT … FOR UPDATE — single writer
load_players()
engine_*()                           mutates the arrays in place
save_game() + save_player()
commit()
bump_state_version()                 AFTER the commit, so no poller
                                     ever sees a half-written state
```

`playAction.php` is the one action endpoint; the engine dispatches on an
action key. One endpoint rather than one per move, because a second place to
enforce a rule is a second place for it to drift.

### State as JSON in TEXT columns

Columns exist only for what must be **indexed, sorted, or locked on**
(status, phase, round, current seat, state_version). Everything else lives in
`vg_games.state` and the players' `public_state` / `private_state`. This is
the decision that lets rules change every playtest without a migration —
and, with iteration this fast, migrations are the main source of "new code
against an old schema" outages.

### Hidden information

`engine_public_state()` is the boundary. Exactly one seat's `private_state`
is ever serialised: the asking seat's. Secrets must never be copied into the
public half, not even temporarily for the UI. Publish *counts* of hidden
things (hand size, votes cast) instead of contents.

### Realtime

1.5 s polling of full public state, with `?since=<state_version>`: when
nothing changed the server answers `{ changed: false }` without building any
state. No websockets — shared hosting. Polling pauses on a hidden tab and
fires immediately on return.

### Auth

Standalone per-seat `player_token`, minted at create/join, stored in
localStorage. No accounts, no sessions, nothing shared with any other site on
the domain. A 4-character join code (alphabet excludes `0/O/1/I/L`) lets a
player at the same table join from their own phone.

### Event log from day one

Every action writes to `vg_event_log` with seat, type, pre-rendered message,
JSON detail, round, phase, timestamp. The in-game feed and the export read the
same rows, so what a player saw during the game and what I read afterwards are
the same record. Exports and all playtest analysis depend on this.

---

## 3. Data model

| Table | Holds |
| --- | --- |
| `vg_games` | one row per playthrough; `state` JSON is the shared board |
| `vg_game_players` | one row per seat; `player_token`, public + private state |
| `vg_event_log` | every action, forever |
| `vg_playtest_reports` | notes + 1–5 rating + a snapshot of the position |
| `vg_scores` | the lobby board; survives clearing finished games |

Migrations are numbered `database/NN_description.sql` and run **by hand in
phpMyAdmin**. `admin_schemaCheck.php` reports which tables and columns are
missing and names the file to run. **Every migration adds its expectations to
that file in the same commit** — that list is the migration checklist.

---

## 4. Endpoints

| File | Method | Purpose |
| --- | --- | --- |
| `createGame.php` | POST | open a table, caller takes seat 0 |
| `joinGame.php` | POST | take the next free seat by join code |
| `startGame.php` | POST | host deals the opening position |
| `getState.php` | GET | the poll endpoint; public state + recent log |
| `playAction.php` | POST | every rules-affecting move |
| `listOpenGames.php` | GET | lobby list |
| `exportGame.php` | GET | one playthrough, verbatim JSON |
| `submitReport.php` | POST | playtest note + rating + snapshot |
| `highScores.php` | GET | lobby board |
| `admin_schemaCheck.php` | GET | which migrations have run (not gated) |
| `admin_exportAll.php` | GET | the entire playthrough database |
| `admin_clearFinished.php` | POST | purge finished games (scores survive) |
| `_opcache_reset.php` | GET | token-guarded OPcache flush after deploy |

Admin endpoints need `ADMIN_TOKEN`, defined in the server-only `dbConfig.php`.
Absent that constant they stay closed rather than open.

---

## 5. Deploy

`gh workflow run deploy.yml`, then `gh run watch`, then confirm the run's
headSha matches HEAD. Push-triggered runs are unreliable on this host, so a
green push is not evidence of a deploy.

The `php -l` gate over every `backend/*.php` runs before anything is uploaded
and fails the build on a parse error. There is no local PHP runtime, so this
gate is the only thing between a stray apostrophe in a single-quoted string
and a 500 on every page. It stays blocking.

rsync runs with **no `--delete`** and excludes `dbConfig.php` and `uploads/`,
so a deploy can never remove a server-only file. The workflow then curls
`_opcache_reset.php`, because LiteSpeed serves new static assets immediately
but will happily keep running stale compiled PHP.

---

## 6. Playtest loop

design → implement → deploy → play → export → review → repeat.

- **Download playthrough** on the game screen writes the verbatim JSON.
- **Playtest note** files a rating and notes with a snapshot of the position
  at filing time — a complaint about the endgame is unreadable six games
  later without the board that produced it.
- Exports shared with me get archived to `docs/playtests/` and reviewed in a
  table: VP, turns, endings, key lines.
- **Balance questions get a simulation before a rule change**: a Python
  playout harness of the current rules, calibrated against real exports,
  then the counterfactual.

---

## 7. Conventions

- One commit per design decision, with the evidence (sim result or playtest
  export) in the message.
- PHP single-quoted strings: escape apostrophes, or word around them.
- Tailwind: **literal class strings only**. `bg-${color}-500` is absent from
  the built CSS and renders unstyled.
- Multi-file source edits go through an assert-guarded Python script run with
  `py -X utf8`, never a bash heredoc.

---

## 8. Changelog

| Date | Decision | Evidence |
| --- | --- | --- |
| 2026-08-22 | Scaffold: schema, engine skeleton, endpoints, lobby, deploy | — |
