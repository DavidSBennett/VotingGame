# VotingGame — design

**Working title:** *The Fourth Estate* (placeholder).

Status: v2, the simplified ruleset, is live. v1 (three tracks, stability,
key cards) was played once and retired; §8 keeps what it taught. Not yet
playtested by a human beyond that first game.

---

## 1. The premise

Players are the **partisan press** of the early republic. You do not run
for office. You print the stories, put your money behind a candidate, and
collect when he wins.

**The player with the most money at the end wins.** Winning elections is
instrumental: backing the winner pays, and backing him hardest makes you
his Patron.

The one dilemma the whole game is built on: **a story pushes the country
the way history says it did, whoever prints it.** Printing the Hartford
Convention helps the States side even if you are backing the Nation man.

---

## 2. The board — fourteen races

1796 to 1860 inclusive is seventeen elections; the three that were not
contests are cut (1804, Jefferson 162–14; 1816, Monroe 183–34; 1820,
Monroe unopposed). Each race is a **Nation** candidate against a **States**
candidate. Content is in [`backend/game_data.php`](../backend/game_data.php).

| # | Year | Nation | States | Historically |
| --- | --- | --- | --- | --- |
| 1 | 1796 | John Adams | Thomas Jefferson | Nation |
| 2 | 1800 | John Adams | Thomas Jefferson | States |
| 3 | 1808 | C.C. Pinckney | James Madison | States |
| 4 | 1812 | DeWitt Clinton | James Madison | States |
| 5 | 1824 | John Quincy Adams | Andrew Jackson | Nation |
| 6 | 1828 | John Quincy Adams | Andrew Jackson | States |
| 7 | 1832 | Henry Clay | Andrew Jackson | States |
| 8 | 1836 | W.H. Harrison | Martin Van Buren | States |
| 9 | 1840 | W.H. Harrison | Martin Van Buren | Nation |
| 10 | 1844 | Henry Clay | James K. Polk | States |
| 11 | 1848 | Zachary Taylor | Lewis Cass | Nation |
| 12 | 1852 | Winfield Scott | Franklin Pierce | States |
| 13 | 1856 | John C. Frémont | James Buchanan | States |
| 14 | 1860 | Abraham Lincoln | Stephen A. Douglas | Nation |

The historical result breaks a dead tie and nothing else.

---

## 3. The track

One track, **States −5 … 0 … +5 Nation**. It starts every campaign at 0
and returns to 0 after every election: each race is argued fresh.

---

## 4. Cards

94 dated cards, each with two numbers: a **value** (3–8) and a **push**
(States 2 … Nation 2, fixed by history).

**Cards are released over time.** A card enters the deck at the first
campaign held in or after its year, so nothing turns up before it
happened. The opening deck is the 30 cards up to 1796 — the Stamp Act,
Common Sense, the Articles, Shays' Rebellion, the Federalist, the Postal
Act — and each later campaign shuffles in the years since the last: the
Louisiana Purchase in 1808, the telegraph in 1844, Kansas in 1856. The UI
announces each batch.

Two kinds:

- **Event** cards argue: they carry a push.
- **Profit** cards are the business of the press itself — the first daily,
  the Postal Act of 1792, Niles' Register, the penny press, the telegraph,
  the Associated Press, the rotary press, cheap postage. High value, **no
  push**: cash them, or stake them without moving the country.

The 54 cards from 1795 on came from the v1 content by a fixed formula; the
founding era, the gap years (1809–12, 1837–44) and the profit cards were
written by hand. All of it is recorded in the header of `game_data.php`,
and every number is a first draft worth arguing with.

---

## 5. A turn

Play one card, then draw back to five:

- **Cash** — take its value. **+2 if you are the Patron.**
- **Print** — move the track by its push, and stake its value on
  **either** candidate.

## 6. The election

Held once every seat still playing has had **2 turns**.

1. The side the track leans toward wins. At 0, the bigger total stake
   wins; failing that, the historical winner.
2. Stakes on the winner pay back **1.5×** (rounded down per seat). Stakes
   on the loser are lost.
3. The single largest stake on the winner makes that seat **Patron** until
   the next election. A tie leaves nobody Patron.
4. The track returns to 0, stakes clear, and the opening seat rotates.

## 7. The end

After 1860 the richest paper wins; conceded seats cannot. If every human
concedes, the game ends where it stands.

**The bot**: Patron? Cash the best card. Otherwise print the most valuable
card that leaves the track off 0, staking on whichever side then leads.
Nothing to print? Cash the best card.

---

## 8. What the simulation found

[`tools/simulate.py`](../tools/simulate.py) plays the game thousands of
times per setting. It parses the content **out of the PHP**, so the data
cannot drift; the rules are a hand-port of `engine.php`, kept in step by
hand. Every number in `engine_default_config()` came from a run.

### v2 (2026-09-26)

**1. A carried-over track decided races before anyone voted.** 67% of
elections were settled at ±5. → The track resets every election.

**2. Full-size pushes still pinned it**, and the early deck leaned +20
toward Nation, which won ~90% of early races. → Pushes halved to ±1–2 and
tariff dropped from the push formula: Nation now wins ~60% early and ~29%
in the crisis; races pinned at ±5 fall to ~2% early, ~14% in the crisis.

**3. Doubling pushes in the crisis did nothing** but re-pin the track
(4% → 14%); win rates were identical. → The crisis only adds its cards.

**5. Dated release keeps the balance** (1,000 games per matchup): races
pinned at ±5 stay under 10%, seat bias within ~5 points of fair, rivals
back opposite candidates in 30% of races. Nation's late win rate rose
from ~29% to ~42%, because the States-leaning late cards are now diluted
by everything released before them. That dilution is the open question:
a third to half of the cards played from 1828 on are over 25 years old
(the Stamp Act in 1844). Retiring cards after 20 years would cut that to
~15% and restore the late States lean (~30%); not yet adopted.

**4. The first bot was a pushover.** An expected-value player beat it 91%
heads-up. → The current bot holds it to ~61%.

**Accepted trade-off:** at a 1.5× payout the EV player prints ~80% of
turns and a never-print player never wins. It is the only payout tested
where rivals regularly back opposite candidates (33% of races, against
~6% at 1.0×).

**Still open:** at 4–5 seats the EV player does *worse* than the bots,
so larger tables are not validated; only real playtests can say whether
two turns per election feels too short.

### v1, retired (2026-08-24)

v1 had three issue tracks that transitioned to successors, a stability
pool that could end the game, key cards, and five numbers per card. Its
simulation found, in order: stability collapsed after 1.9 of 14 spaces;
investing in control was penalised twice; the payback window, not the
bonus, was the broken knob; control was never contested (the opener took
it 14 of 14 times) until losing support paid out; zero transitions fired
in 300 games; then they fired in 1812; and a two-player tuning died at
four. Each fix added a rule. v2 is what was left after asking which rules
existed only to patch other rules.

---

## 9. Architecture (locked — proven on this host)

Server-authoritative. `backend/engine.php` holds every rule as pure
functions over `$game`/`$players`; endpoints authenticate, lock, call the
engine, save, commit, bump. The client is presentation-only.

**The mutation contract**, followed by every mutating endpoint:

```
authenticate()                       per-seat player_token
begin_transaction()
load_game(…, forUpdate: true)        SELECT … FOR UPDATE — single writer
load_players()
engine_*()                           mutates the arrays in place
save_game() + save_player()
commit()
bump_state_version()                 AFTER the commit, so no 1.5s poller
                                     ever sees a half-written state
```

Rival papers play inside the **same** transaction as the human action, so a
solo player gets the whole round back in one response.

**State as JSON in TEXT columns.** Columns exist only for what must be
indexed, sorted or locked on. Everything else — the track, stakes,
hands, the deck — lives in `vg_games.state` and the players'
`public_state`/`private_state`. This is what let §8 happen as a series of
one-file diffs with no migrations, and v2 replace v1 with none.

**Hidden information.** `engine_public_state()` is the boundary; exactly one
seat's `private_state` is ever serialised. Hands are private — other seats
get a count. It also works out each card's cash value and where printing it
would move the track, so the UI never reimplements a rule.

**Realtime.** 1.5 s polling with `?since=<state_version>`; unchanged state
answers `{ changed: false }` without building anything.

**Auth.** Standalone per-seat `player_token` in localStorage, plus a
4-character join code. No accounts.

**Event log from day one.** Every action, with seat, type, message, JSON
detail, round, phase. The in-game feed and the export read the same rows.

### Data model

| Table | Holds |
| --- | --- |
| `vg_games` | one row per playthrough; `state` JSON is the board |
| `vg_game_players` | one row per seat; token, public + private state |
| `vg_event_log` | every action, forever |
| `vg_playtest_reports` | notes + 1–5 rating + a snapshot of the position |
| `vg_scores` | the lobby board; survives clearing finished games |

Migrations are numbered `database/NN_description.sql`, run by hand in
phpMyAdmin. `admin_schemaCheck.php` names what has not been run, and **every
migration adds its expectations to that file in the same commit**.

### Deploy

`gh workflow run deploy.yml`, then `gh run watch`, then confirm headSha
matches HEAD. Push-triggered runs are unreliable here. The blocking `php -l`
gate is the only PHP syntax check in the project. rsync runs with no
`--delete` and excludes `dbConfig.php`.

### Conventions

- One commit per design decision, with the evidence in the message.
- Balance questions get a simulation before a rule change.
- Tailwind: literal class strings only.
- PHP single-quoted strings: escape apostrophes.
- Multi-file edits: assert-guarded Python via `py -X utf8`, never a heredoc.

---

## 10. Changelog

| Date | Decision | Evidence |
| --- | --- | --- |
| 2026-08-22 | Scaffold: schema, engine skeleton, endpoints, lobby, deploy | — |
| 2026-08-24 | Board fixed at 14 spaces by cutting 1804, 1816, 1820 | The three uncontested races |
| 2026-08-24 | Expansion replaces Nullification as the first late track | Orthogonality to Slavery |
| 2026-08-24 | Issues pick the candidate, control picks the owner | Design Q1 |
| 2026-08-24 | Each key card transitions one track, gated by era | Q2; transitions fired at space 2.6 ungated |
| 2026-08-24 | Transitions pay their finance value | 0 of 300 games fired one when they did not |
| 2026-08-24 | Losing support pays out | Opener took control in 14 of 14 campaigns |
| 2026-08-24 | turns_per_space 3, control_bonus 4 | Payback-window sweep |
| 2026-08-24 | Stability pool and recovery scale per two seats | 4-player games died at space 2.8 |
| 2026-09-26 | v2 simplification begun: one Nation/States track, cards reduced to value + push, cash or print, 2 turns per seat, winning stakes pay 1.5x, a Patron bonus replaces the presidency bonus. Content in `backend/v2_data.php`, rules in `tools/simulate_v2.py`; the live engine is still v1 | Rules reduced to two ways to play a card and one track |
| 2026-09-26 | v2: the track resets to 0 every election | Carried over, 67% of races were decided at +-5 |
| 2026-09-26 | v2: pushes halved to +-1..2, tariff dropped from the push formula | Early deck leaned +20 to Nation, which won ~90% of early races; now ~60%, pinned races ~12% |
| 2026-09-26 | v2: the crisis only adds its cards in 1848, and pushes are not doubled | Doubling changed no win rate, only re-pinned the track (4% -> 14%) |
| 2026-09-26 | v2: the bot prints its best card that leaves a side ahead | The first bot lost to the EV player 91% heads-up; now 61% |
| 2026-09-26 | v2 goes live: engine rewritten, v1 data and simulator deleted, games from v1 shown as ended | — |
| 2026-09-26 | Cards released by date; 40 new cards (the founding, the gap years, profit cards); the crisis rule removed | Opening deck of 30 deals five hands; pinned races stay under 10% |
