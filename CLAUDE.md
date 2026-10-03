# This checkout is the 2024 copy of The Fourth Estate

The `2024` branch is a copy of the `variant` branch (itself an experimental
copy of the game), taken on 2026-10-03, with its own site and database.
`VARIANT.md` records everything the variant tried before the copy and what
this branch changes since; keep it up to date.

## Hard rules

- Work on the `2024` branch only. Never commit to, merge into, or push
  `main` or `variant`. Never run `deploy.yml` (the real game) or
  `deploy-variant.yml` (the variant).
- This copy is live at https://2024.davidsbennett.com, with its own
  database. Pushing `2024` deploys it (`deploy-2024.yml`); to deploy by
  hand: `gh workflow run deploy-2024.yml --ref 2024`, then `gh run watch`.
- Nothing here should touch https://voting.thehistorians.org (the real
  game) or https://fourthestate.thehistorians.org (the variant).

## What the variant plays

A DC Deck-Building (Heroes Unite) style game (VARIANT.md revision 4 and
after; `docs/BUILD-PLAN-DC.md`). New tables play it; the older newsroom
game (`backend/engine.php`, `backend/game_data.php`, `tools/simulate.py`,
`tools/smoke_play.py`) is still on the server for API use
(`"engine": "newsroom"`), but the site no longer draws it.

## How it is built

- Content is three spreadsheets: `docs/deck-dc.csv` (stories, starters,
  Editorial, Scandal), `docs/elections-dc.csv`, `docs/papers-dc.csv` (the
  newspapers). After editing one, run `py -X utf8 tools/export_cards_php.py`
  to regenerate `backend/cards_dc.php` (never edit it by hand); the deploy
  fails if they disagree (`--check`).
- `backend/engine_dc.php` is the rules engine (server-authoritative PHP).
  `lib.php` loads the engine a game was created with
  (`vg_require_engine_for_game`); the two engines share function names, so
  a request loads exactly one.
- `tools/simulate_dc.py` plays the same rules thousands of times. Change a
  rule in the simulator first, report the numbers, then mirror it in
  `engine_dc.php`. `tools/parity_dc.py` checks the engine's bots against
  the simulator's.
- PHP 8.3 is installed locally (winget `PHP.PHP.8.3`, php.ini with mbstring,
  2026-09-28). Before pushing: `php -l` every changed backend file and
  `php tools/engine_test.php` (rule tests + random games with invariants).
  Escape apostrophes in single-quoted PHP strings.
- After deploying: `py -X utf8 tools/smoke_dc.py --games 3` plays live games
  through the endpoints and checks invariants after every action.
- Frontend: `frontend/` (Vite + React + Tailwind; literal class strings
  only). The game screen is `views/DcShell.jsx` + `components/dc/`.
  `npm run build` in `frontend/` to check it compiles; `npm run dev`
  (`.claude/launch.json`: "frontend") talks to the live 2024 site.
- `docs/DESIGN.md` records the main game's design; `docs/design-doc.md` +
  `tools/build_design_doc.py` build the Word design document.

## Language

Each player is a **paper** with a **newspaper** (its ability). Papers take
turns: **play** stories for **influence** (plain, or Political / Economic /
Social, or Campaign), **elect** a man by reaching his **threshold** (the
paper becomes his **Patron** and gains the election card), and **buy**
stories off the **exchange**. **Negative stories** attack rivals and give
**Scandals**; **media events** stay in play. The score is **prestige** (★).

## Working style

- Test a rule change in the simulator first, report the numbers, then build
  it into the engine and UI.
- One commit per decision, with the evidence in the message.
- Record every rule change and its result in `VARIANT.md`.
