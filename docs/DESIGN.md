# VotingGame — design

**Working title:** *The Fourth Estate* (placeholder).

Status: rules implemented and tuned by simulation. Not yet deployed — the
subdomain is still a placeholder. Not yet playtested by a human.

---

## 1. The premise

Players are the **media apparatus** of the early republic — partisan
newspaper networks, the men who decided what a national argument sounded
like. You do not run for office. You lend your power to whoever is running,
and you take your cut.

**The player with the most wealth at the end wins.** Not the player whose
candidates won most often. Winning elections is instrumental: you back a
candidate because a president you control makes you rich.

That inversion is the game's best idea and its central balance risk, and
§8 is the record of making it actually work.

---

## 2. The board — fourteen spaces

1796 to 1860 inclusive is *seventeen* elections, so three come out. The
three dropped are the three that were not contests: 1804 (Jefferson
162–14), 1816 (Monroe 183–34) and 1820 (Monroe unopposed). That keeps both
endpoints named in the brief and leaves fourteen races a newspaper could
plausibly have swung. Board content is in
[`backend/history_data.php`](../backend/history_data.php).

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

Historical outcomes confer nothing mechanically — the premise is that they
were contingent. They break a dead tie and nothing else.

**Candidate stances are a first draft and are the part of this most worth
arguing with.** A coarse seven-point scale on three axes, chosen so that
revising one is cheap and no single number carries weight.

---

## 3. The three issue tracks

Each is a slider from −5 to +5, starting at 0 — the current centre of
national opinion, which is what a press apparatus actually moves.

| Track | −5 | +5 |
| --- | --- | --- |
| Independence from European Markets | Bound to Atlantic commerce | Economic self-sufficiency |
| Tariffs | Revenue only | High protection |
| Federal Power | Strict construction | Broad national authority |

Each transitions independently to a successor question:

| From | To | −5 | +5 |
| --- | --- | --- | --- |
| Independence | **Expansion** | Continental restraint | Manifest Destiny |
| Tariffs | **Slavery** | Restriction | Protection and expansion |
| Federal Power | **States Rights** | National supremacy | State sovereignty |

Expansion replaced Nullification deliberately. Nullification and States
Rights measure nearly the same thing — whether a state may defy the nation
— so the endgame would have had two tracks that always moved together.
Expansion is genuinely orthogonal to Slavery: Frémont is +2 expansion and
−3 slavery, Buchanan is +3 and +3. It is also the tighter history, since
what actually replaced the European question after 1815 was Texas, Oregon
and Mexico.

**A transition resets its track to 0.** The country has not yet staked out
ground on the new question — and that reset is what makes the timing of a
transition a weapon.

---

## 4. Cards

A card is a historical force. It can be played three ways:

- **Finance** — take the card's money. **+`control_bonus` if you control
  the sitting president.** Always legal, so a card is never dead in hand.
- **Sway** — pay the card's cost, put control points on **one** of the two
  candidates, and move the issue tracks by the card's deltas.
- **Transition** — key cards only. Flip one issue to its successor, shuffle
  that pack into the deck, and take the card's money.

**The track movement is fixed by history, not chosen by the player.** That
is the whole dilemma: the Tariff of Abominations pushes protection whoever
prints it, so a player backing a free-trade candidate must decide whether
the control points are worth handing their opponent the argument.

**A card whose tracks have been superseded stays playable for Finance but
not for Sway.** The Embargo is no longer what the country argues about,
though you can still sell papers about it.

The deck starts as the base pack (30 cards, arguing markets, tariffs and
federal power). Each key card shuffles its pack in, so playing Manifest
Destiny is literally what brings Texas into the conversation. 57 cards
total, in [`backend/cards_data.php`](../backend/cards_data.php).

---

## 5. Elections, control, and income

1. **The issue tracks decide which candidate wins** — the dot product of
   their stances with where the country currently sits. A track at 0
   contributes nothing: the country has not decided, so that question helps
   nobody.
2. **Control points decide which *player* owns them.** Most points on the
   winner controls the presidency until the next election; a tie means
   nobody does.
3. Controlling the sitting president makes every Finance play pay more, for
   the whole of the next era.
4. **Support for the losing candidate pays out** at `losing_cp_payout` per
   point. You backed the wrong man, but you sold newspapers doing it.

That last rule is not in the brief. §8 explains why the game does not work
without it.

An election also **rotates which seat opens the next campaign**, and
settles the country by `stability_recovery`.

---

## 6. Stability and the endgame

Stability starts at 28 per two seats and falls when the press inflames the
country. **The cost applies however a card is played** — the country is
inflamed by the story being printed, not by the motive for printing it.
Pushing a track already at an extreme costs an extra point.

At zero the Union breaks, the game ends where it stands, and wealth is
counted. There is no bonus for causing it and no penalty for it happening;
you simply might not have banked your winnings yet.

The three transitions are gated to the era they belong to — Manifest
Destiny from space 8, The Slave Power from 9, States Rights from 10 — so
the sectional crisis arrives in the last third of the board rather than in
1808.

At the tuned settings, **48% of two-player games reach 1860** and the rest
break the Union around space 13, in the 1850s.

---

## 7. A turn

Sequential seat rotation. Play one card (Finance, Sway or Transition), draw
back to five. When every seat has taken `turns_per_space` turns, the
election resolves and the board advances.

---

## 8. What the simulation found

[`tools/simulate.py`](../tools/simulate.py) plays the game a few thousand
times per setting. It parses the cards and the board **out of the PHP** so
the content cannot drift; the rules are a hand-port and are kept in step by
hand. Every number in `engine_default_config()` came from a run below.

There is still **no local PHP runtime**, so none of this verifies the
engine executes — only that the rules it implements produce a game. The
first real test is the first deploy.

**1. The game never got played.** Every one of 400 games ended in
`the_union_breaks` after a mean of 1.9 of 14 spaces. Stability started at
12 while a two-player game runs 56 turns, and the track had no income.
→ Recovery at each election, and a much larger pool.

**2. Investing in control was penalised twice.** Stability was spent only
by Sway, so the player investing in the presidency paid the entire cost of
ending the game early — and ending early is exactly what rewards a player
who hoards. No `control_bonus` could ever repair that.
→ Finance pays the stability cost too.

**3. The payback window, not the bonus, was the broken knob.** At
`turns_per_space = 2` a sway costs its price plus a forgone Finance turn
(~10) and leaves at most **one** turn to earn it back. The sweep showed a
flat +0.2 edge at *every* bonus from 2 to 12: a competent player correctly
declines to ever invest.
→ `turns_per_space = 3`. At `control_bonus = 4` the investor beats the
hoarder 71% and +8 wealth; at 6 it wins 95% and wealth-as-score becomes a
formality.

**4. Control was never contested — not once.** Instrumenting identical
strategies showed presidencies split dead even but **the opener of a
campaign taking control in 14 of 14 campaigns**. Points on the losing
candidate paid nothing, so a bidding war was negative-sum and a rational
rival always declined. Control was a turn-order entitlement, not a
contested resource — which guts the premise.
→ Losing support pays out, so a failed bid refunds rather than burns.

**5. Half the game was dead content.** **Zero transitions fired in 300 of
300 games.** The Expansion, Slavery and States Rights tracks never opened
and all 24 cards in the three packs were never seen. Playing a key card as
a transition paid nothing while playing it for money paid full value —
transition was *strictly dominated*, so no rational player would ever fire
one and the sectional crisis could not arrive.
→ A transition pays its finance value. The decision survives, because
transitioning resets that track and destroys the position of a player
currently winning on it.

**6. Then they fired far too early** — first transition at space 2.6, with
the country arguing about slavery in 1812.
→ `earliest_space` gates.

**7. A game tuned at two players was unplayable at four.** Stability drain
is per card played, so it scales with the table while the pool and recovery
did not: four-player games collapsed after 2.8 of 14 spaces.
→ Both pool and recovery are expressed per two seats and scaled at setup.

**Settled values:** `turns_per_space 3`, `control_bonus 4`,
`stability_start 28` and `stability_recovery 3` per two seats,
`losing_cp_payout 1`, `hand_size 5`, `start_money 12`.

### Still open, for real playtests to answer

- **Control is contested only ~5% of the time** even after the payout fix.
  Players tend to back *opposite* candidates rather than bid against each
  other, because each sway moves the tracks and flips which candidate the
  issues favour. That may be fine — the fight becomes a tug-of-war over the
  tracks rather than over control points — but it is not what the design
  assumed, and only humans can settle whether it plays well.
- **The economy compounds.** Control buys income, income buys control. Seat
  0 still wins about 60/40 heads-up against an identical strategy, which
  looks like an early lead snowballing rather than a turn-order artifact.
- **Investing pays at 2 players but not at 4–5.** The presidency is one
  prize split among more bidders, so its expected value falls with table
  size while hoarding is unaffected. Solo (1 human + 1 bot = 2 seats) is
  the tuned case; larger tables need their own pass.
- Candidate stances, and every flavour line, are a first draft.

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
indexed, sorted or locked on. Everything else — tracks, control points,
hands, the deck — lives in `vg_games.state` and the players'
`public_state`/`private_state`. This is what let §8 happen as a series of
one-file diffs with no migrations.

**Hidden information.** `engine_public_state()` is the boundary; exactly one
seat's `private_state` is ever serialised. Hands are private — other seats
get a count. It also computes per-card legality server-side, so the UI never
reimplements a rule to grey out a button.

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
