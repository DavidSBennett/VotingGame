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

### Revision 3 (2026-09-28, the user): playing spends, burying recycles, negatives

- A story **played on a candidate** is spent: it leaves the deck, and the
  paper banks its **prestige** ("what you become known for").
- A story **buried** gives its purchasing power (x2 for the Patron) and
  goes back to the paper's discard pile. It is never lost.
- Each candidate has a **positive** and a **negative** space. Negative
  subtracts the story's influence (plus theme bonus if its theme matches
  that candidate) from his total. Same cost as any story, for now.
- Not specified; simulator defaults, both tested: a negative play counts
  toward the Patronage of his rival (`neg_patron`); a candidate's net
  influence never drops below 0 (`floor_zero`).

`tools/simulate_eras.py` (240-3,000 games per line). New bots: positive
(never negative), negative (only negative), banker (bury everything, buy,
play only what would be lost), spender (play everything).

- **Bot fix:** with sideless stories and negatives, every hand helps both
  men equally; following history herded every paper onto one candidate
  (matched history 100%). Papers now back the candidate whose theme their
  hand fits, a coin for a tie. Seats fair (3,000 games: 33.0/33.9/33.1).
- **Decks run dry.** Playing spends, money only comes from burying, 1 buy
  a round: papers hold an empty hand ~9 of 17 rounds and buy ~0 Era III
  stories. So hoarding wins: the banker 38% at 3 seats, **79-86% at 4-5**;
  buying trade stories (never spent, bury every cycle) 44% / 73%.
- No money knob fixes the flow: start money, prices, theme bonus, 2 buys
  only move who wins (prices -2: banker 0%; +2: banker 88%).
- **A steady income fixes it.** Every paper gets purchasing power each
  round (subscriptions):

  | income | empty-hand rounds | Era III buys | banker 3p / 4p | trade-buying 3p / 4p | winner prestige 3p |
  | --- | --- | --- | --- | --- | --- |
  | 0 | 8.6 | 0.3 | 38% / 79% | 44% / 73% | 12 |
  | 1 | 5.5 | 2.0 | 0% / 1% | 9% / 37% | 23 |
  | 2 | 3.1 | 3.5 | 0% / 0% | 0% / 1% | 29 |
  | 3 | 1.1 | 5.5 | 0% / 0% | 0% / 0% | 34 |

  A bigger starting deck (starters x2) did not help (empty 8.1).
- **Negatives:** only-negative play is weak (15-18% at 3 seats, fair 33%);
  papers that mix positive and negative use about 1-2 a game. If a
  negative play did NOT count toward the Patronage, only-negative fell to
  5%. Letting totals go below 0 changed little.
- At income 2, "steady" (small bids, bury most) leads: 43% at 3 seats,
  32% at 4.

### 2026-09-28: the reserve rule returns (the user), with no prestige

Every paper that does not become Patron keeps one story it played this
round (positive or negative): it returns to hand, and its prestige is NOT
banked. Every other story played is spent and scores. (It had been
dropped at revision 2 without the user deciding it.) Simulator: `reserve`
(on), `reserve_pick` (which story a bot keeps; 'influence' or 'prestige'
made no difference).

One of each line among hunters, 300 games per cell; fair 33% / 25%:

| rules | empty-hand rounds (3p) | Era III buys | winner prestige (3p) | banker 3p / 4p | trade-buying 3p / 4p | negative-only 3p / 4p | steady 3p / 4p |
| --- | --- | --- | --- | --- | --- | --- | --- |
| no reserve, no income | 8.6 | 0.3 | 12 | 38% / 79% | 44% / 73% | 17% / 14% | 27% / 24% |
| **reserve**, no income | 6.4 | 0.3 | 12 | 24% / 66% | 48% / 76% | 25% / 24% | 37% / 23% |
| no reserve, income 1 | 5.5 | 2.0 | 23 | 0% / 1% | 9% / 37% | 15% / 17% | 44% / 31% |
| reserve, income 1 | 3.0 | 2.3 | 23 | 0% / 1% | 1% / 31% | 8% / 9% | 69% / 50% |
| no reserve, income 2 | 3.1 | 3.5 | 29 | 0% / 0% | 0% / 1% | 11% / 16% | 43% / 32% |
| reserve, income 2 | 0.4 | 5.2 | 31 | 0% / 0% | 0% / 0% | 5% / 13% | 11% / 25% |

- The reserve keeps hands fuller and makes negative-only play viable at
  3-4 seats (25% / 24%), but money is still the bottleneck: nobody can
  buy Era III stories and hoarding still wins at four seats.
- Reserve + income 1 overshoots toward small bids (steady 69% at 3 seats).
  Reserve + income 2 has full hands and a working market, but the hunter
  line beats every alternative (negatives 5-13%).

### 2026-09-28: more profit per story instead of income (the user's question)

With the reserve on and no income; knobs `profit_add`, `profit_mult`,
`cost_follows` (prices rise by the same amount). One of each line among
hunters, 300 games per cell; fair 33% / 25%.

| rules | empty rounds 3p / 4p | Era III buys 3p / 4p | winner prestige 3p | banker 3p / 4p | trade 3p / 4p | negative-only 3p / 4p | steady 3p / 4p |
| --- | --- | --- | --- | --- | --- | --- | --- |
| reserve only | 6.4 / 6.5 | 0.3 / 0.0 | 12 | 24% / 66% | 48% / 76% | 25% / 24% | 37% / 23% |
| profit +1 | 3.4 / 5.2 | 2.2 / 0.7 | 25 | 0% / 1% | 0% / 24% | 4% / 12% | 52% / 53% |
| profit +2 | 0.9 / 3.4 | 5.2 / 2.2 | 33 | 0% / 0% | 0% / 0% | 4% / 12% | 7% / 24% |
| profit +3 | 0.2 / 1.1 | 6.3 / 3.9 | 34 | 0% / 0% | 0% / 0% | 11% / 9% | 2% / 7% |
| profit x1.5 | 2.9 / 4.9 | 2.9 / 1.0 | 28 | 0% / 2% | 0% / 16% | 5% / 12% | 37% / 43% |
| profit x2 | 0.6 / 2.9 | 5.8 / 2.8 | 33 | 0% / 0% | 0% / 1% | 8% / 13% | 4% / 13% |
| profit +2, prices +2 | 4.6 / 5.7 | 1.2 / 0.2 | 21 | 0% / 9% | 13% / 60% | 8% / 15% | 71% / 52% |
| profit x2, prices x2 | 5.7 / 6.1 | 0.8 / 0.2 | 16 | 15% / 46% | 21% / 58% | 20% / 21% | 41% / 32% |
| income 2 (for comparison) | 0.4 / 0.9 | 5.2 / 3.5 | 31 | 0% / 0% | 0% / 0% | 5% / 13% | 11% / 25% |

- Raising profit while prices stay put does the same job as income:
  +2 per story (or x2) matches income 2 at three seats. At four seats it
  is weaker (Era III buys 2.2 vs 3.5), because the money arrives only when
  a paper has something to bury.
- If prices rise with profit, the gain cancels and the shortage returns.
- Either way, the same catch as income: once money flows, the hunter line
  beats every alternative.

