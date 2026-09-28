# Build plan: the DC-style Fourth Estate on the variant

Planned 2026-09-28. The rules are VARIANT.md revision 4 as balanced in
`tools/simulate_dc.py`; the content is `docs/deck-dc.csv`,
`docs/elections-dc.csv` and `docs/papers-dc.csv`. `main` is untouched.

## Decisions (the user, 2026-09-28)

- **Card play:** one card at a time, plus Play all. Cards with a choice
  (trash, gain, the Sun, the Herald) open a prompt.
- **Defense:** automatic. A Defense card in hand is used when attacked.
- **Papers:** chosen in the lobby; bots take the rest at random.
- **Local PHP:** installed, to lint and unit-test the engine before deploys.

## What changes

| Area | Today | After |
| --- | --- | --- |
| Turn structure | sealed simultaneous commits | papers take turns (`current_seat`); bots play their turns inside the same request |
| Content | hard-coded in `game_data.php` | generated from the three CSVs (engine and simulator read the same cards) |
| A turn | one commit | `play` -> `elect` -> `buy` (repeatable) -> `end_turn` |
| Currency | money | plain + Political / Economic / Social influence + Campaign; payment allocated by the engine (themed first) |
| Hidden info | hands and commits | hands and deck order |
| Score | money | prestige |

No database migration: game state is JSON in `vg_games.state` and
`vg_game_players.public_state` / `private_state`. Endpoints keep their
contracts (`createGame`, `joinGame`, `startGame`, `playAction`,
`getState`, `exportGame`).

## Milestones

1. **Content pipeline.** `tools/export_cards_php.py` writes
   `backend/cards_dc.php` from the CSVs; a check that PHP content == CSV.
2. **Engine core** (`engine.php`, state version 9). State: main deck with
   dated releases, exchange of 5, Editorial and Scandal piles, the election
   in progress, each paper's deck / hand / discard / media events /
   newspaper, the turn's influence pools and any pending prompt. Actions:
   `play` (one or all), `choose`, `elect` {side}, `buy` {card|editorial},
   `end_turn`, `concede`. Rules: attacks with automatic Defense and the
   +1 when an attack hits, Retraction, compounding, chains, per-office,
   trash, gain, media events (owner and others), the eight papers, the
   turn-order catch-up, releases as elections are decided, the end at
   1860. Unit tests under local PHP.
3. **Bots in PHP.** Port of the simulator's balanced bot (score, buy,
   elect). Each bot turn logged in plain words.
4. **Smoke test** (`tools/smoke_play.py`, turn-based) against the live
   variant: card conservation, payments, no negative pools, one election a
   turn, turn order, hidden information, the ending; and a batch of live
   bot games compared with `simulate_dc.py`.
5. **UI.** The current election card (two candidates, themes, thresholds,
   prestige) and the 1796-1860 strip; the exchange row (5 stories,
   Editorial pile, main-deck count, Buy when affordable); your hand
   (restyled Clipping), Play / Play all, influence meter per pool, Elect
   per candidate, End turn; choice prompts; rivals panel (paper, prestige,
   deck/discard counts, media events, elections, who is on turn); your
   newspaper card; event log; new Rules sheet. Lobby: choose a paper.
   Retire CommitBoard, Reveal, Track, NationGauge, the old Exchange.
6. **Polish.** Export, high scores in prestige, playtest report.

Roughly 1,000 lines of PHP and 1,500 of React.

## Progress

- Milestone 1 (content pipeline): done 2026-09-28.
- Milestone 2 (engine core, `backend/engine_dc.php`, `tools/engine_test.php`): done.
- Milestone 3 (bots, `tools/parity_dc.py`): done; engine and simulator agree.
- Milestone 4 (live, opt-in `"engine": "dc"`; `tools/smoke_dc.py`): done.
- Milestone 5 (UI): done. `createGame` defaults to `dc`; the lobby lists
  DC tables with their papers.
- Milestone 6 (polish): done. The build is complete.

