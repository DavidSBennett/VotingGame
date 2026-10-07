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

## What this branch plays

The 2024 game (VARIANT.md revision 5 and its 2026-10-04..07 entries; the
rulebook is the "Board Game Rules" doc, behind the engine since 2026-10-04):
news outlets claim the 51 contests of the 2024 electoral college for Trump
or Harris, stake cards face down on a candidate, and the game ends when a
side reaches 270 (a 269-269 split goes to the House); only stakes on the
winner score. The site opens on a launch page: 2024 (`"engine": "2024"`) or
1796-1860 (the variant's DC game, `engine_dc.php`); the newsroom game
(`engine.php`) stays on the server for old tables only.

## How it is built

- Content is spreadsheets: `docs/states-2024.csv` (built by
  `tools/build_states_2024.py`; half names in `docs/state-cards-2024.csv`,
  reveals in `docs/state-reveals-2024.csv`), `docs/deck-2024.csv` (built by
  `tools/build_deck_2024.py` from the mechanics slots, `docs/stories-2024.csv`,
  the headlines in `docs/frames-2024.csv` and `docs/planks-2024.csv`), and
  `docs/outlets-2024.csv` (`docs/calendar-2024.csv` is no longer used).
  Printable cards: `tools/build_print_*_2024.py` -> `docs/print/`. After editing, run
  `py -X utf8 tools/export_cards_2024_php.py` to regenerate
  `backend/cards_2024.php` (never edit it by hand); the deploy fails if they
  disagree (`--check`).
- `backend/engine_2024.php` is the rules engine (server-authoritative PHP).
  `lib.php` loads the engine a game was created with
  (`vg_require_engine_for_game`); the engines share function names, so a
  request loads exactly one.
- `tools/simulate_2024.py` plays the same rules thousands of times. Change a
  rule in the simulator first, report the numbers, then mirror it in
  `engine_2024.php`. `tools/parity_2024.py` checks the engine's bots against
  the simulator's.
- PHP 8.3 is installed locally (winget `PHP.PHP.8.3`, php.ini with mbstring,
  2026-09-28; on the PowerShell PATH). Before pushing: `php -l` every changed
  backend file and `php tools/engine_test_2024.php` (rule tests + random
  games with invariants). Escape apostrophes in single-quoted PHP strings.
- After deploying: `py -X utf8 tools/smoke_2024.py --games 3` plays live
  games through the endpoints and checks invariants after every action.
- Frontend: `frontend/` (Vite + React + Tailwind; literal class strings
  only). The game screen is `views/Shell2024.jsx` + `components/e24/` (it
  reuses the card tile, modal and prompts in `components/dc/`).
  `npm run build` in `frontend/` to check it compiles; `npm run dev`
  (`.claude/launch.json`: "frontend") talks to the live 2024 site.

## Language

Each player is an **outlet** (with an ability). Outlets take turns: either
**stake** (one card face down on Trump or Harris, in place of the turn) or
**use** cards (their currency -- **neutral**, **Republican**, **Democratic**,
**Campaign** -- counts from the hand): each story on its **top** (its
ability) or its **bottom** (the other party's currency, sometimes a plank
**knockout**); **party planks** stay in play until knocked out. Outlets
**buy** the face-up **state** of the large, medium or small **state deck**
(for a side, optionally with its **House** delegation; the next state's
**reveal** hits everyone) and stories and planks off the **exchange** (the
**Biden set**, the **switch**, then the **Harris set**). A state counts
toward **270** once **claimed**. The score is **wealth** ($, -1 to 12) on
the cards staked on the winner.

## Working style

- Test a rule change in the simulator first, report the numbers, then build
  it into the engine and UI.
- One commit per decision, with the evidence in the message.
- Record every rule change and its result in `VARIANT.md`.
