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
