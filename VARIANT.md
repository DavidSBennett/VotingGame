# The variant

Branched from `main` on 2026-09-27, at the rules of the live game:
seventeen elections, stories run positive / run negative / buried,
negative stories always the stronger push, Patron x2, Union ceiling 10 per
two seats.

Live at https://fourthestate.thehistorians.org, with its own database (thehist2_fourthestate).

## The theory being tested

**The newsroom: a deck-builder.** On main every paper draws from one shared
deck, so a hand is luck of the draw and what you play is gone for everyone.
The theory: if each paper builds its OWN deck during the game, buying
stories as history releases them, then (1) what you buy becomes a strategy
of its own (influence stories to win the Patronage, or dear stories to bury
later at double), (2) running a story becomes an investment rather than a
loss, and (3) money gains a second use (investing versus scoring), which is
the classic deck-builder tension.

Design chosen by the user (2026-09-27), from three options each:

- **Acquire:** a newsroom market. Each paper starts with its own deck of 5
  opening stories (its opening hand). The rest of the released stories are
  the supply, and 6 of them lie face up on **the exchange**. Fresh news goes on
  the exchange first each election.
- **Currency:** money. A story costs what it would bury for (its profit).
- **Recycling:** a story you **run** goes to your own discard pile and comes
  back when your deck reshuffles; a story you **bury** is sold and leaves
  the game.
- Buys are sealed with the commitment, at most 1 a round, and resolve after
  the election (after this round's burials have paid), **poorest paper
  first**. A bought story goes to your discard pile. An empty hand is no
  longer the end: you can pass and still buy.

### Revision 2 (2026-09-28, designed with the user; NOT yet simulated or built)

The newsroom above is live. The user has redesigned the loop around eras:

- **Stories have no side.** Play a story on either candidate for its
  **influence**, plus its **theme bonus** if its theme (Economic /
  Political / Social) matches that candidate's theme for that race.
- **The election**: the candidate with the most total influence wins; the
  most influence on him makes a paper **Patron**. No track, no negative
  runs, no Union stability, no exposure.
- **Burying** a story gives its **purchasing power** (doubled for the
  Patron) and banks its **prestige**. **Most prestige buried by 1860
  wins.** Purchasing power buys stories off the exchange at their **cost**.
- **Three eras**: I 1796-1816, II 1820-1840, III 1844-1860. Entering a new
  era, a paper loses every card of the previous era. A card's era is set by
  the election that releases it, so next-era stories arrive three
  elections early (Era II from 1808, Era III from 1832), fully playable.
- **Starters** are generic Era I cards, lost in 1820.
- Candidates are sided by what they ran on, not party (the Jeffersonians
  of 1804-1816 governed as nationalists), with themes balanced to
  Nation 5/6/6 and States 6/6/5 (Economic/Political/Social).

Content: `docs/deck-v2.csv` (every card: era, theme, influence,
theme_bonus, profit, prestige, cost) and `docs/candidates.csv`. The
numbers are a first pass by formula, to be tuned in the simulator.
Era II was thin (20 stories); seven were added (Cumberland Road,
Adams-Onis, McCulloch, Denmark Vesey, Freedom's Journal, Anti-Masonic
Party, Cherokee Phoenix), plus Indian Removal and The Liberator in Era
III (their 1830-31 dates release them in 1832). Now I 35 / II 27 / III 44.

Simulator: `tools/simulate_eras.py` reads both spreadsheets. Defaults not
set by the user: draw 2, hand limit 10, start purchasing power 6, exchange
6, 1 buy a round, a tie for most influence = no Patron, a tied election
goes to history, after an era change each paper draws back up to 5.

## Changes from main

| Date | Change | Simulator result | Playtest notes |
| --- | --- | --- | --- |
| 2026-09-27 | Newsroom deck-builder (above): own decks, the exchange, buy at bury value, run returns / bury trashes | See "Findings, 2026-09-27" | |
| 2026-09-27 | Built into the engine (config `deckbuild`, engine state v8; old games show as ended) and the UI: "The exchange" panel above the desk (tap a story to buy it with your commitment), your own deck and discard listed, purchases in the reveal, deck/bought counts per paper. Bots buy: easy = push per dollar, hard = dearest story that pushes. Named "the exchange" because "the wire" is already the event log. | as above | |

## Findings

### 2026-09-27: the newsroom in the simulator

`py -X utf8 tools/simulate.py --newsroom --games 600` (the simulator now
plays the variant by default; `--shared` plays main's shared deck).

- **Buying decides games.** A hard bot that buys beats an identical one that
  never buys 95% heads-up; the easy bot 100%. A pure casher still loses
  (0.4% among two easy bots; main 0.9%).
- **What to buy.** Five buy policies were tried, each as one buyer among
  hard bots that buy "push per dollar" (steady):

  | seats | steady | rich (dearest) | rich_ev (dearest that pushes) | cheap | greedy |
  | --- | --- | --- | --- | --- | --- |
  | 2 | 48% | 94% | 96% | 37% | 62% |
  | 3 | 34% | 49% | 51% | 27% | 41% |
  | 4 | 26% | 28% | 30% | 23% | 27% |
  | 5 | 21% | 18% | 19% | 18% | 21% |

  Heads-up, buying dear stories and burying them later as Patron (x2) is
  the strong line; at four and five seats the Patronage is contested and
  every policy is near a fair share. The dear-story line needs the
  Patronage to pay, so it has a counter; it is a strategy, not a free pump.
- **Pricing rules tried** (5-way table of policies, and heads-up): price =
  bury value; profit-only stories at double; bury value + (3 − push);
  2 × bury value − push. None removed the heads-up edge of dear stories;
  "double for profit-only" overcorrected at 3-4 seats (the dear buyer fell
  to 29% / 16%). Kept the simplest: **price = bury value**. Other knobs
  (price ±1-2, start deck 7, exchange 4 or 8, 2 buys a round) moved little;
  2 buys a round made the greedy buyer lose (31%) and broke the Union more.
- **Compared with main** (shared deck → newsroom):
  - hard bot vs easy bot heads-up: 99.7% → 98.4%
  - the human line from games 29/31 (buying push per dollar) vs the hard
    bot: 42% → **1.4%**. The hard bot now buys dear stories; the same human
    line buying the same way as its rival won ~61%. Players will have to
    learn what to buy.
  - winning money is about 40% of main's (254 → 101 heads-up, 166 → 56 at
    four): buried stories leave the game and the exchange is the only new supply.
  - Union broke with all hard bots: 2 seats 9% → 5%, 3 seats 46% → 47%,
    **4 seats 15% → 33%**, 5 seats 46% → 50%. Run stories come back, so
    hostile stories recur. Watch this in play; stability_start is the knob.
- The market never ran dry for the hard bots (exchange always full; supply 9-26).
  Easy bots at five seats bury their own decks away and hold an empty hand
  19% of rounds: a mistake that punishes itself.

### 2026-09-28: revision 2 (eras) in the simulator, first pass

`py -X utf8 tools/simulate_eras.py` (300-600 games per line). Bots:
hunter (win the Patronage with about 4 influence, bury the hand as
Patron), steady (bid 3, keep 4 in hand), bidder (bid 7), half, runner
(run everything), burier (bury everything); buy policies prestige /
influence / mixed / cheap / dear / trade / ahead / none.

- **The loop works as designed at x2.** Burying everything never wins the
  Patronage, so never doubles its purchasing power: 0-2% at every table
  size. A paper that never buys: 0%. Seats are fair (3,000 games: 33.5 /
  33.5 / 33.1).
- **Strategies:** steady beats hunter 76-79% heads-up but is below fair at
  3-4 seats (24% / 16%); overbidding (bidder) and running everything
  (runner) lose. Buy policies prestige / influence / mixed / ahead all
  near fair; trade-only buying and "cheapest" lose.
- **Market:** the exchange never ran short, Era II included. About 2 cards
  lost per paper at each era change; papers buy ~5-12 stories a game.
- **Knife edge on price vs. the Patron bonus.** Patron x1: the burier wins
  90%. Prices -2: burier 60%. Prices -1 or 0: burier 0%. The pure-economy
  line flips from worthless to dominant within a step or two; the numbers
  must sit where both lines are viable, not at either cliff.
- **The theme bonus barely matters at +1** (bonus 0 / 1 / 2 changed
  nothing much for the hunter mirror). At +3 it reshapes play (half 68%).
  If themes are meant to matter, the bonus must be large next to base
  influence (2-3).
- Scores are small: the winner buries ~20 prestige at 3 seats (1/2/3 per
  era).
