# VotingGame — design

**Working title:** *The Fourth Estate* (placeholder).

Status: rules drafted from the design brief, awaiting confirmation on four
points. Scaffold deployed-ready; engine implementation begins once those land.

Everything in §1–§7 marked **[stated]** comes from the brief verbatim.
Everything marked **[inferred]** is my reconstruction of how the stated parts
fit together — those are the parts to push back on.

---

## 1. The premise

**[stated]** Players are the **media apparatus** of the early republic —
partisan newspaper networks, pamphleteers, the men who decided what a
national argument sounded like. You do not run for office. You lend your
power to whoever is running, and you take your cut.

The subject is the *behind-the-scenes* political process of electing a
president between 1796 and 1860, and the fantasy is manipulating historical
events to shape elections.

**[stated]** **The player with the most wealth at the end wins.** Not the
player whose candidates won most often — winning elections is instrumental.
You back a candidate because a president you control makes you rich.

That inversion is the game's best idea, and §8 flags the balance risk it
carries.

---

## 2. The board

**[stated]** Fourteen presidential spaces, played in order. Each space offers
two historical options for the office: 1796 is Adams and Jefferson, and so on.

**[inferred]** 1796 to 1860 inclusive is *seventeen* elections, so three come
out. I dropped the three that were not contests — 1804 (Jefferson 162–14),
1816 (Monroe 183–34) and 1820 (Monroe unopposed) — which keeps both endpoints
the brief names and leaves fourteen races a newspaper could plausibly have
swung. The board is in [`backend/history_data.php`](../backend/history_data.php).

| # | Year | Candidates | Historically |
| --- | --- | --- | --- |
| 1 | 1796 | John Adams · Thomas Jefferson | Adams, 71–68 |
| 2 | 1800 | Thomas Jefferson · John Adams | Jefferson, 73–65 |
| 3 | 1808 | James Madison · C.C. Pinckney | Madison, 122–47 |
| 4 | 1812 | James Madison · DeWitt Clinton | Madison, 128–89 |
| 5 | 1824 | John Quincy Adams · Andrew Jackson | Adams, in the House |
| 6 | 1828 | Andrew Jackson · John Quincy Adams | Jackson, 178–83 |
| 7 | 1832 | Andrew Jackson · Henry Clay | Jackson, 219–49 |
| 8 | 1836 | Martin Van Buren · W.H. Harrison | Van Buren, 170–73 |
| 9 | 1840 | W.H. Harrison · Martin Van Buren | Harrison, 234–60 |
| 10 | 1844 | James K. Polk · Henry Clay | Polk, 170–105 |
| 11 | 1848 | Zachary Taylor · Lewis Cass | Taylor, 163–127 |
| 12 | 1852 | Franklin Pierce · Winfield Scott | Pierce, 254–42 |
| 13 | 1856 | James Buchanan · John C. Frémont | Buchanan, 174–114 |
| 14 | 1860 | Abraham Lincoln · Stephen A. Douglas | Lincoln |

Historical outcomes are flavour and confer nothing. The premise is that they
were contingent.

---

## 3. The three issue tracks

**[stated]** Three tracks determine whether a candidate wins. They begin as
**Independence from European Markets**, **Tariffs**, and **Federal Power**.

**[inferred]** Each is a slider running −5 to +5, starting at 0 — the current
centre of national opinion, which is what a press apparatus actually moves.

| Track | −5 | +5 |
| --- | --- | --- |
| Independence from European markets | Bound to Atlantic commerce | Economic self-sufficiency |
| Tariffs | Revenue only | High protection |
| Federal Power | Strict construction | Broad national authority |

**[stated]** When a transition card is played the issues shift: Independence →
**Nullification**, Tariffs → **Slavery**, Federal Power → **States Rights**.

**[inferred]** Each candidate carries a stance on all three tracks, on the
same −3…+3 scale, in both the early and the late regime — a candidate has to
be resolvable under whichever regime is live when their election comes up.
Stances are in `history_data.php` and are a first draft written to be argued
with.

---

## 4. Cards

**[stated]** Cards in hand are **historical forces** the players navigate.
Each card can be played in one of two ways:

**A. Sway.** Spend money to push support toward one of the two candidates on
the current space. This generates **control points** on that candidate.

**B. Finance.** Play the card for money instead. **If you control the
president elected last time, you get more.**

**[inferred]** Sway also moves the issue tracks — a card is a historical force,
and forces move opinion. This is what makes the two uses a real dilemma rather
than a pure rate comparison: swaying spends money *and* shifts the ground
every future election is fought on, including in directions that help your
rivals. A card would carry:

```
name, era, cost to play for sway, money if played for finance,
track deltas applied on sway, stability delta
```

**[open]** The card list itself is not written yet — it waits on §9 Q1,
because whether sway moves tracks or only accumulates control points changes
what a card needs to say.

---

## 5. Elections, control, and income

This is the part the brief specifies least and the engine depends on most.
**[inferred]**, and §9 Q1 asks about it directly.

My reading, which makes both stated mechanics load-bearing rather than
redundant:

1. **The issue tracks decide which candidate wins.** Compare each candidate's
   three stances against where the three tracks currently sit; whoever is
   closer to the national mood takes the office.
2. **Control points decide which *player* owns that president.** The player
   with the most control points on the winning candidate controls the
   presidency until the next election.
3. **[stated]** Controlling the sitting president means your Finance plays pay
   more, for the whole of the next era.
4. Control points on the losing candidate are wasted. That is the risk: you
   can spend heavily on a candidate and have the country move out from under
   them before election day.

The alternative reading — control points decide the election outright, issues
only modify — is a different and also playable game. It makes the press
*directly* decisive rather than indirectly so, and it makes the issue tracks a
tiebreaker rather than the board's centre of gravity. §9 Q1.

---

## 6. The transition and the endgame

**[stated]** Three key cards sit in the deck. Playing one transitions the
United States toward the endgame, shifting the issues to Nullification,
Slavery and States Rights.

**[open]** Three cards, one transition — or three cards, three staggered
transitions? §9 Q2. The staggered reading is the more interesting game (you
choose *which* crisis arrives first, and when), and it explains the number
three without needing them to be redundant copies.

**[stated]** The game ends when the board is played out, **or** when the
nation's stability track decays too far.

**[inferred]** Stability starts at 10 and falls when the press inflames the
country: swaying hard on a track already at an extreme, and — after the
transition — any play on the Slavery track. At zero the Union breaks, the game
ends immediately, and wealth is counted where it stands. There is no bonus for
causing it and no penalty for it happening; you simply might not have banked
your winnings yet.

**[open]** What exactly decays stability is unspecified in the brief. The
above is my proposal, and it is the knob most likely to need a simulation
before it is right.

---

## 7. A turn

**[inferred]** Sequential seat rotation.

1. Play one card from hand — Sway or Finance.
2. Resolve its effects (money, control points, track movement, stability).
3. Draw back up to hand size.
4. When every player has played into the current space, the election resolves,
   control is awarded, and the board advances one space.

---

## 8. Design risks, logged now

**Money is both the resource and the score.** Swaying spends victory points to
buy an income stream. That is a genuinely elegant tension — and it is exactly
the kind of tension that collapses if mistuned. If the control bonus is too
small, the dominant strategy is to never sway at all, let others fight over
the presidency, and bank every card as Finance. The control bonus has to
comfortably exceed the cost of winning control, over a realistic number of
turns, or the game has a boring solved line.

**This is the first thing to simulate**, before any of it is tuned by feel.

**Two of the three late tracks may be the same axis.** Nullification (may a
state defy the nation) and States Rights (is sovereignty state or national)
measure nearly the same thing, so after the transition the board risks having
two tracks that always move together — which flattens exactly the part of the
game the whole arc builds toward. §9 Q3 offers two ways out.

**Fourteen spaces × N players may be short or long.** Unknown until played;
the round count is config, not code.

---

## 9. Open questions

**Q1. How does an election resolve?** Do the issue tracks pick the winning
candidate while control points decide which player owns them (§5), or do
control points pick the winner with the issue tracks as a modifier?

**Q2. The three transition cards** — does each one shift a single issue
(staggered crises, player-chosen order), or does any one of them shift all
three at once?

**Q3. The late-track overlap** — Nullification and States Rights are close to
the same axis. Split them (Nullification becomes Union-vs-Secession as an
outcome, States Rights stays about federal authority over banks, improvements
and territories), or re-map one of them entirely?

**Q4. Player count, and is there a solo mode?** Not stated. Affects whether
the engine needs a scripted opponent from the start.

**Q5. The subdomain.** Still unanswered from the last round, and it blocks the
first deploy.

---

## 10. Architecture (locked — proven on this host)

Server-authoritative. `backend/engine.php` holds every rule as pure functions
over `$game`/`$players`; endpoints authenticate, lock, call the engine, save,
commit, bump. The client is presentation-only — anything the browser computes
is an advisory mirror.

**The mutation contract**, followed by every mutating endpoint without
exception:

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

**State as JSON in TEXT columns.** Columns exist only for what must be
indexed, sorted or locked on. Everything else — tracks, control points, hands,
the deck — lives in `vg_games.state` and the players' `public_state` /
`private_state`. This is what lets a rules change be a one-file diff. With
migrations run by hand, keeping rules out of the schema is the whole game.

**Hidden information.** `engine_public_state()` is the boundary; exactly one
seat's `private_state` is ever serialised. Hands are private — publish counts,
never contents.

**Realtime.** 1.5 s polling with `?since=<state_version>`; unchanged state
answers `{ changed: false }` without building anything. No websockets on
shared hosting.

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
matches HEAD. Push-triggered runs are unreliable here, so a green push proves
nothing. The blocking `php -l` gate over every backend file is the only PHP
syntax check in the project — there is no local runtime. rsync runs with no
`--delete` and excludes `dbConfig.php`.

### Conventions

- One commit per design decision, with the evidence in the message.
- Balance questions get a simulation before a rule change: a Python playout
  harness of the current rules, calibrated against real exports, then the
  counterfactual.
- Tailwind: literal class strings only.
- PHP single-quoted strings: escape apostrophes, or word around them.
- Multi-file edits: assert-guarded Python via `py -X utf8`, never a heredoc.

---

## 11. Changelog

| Date | Decision | Evidence |
| --- | --- | --- |
| 2026-08-22 | Scaffold: schema, engine skeleton, endpoints, lobby, deploy | — |
| 2026-08-24 | Board fixed at 14 spaces by cutting 1804, 1816, 1820 | The three uncontested races; keeps 1796 and 1860 |
| 2026-08-24 | Rules drafted from the brief; four questions raised | — |
