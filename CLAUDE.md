# This checkout is the VARIANT of The Fourth Estate

The `variant` branch is an experimental copy of the game, used to test rule
ideas without touching the real game. Read `VARIANT.md` for what is being
tested and what has been tried so far, and keep it up to date.

## Hard rules

- Work on the `variant` branch only. Never commit to, merge into, or push
  `main`. Never run `deploy.yml` (it publishes the real game and refuses any
  ref but main anyway).
- The variant is live at https://variant.thehistorians.org, with its own
  database. Pushing `variant` deploys it (`deploy-variant.yml`); to deploy
  by hand: `gh workflow run deploy-variant.yml --ref variant`, then
  `gh run watch`.
- Nothing here should touch https://voting.thehistorians.org.

## How the game is built (same as main)

- `backend/engine.php` is the rules engine (server-authoritative PHP);
  `backend/game_data.php` holds the races and the story cards. There is no
  local PHP: the deploy's `php -l` step is the only syntax check. Escape
  apostrophes in single-quoted PHP strings.
- `tools/simulate.py` is a Python port of the rules that plays thousands of
  games; it parses `game_data.php` directly. Change a rule in `engine.php`
  and mirror it in `simulate.py`, then run `py tools/simulate.py --games 1000`
  to see its effect before anyone plays it.
- `tools/smoke_play.py` plays a full game against the live variant and checks
  invariants: `py -X utf8 tools/smoke_play.py --level hard`.
- Frontend: `frontend/` (Vite + React + Tailwind; literal class strings
  only). `npm run build` in `frontend/` to check it compiles.
- `docs/DESIGN.md` records the main game's design; `docs/design-doc.md` +
  `tools/build_design_doc.py` build the Word design document.

## Language

Players hold **stories**. Each round a story is **run positive** (promote a
candidate), **run negative** (attack his opponent: the stronger push, but it
costs the Union stability), or **buried** for profit.

## Working style

- Test a rule change in the simulator first, report the numbers, then build
  it into the engine and UI.
- One commit per decision, with the evidence in the message.
- Record every rule change and its result in `VARIANT.md`.
