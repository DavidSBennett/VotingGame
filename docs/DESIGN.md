# VotingGame — design

**Working title:** *The Fourth Estate* (placeholder).

Status: sealed rounds with three-stat cards and a stability track are live. v1 (three tracks, stability,
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

## 3. The track and the Union

One track, **States −5 … 0 … +5 Nation** (states' rights against federal
power). It starts every round at 0.

**Stability of the Union** starts at 14 per two seats. Negative coverage
spends it; each election restores 1 per two seats. **At zero the Union
breaks: the game ends and every paper loses** (all score zero).

---

## 4. Cards

94 dated cards. Each has up to three stats:

- **Profit** — money when played for profit. The only way to score.
- **Positive coverage** — a signed push (−3 … +3).
- **Negative coverage** — a signed push, usually weaker and always the
  other way, plus the **stability** it costs. Each card touches stability
  once at most, and only when played negatively.

Example: *The Bill of Rights* — profit 1; positive States +3; negative
Nation +1 at a cost of 2 stability.

**Balance rule:** within every one of the 14 release batches, the Nation
push on offer equals the States push on offer, counting both coverage
options of every card. `tools/simulate.py` refuses to run if a batch
drifts. Negative coverage is where most of the balancing was done.

8 cards are profit-only (the business of the press: the Postal Act, the
telegraph, the rotary press…). Cards enter the deck at the first campaign
held in or after their year; the opening deck is the 30 cards to 1796.

---

## 5. A round

Each election is **one round**, played by every paper **at once, blind**.
Commit any number of cards (at least one), each played for:

- **Profit** — its profit in money. **Doubled if you are the Patron.**
- **Positive** — its positive push, counted as that much **influence** on
  the candidate you name.
- **Negative** — its negative push the same way, and it costs the Union
  its stability. **At most one card a round.**

Mark one **coverage** card to reserve.

## 6. The reveal

1. Negative plays are paid from stability. At zero, everyone loses.
2. Every push is added up. The side the track leans toward wins. Level:
   the greater total influence wins; failing that, history.
3. The most influence on the winner makes that paper **Patron**: its
   profit plays pay double next round. A tie leaves nobody Patron.
4. Everyone except the new Patron takes its reserved coverage card back;
   everything else committed is spent. Everyone draws 2; the Union
   recovers.

## 7. The end

After 1860 the richest paper wins; conceded seats cannot. If the Union
breaks, nobody wins.

**The bot**: keep four cards, commit the rest. Patron? Profit them all.
Otherwise pick the side the hand can push hardest and, cheapest first,
cover a card for it when its push is at least its profit — negatively only
once, and only if stability stays above 4. Profit the rest.

---

## 8. What the simulation found

[`tools/simulate.py`](../tools/simulate.py) plays the game thousands of
times per setting. It parses the content **out of the PHP**, so the data
cannot drift; the rules are a hand-port of `engine.php`, kept in step by
hand. Every number in `engine_default_config()` came from a run.

### Three-stat cards and the Union (2026-09-27)

Cards became profit / positive / negative with a stability cost, money
comes only from profit, and a stability track was added. 400–600 games per
setting:

- **With the Patron's only reward a per-card bonus, coverage was
  worthless** — a pure casher beat the bot 99.8% at +3 per card.
- **The reserve was the real culprit.** A non-Patron took back a card that
  had already paid profit, so a pure casher replayed its best card (the
  Postal Act) every round and avoiding the Patronage was the winning line.
  **Reserve limited to coverage cards** → at Patron ×2 a pure casher wins
  1.8% heads-up and under 4% at any table size.
- **Uncapped negative coverage let one paper break the Union** in 80–100%
  of games. **One negative card a round** plus recovery 2 per two seats →
  a paper that plays negatively every round breaks it ~0%. At recovery 0–1
  it still broke it 90–100%.
- A bot that never plays negatively ties the full bot (50/50): negative
  coverage is a situational tool, not a requirement.
- Nation wins ~45% of races before 1848 and ~52% after (history: 3 of 10,
  2 of 4). The Patron still never repeats.
- **Stability retuned to 14 / 1** (from 10 / 2, where recovery refunded
  nearly every negative play and the gauge never moved). Careful papers
  still never break the Union; a paper playing its costliest card
  negatively every round breaks it ~59% heads-up, ~32% at three seats, 0%
  at four or more.
- **Open:** heads-up, 33% of races end level on the track with equal
  influence, so history decides them.

### Sealed rounds (2026-09-26)

One blind round per election replaced two sequential turns each. Heads-up
round robin, 800 games per pairing, bot's win rate against:

| hoarder | casher | all-in | blind printer | contrarian |
| --- | --- | --- | --- | --- |
| 100% | 60% | 93% | 98.5% | 100% |

- **Cash against print is a live choice** — at equal card throughput a
  pure casher still wins ~40% against the bot. The Patron bonus is the
  lever: at 0 the casher won 89%, at 3 it won 15%. Kept at +2 per card.
- **The push on each card matters**: printing every card for your side
  regardless of its push loses 98.5%.
- **The Patron cashes, so the Patron never repeats** (0% back-to-back in
  bot mirrors) — a structural brake on a runaway leader. Banking as Patron
  alone was worth 96% against an otherwise identical line.
- **The first bot** (print up to three, cash one) lost 89% to a sharper
  line, which became the bot.
- Simultaneous play removes turn order, so seat bias is gone by
  construction.
- **Open:** at 4–5 seats a pure casher wins above its fair share (40%
  against 25%) while the printers crowd each other. The ±5 clamp is hit
  far more at big tables (44–56%), which matters less now that the track
  resolves once, but may argue for a wider track.

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
| 2026-09-26 | Sealed rounds: one blind commitment per election, any number of cards, cash or print; Patron keeps no reserve; draw 2 | Round robin above; the Patron bonus sets cash against print |
| 2026-09-27 | Three-stat cards (profit / positive / negative + stability), every release batch push-balanced; stakes removed; stability track (at zero everyone loses); Patron profit x2; one negative a round; reserve limited to coverage cards | See 'Three-stat cards and the Union' |
| 2026-09-27 | Stability 14 per two seats, recovery 1 | At 10 / 2 the gauge never moved; griefer now breaks it 59% heads-up, 32% at three |
