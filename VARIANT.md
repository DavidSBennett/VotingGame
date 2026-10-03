# The variant, and its 2024 copy

**2026-10-03: copied to the `2024` branch**, live at
https://2024.davidsbennett.com with its own database. Everything below up
to that date is the variant's history, shared by both branches; entries
after it are this branch's own.


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

## Revision 4 (2026-09-28, the user): a strict deck-builder on DC Heroes Unite

The user sent the DC Deck-Building Game: Heroes Unite card list and asked
for a strict deck-builder on its rules. Burying is gone. Translation:

- **Super-Villains = the 17 elections**, thresholds rising linearly. Each
  election card has **two thresholds, one per candidate**; reaching one
  elects that man (history can be rewritten; the historical winner is 2
  cheaper). The winner gains the card: its **Patron bonus is printed on
  it** (+1 influence and +2..4 of the man's theme when played), worth 3..7
  prestige.
- **Three currencies** (the user): every story has plain influence (spends
  on anything) and themed influence -- Political, Economic or Social --
  that spends only on stories of that theme or on electing a candidate of
  that theme. Hero = Political, Equipment = Economic, Super Power = Social.
  "A political paper should feel different from an economic one":
  Political = elections (themed influence, Campaign for elections only,
  +per office held); Economic = engine (plain influence, trash, gain,
  compounding); Social = momentum (draw, chains, Defense).
- **Villains = negative stories**: attack rivals (discard at random, or
  gain a **Scandal**, -1 prestige, the Weakness).
- **Locations = media events** (the 8 trade stories): stay in play; a
  bonus to the owner each turn, a smaller one to everyone else.
- DC structure: papers **take turns**; start 7 Letters (+1) + 3 Local
  Notices (0); hand 5; exchange of 5 (the Line-Up); Editorials (the Kick:
  cost 3, +2, 1 prestige); win at most one election a turn; the game ends
  when 1860 is decided; most prestige wins.
- Dates kept, card loss dropped (the user): stories enter the main deck
  when the election in progress reaches their year.

Content: `docs/deck-dc.csv` (106 stories: 24 Political, 26 Economic, 21
Social, 27 negative, 8 media events; plus starters, Editorial, Scandal) and
`docs/elections-dc.csv`, first built by `tools/build_deck_dc.py` from
templates by theme and cost. Simulator: `tools/simulate_dc.py`.

### 2026-09-28: first simulator pass

Bots: balanced; political / economic / social (prefer their theme x1.5);
attacker; pacifist (never buys negatives); bigmoney (cost 5+ only).

- First templates: Political 0% (its cards gave less per cost); Social
  drew ~50 cards a game and won 65% against the other two themes. Fixed
  in the templates (Political stronger; Social draw 1 below cost 7).
- A too-strict theme bot (x1.8 on theme, x0.6 off) filled up on
  Editorials and made Political look dead; softened to x1.5 / x0.85.
- **Now** (240 games per line), three themes at one table: Political
  5%, Economic 44%, Social 52%. One focused paper among two balanced:
  Political 12%, Economic 54%, Social 60%, attacker 42%, pacifist 13%,
  bigmoney 2% (fair 33%).
- **The decks do feel different**: Political wins the most elections when
  its influence can reach any candidate; Economic trashes 9-12 starters a
  game; Social draws 27-34 cards and attacks most.
- **Political is still weak**: half of what it makes is locked to
  Political stories and Political candidates, and much of it goes
  unspent. Letting Political influence count toward ANY candidate
  (`political_any`) raises it to 10-18% and shortens games (34 -> 26
  rounds), but it is still the weakest line.
- **Negative stories are strong**: never buying them (pacifist) wins 12-13%.
- **Seats**: fair at 3 (31/37/32); at 4 the last seat is weakest (18-22%).
- Games run long: 26-34 rounds per paper, 28-38 purchases each.

### 2026-09-28: newspapers as the Super Heroes (the user)

Each player is a newspaper with a permanent ability, converted from the
eight Heroes Unite Super Heroes and aimed at Political's problems
(`docs/papers-dc.csv`; unnamed seats get a random paper):

| DC Super Hero | Paper | Ability (as tested, second version) |
| --- | --- | --- |
| Hawkman | The Washington Globe | +1 Political per Political story; Political influence counts toward any candidate |
| Red Tornado | The Albany Argus | +2 prestige per election won |
| Booster Gold | The National Intelligencer | never gains Scandals; draws a card when a negative story targets it |
| Nightwing | The Journal of Commerce | 1st Economic story each turn +1 influence, 2nd draws a card |
| Shazam! | The New York Herald | once a turn, pay 3: gain the top story of the main deck |
| Batgirl | The Sun | once a turn, discard a Scandal or Local Notice to draw |
| Starfire | The North Star | once a turn, two Social stories played: draw a card |
| Black Canary | The Aurora | +1 influence per different negative story played |

First version (weaker Globe/Argus/Intelligencer, Sun could discard Letters,
Herald paid 4): Political still 4-12% against the other two themes; the Sun
52% on a general deck. Second version, 240 games per line:

- Each paper on a general-purpose (balanced) bot among two balanced bots
  (fair 33%): Globe 33%, Argus 30%, **Intelligencer 55%**, Journal 39%,
  Herald 20%, Sun 40%, North Star 22%, Aurora 24%.
- Three theme decks at one table (fair 33%), Political's paper varied:
  no paper chosen 9%, Globe 16%, Argus 2%, **Intelligencer 30%**.
- One theme deck on its own paper among two balanced: Political+Globe 28%,
  +Argus 15%, +Intelligencer 38%; Economic+Journal 57%, +Herald 32%;
  Social+Sun 61%, +North Star 49%; attacker+Aurora 48%.
- Seats with random papers: 3p 33/38/30; 4p 26/30/21/22. ~30 rounds.
- **Finding:** what holds Political back is negative stories. It has no
  way to block or trash Scandals; given one (the Intelligencer) it reaches
  a fair share. Extra prestige per office (Argus) does not help a deck
  that wins few elections. Scandal immunity is too strong for any other
  deck (55% on a general deck).

### 2026-09-28: Retraction on Political stories (the user's choice)

"Retraction: draw a card, and you may destroy a Scandal in your hand or
discard pile." Now on every Political story costing 4+ (16 cards). The
Intelligencer is narrowed: the first Scandal each round is ignored, and
it draws a card instead.

Separating the effects (Political deck vs Economic + Social, fair 33%,
240-300 games):

| version | Political |
| --- | --- |
| no fix | 9% |
| Intelligencer blocks Scandals and draws a card | 30% |
| Intelligencer blocks Scandals, no draw | 8% |
| Retraction on every Political story, no draw | 12% |
| Retraction draws only when it destroys a Scandal | 17% |
| **Retraction on cost 4+, always draws** | **33%** (Economic 29%, Social 38%) |
| Retraction on cost 3+, always draws | 46% |
| Retraction on every Political story, always draws | 49% |

- **Correction to the earlier finding:** blocking Scandals was not what
  helped. Political was short of cards: it drew ~7 a game to Social's
  ~30. The Intelligencer's 30% came from the card it drew per attack.
- With Retraction (4+, always draws): Political wins the most elections
  (7.2 a game) and draws ~23 cards; games shorten to ~25 rounds; seats
  36/34/30. One theme deck among two balanced (fair 33%): Political 26%,
  Economic 34%, Social 36%, attacker 29%, never-negative 18%.
- Papers on a balanced deck (fair 33%): Globe 34%, Argus 28%,
  Intelligencer 44%, Journal 41%, Herald 16%, Sun 45%, North Star 27%,
  Aurora 33%. Next: bring the Intelligencer and Sun down and the Herald
  up.

### 2026-09-28: balancing the papers

Each paper on a general-purpose (balanced) bot among two balanced bots
with random papers; fair 33%.

| paper | before | final ability | after (600 games) |
| --- | --- | --- | --- |
| The Washington Globe | 34% | Political influence counts toward any candidate (dropped +1 Political per Political story) | 30% |
| The Albany Argus | 28% | +1 prestige per election won (was +2; +3 overshot to 44%) | 32% |
| The National Intelligencer | 44% | never gains Scandals, no card draw | 35% |
| The Journal of Commerce | 41% | 1st and 2nd Economic story each turn +1 influence (the 2nd drew a card) | 38% |
| The New York Herald | 16% | pay **2** to put the top story of the main deck **into your hand** (was 3, to discard) | 39% |
| The Sun | 45% | discard a **Scandal** to draw **three** (could discard Letters or Notices and draw one: 45-52%) | 32% |
| The North Star | 27% | two Social stories: draw a card (draw 2 pushed Social decks to 45%) | 32% |
| The Aurora | 33% | unchanged | 38% |

Spread 32 points -> 9 (7 on the final 300-game recheck).

Two changes outside the papers were needed to keep the rest balanced:

- **Retraction now on Political stories costing 3+** (19 cards, was 4+):
  once the papers changed, Political lost its best partner (the old
  Intelligencer's draw) and Social rose to 45% head to head.
- **Turn-order catch-up** (new rule, not the user's): on its first turn
  each later seat gets +1 influence per seat (seat 2 +1, seat 3 +2, ...).
  Without it, 3 seats won 43/31/25 and 4 seats 36/27/21/15. With it:
  2 seats 51/49, 3 seats 32/31/37, 4 seats 22/26/26/26.

Themes with the final papers: one theme deck among two balanced (fair
33%): Political 29%, Economic 30%, Social 31%. Three theme decks head to
head: Political 39%, **Economic 23%**, Social 39% -- Economic lags when
all three focused decks meet; the Journal's card draw did not fix it
(23%). Negative stories still near-essential: attacker 22%, never buying
them 21%. Games ~24 rounds.

### 2026-09-28: Economic compounds

Economic lagged when all three focused decks met (22-23%). Tried in
memory (Political / Economic / Social; head to head, and one theme deck
among two balanced; fair 33%):

| change | head to head | among balanced |
| --- | --- | --- |
| as was | 38 / 22 / 40 | 27 / 30 / 32 |
| +1 influence on every Economic story | 41 / 31 / 28 | 22 / 34 / 24 |
| +1 Economic on every Economic story | 32 / 36 / 32 | 26 / 38 / 25 |
| +1 influence on Economic stories cost 4+ | 38 / 30 / 32 | 22 / 32 / 28 |
| **every Economic story: +1 influence per other Economic story this turn** | **34 / 31 / 36** | **28 / 37 / 30** |

Adopted the last (600 games): it is Economic's identity -- the
engine-builder that compounds -- and the only change that evened the
head-to-head without sinking Political or Social. Now on all 26 Economic
stories (19 gained it). Rechecked: papers on a balanced deck 29-41%
(Globe 34, Argus 35, Intelligencer 33, Journal 39, Herald 41, Sun 31,
North Star 29, Aurora 35); seats 3p 36/32/33, 4p 22/22/28/28; ~23
rounds. Still: never buying negative stories 17%, attacker 22%,
bigmoney 1%.

### 2026-09-28: negative stories

Before: the middle line won; both extremes lost. Never buying negative
stories 14-17%, leaning on them 22-26% (fair 33% among two balanced).
Negative stories carried the same influence and prestige as any story
plus an attack, so skipping them skipped a quarter of the exchange and
its points; and an attack hurts every rival equally, so the attacker
gained nothing over the rest of the table.

| change | attacker / never-negative, among 2 balanced (fair 33) | at 4 seats: attacker / never / balanced (fair 25) |
| --- | --- | --- |
| as was | 26 / 14 | 12 / 24 / 32 |
| -1 influence on negative stories | 11 / 32 | 6 / 30 / 32 |
| +1 influence per rival hit | 42 / 6 | 48 / 9 / 22 |
| both | 34 / 12 | 36 / 15 / 25 |
| -2 influence, +1 per rival hit | 18 / 20 | 17 / 22 / 31 |
| negative stories worth 0 prestige | 20 / 31 | 7 / 42 / 26 |
| 0 prestige, +1 per rival hit | 40 / 23 | 46 / 14 / 20 |
| 0 prestige, +1 prestige per rival hit | (attacks rewarded in prestige: attacker 64-74%) | |
| **0 prestige, +1 influence if the attack hits anyone** | **31 / 24** (600 games) | **23 / 27 / 25** |

Adopted the last: **negative stories are worth no prestige** (nobody is
honoured for a smear), and **an attack that hits any rival gives its
paper +1 influence** that turn. All 27 negative stories updated in
`docs/deck-dc.csv`. Rechecked: themes head to head 29 / 37 / 34
(Economic a little high); papers 30-43% (Herald 43%); seats 2p 51/49, 3p
32/33/35, 4p 25/25/27/23; games ~20 rounds (were ~23); a paper now makes
~8 attacks and takes ~4 Scandals a game (were ~12 and ~5).

### 2026-09-28: engine and UI build planned

`docs/BUILD-PLAN-DC.md`. Decisions (the user): cards played one at a time
plus Play all, with prompts for choices; Defense automatic; papers chosen
in the lobby (bots take the rest at random -- the simulator dealt them at
random); PHP 8.3 installed locally for linting and engine tests.

### 2026-09-28: milestone 1 -- content pipeline

`tools/export_cards_php.py` writes `backend/cards_dc.php` (`dc_cards()`,
`dc_elections()`, `dc_papers()`, `dc_card($key)`, `dc_election($i)`) from
the three CSVs, through the simulator's own loader so engine and
simulator read the same cards. `--check` runs PHP and compares every
field: 110 card kinds, 17 elections, 8 papers, 3,256 fields match; a
hand-edited value is caught. The variant deploy now runs the check after
the PHP lint. Nothing uses the file yet; the live game is unchanged.

### 2026-09-28: milestone 2 -- the engine core

`backend/engine_dc.php` (not yet loaded by any endpoint; the live game is
unchanged): the DC-style rules with the same entry points as engine.php
(`engine_setup`, `engine_apply_action`, `engine_run_bots`,
`engine_public_state`, `engine_available_actions`, `engine_build_export`,
`engine_record_scores`), state version 9, no database migration. Actions:
play, play_all, choose (trash / gain prompts), elect, buy, paper (the Sun,
the Herald), end_turn, concede. Defense automatic; payment allocated by the
engine (themed first, then Campaign, then plain); the exchange refills at
the end of a turn (at once after a gain); the Herald's scoop joins the
next hand (the simulator's timing); a tie for the win goes to more
elections won. A placeholder bot (play all, elect, buy the dearest) until
milestone 3.

`tools/engine_test.php` (local PHP, no database): 1,861 rule checks
(setup, play, buying and electing payment order, the Globe, attacks,
Defense, the Intelligencer, the +1 for a hit, Retraction, the Journal,
North Star, Sun, Herald, Argus, media events, catch-up and rounds, trash
and gain prompts, the ending after 1860, concede, hidden information),
then 300 games of random legal moves at 2-5 seats with every invariant
checked after every action (each card exactly once; Editorials and
Scandals conserved; one election card per election; pools never negative;
public counts and prestige match): 146,523 actions, 25 million checks, 0
failures. Found and fixed: setup did not fill the exchange.

### 2026-09-28: milestone 3 -- bots on the engine

The simulator's bot (value, score by style, choose, trash and gain
picks, elect the man reachable with the least plain influence, buy while
worth it, the Sun first and the Herald's scoop last) ported to
`engine_dc.php` as `dc_bot_turn`, with the simulator's styles
(balanced, political, economic, social, attacker, pacifist, bigmoney).
Both bot levels play "balanced" for now. Bot turns are logged in plain
words ("The New York Tribune: 6 cards played (9 influence); elected John
Adams (1796).").

Parity (`tools/parity_dc.py`: all-bot games on the engine via
`tools/engine_botgames.php` against the same match-ups in
`simulate_dc.py`, 300 games each): rounds, prestige, elections per
paper, purchases, attacks, Scandals taken, history rewritten and
completion all within ~3%. Seat win rates within noise: over four seeds
of 1,500 four-bot games, first seat 24.1% on the engine and 22.3% in the
simulator; each varies as much against itself.

Local PHP now has a php.ini with mbstring enabled (the server has it;
the engine uses mb_strlen for log lines).

### 2026-09-28: milestone 4 -- the new engine live, behind an opt-in

A game created with `"engine": "dc"` plays `engine_dc.php`; every other
game (the whole current UI) still plays the newsroom game. `lib.php`
gains `vg_engine_of_config` / `vg_require_engine` /
`vg_require_engine_for_game`; `createGame` stores the choice in the
game's config; start, play, state and export load that game's engine.
DC games are kept off the lobby list and exported raw by the bulk export
until the UI lands.

`tools/smoke_dc.py` plays DC games through the live endpoints: solo
against 1-3 bots (easy and hard) and two people + a bot (join, start,
turns alternate, "not your turn" enforced, the second person never sees
the first's hand), with refusals (card not in hand, unaffordable
election and story, unknown action) and checks after every action (one
paper on turn = current_seat; my hand / deck / discard match the public
counts; my prestige adds up; nothing private in any seat's public block;
pools never negative; exchange at most five; the ending, scores, winner
and export). Two runs: 8 games at 2-4 seats, 14,353 checks, all clear;
17-25 rounds, history rewritten in 2-9 of 17 elections -- the
simulator's shape. The current game's smoke test still passes (1,993
checks). One run lost a request to a dropped connection from the host
(WinError 10054); actions are not retried, since a retry could apply
twice.

### 2026-09-28: milestone 5 -- the UI; the DC game is now the default

New tables play the DC-style game (`createGame` defaults to engine
`dc`; `"engine": "newsroom"` still makes the old one, which the old smoke
test now asks for). The lobby lists only DC tables, with the eight
newspapers and which each table has taken; the host (`createGame`) and a
guest (`joinGame`) may choose a paper, and a paper already taken at a
table is refused. The state carries `engine: 'dc'`.

Frontend: `GameShell` routes a DC state to `views/DcShell.jsx` (an old
game still in progress keeps its old screen). New components in
`components/dc/`: Card (cost, type band, prestige, effect chips, flavour),
ElectionPanel (both men, themes, thresholds, your reach, Elect),
ElectionStrip (1796-1860: who elected whom, history rewritten marked),
ExchangeRow (five stories, Editorial pile, Buy lit when affordable),
TurnArea (pools, played cards, hand -- click to play -- Play all, the
Sun / Herald button, End turn), PromptModal (destroy / gain), PapersPanel
(each paper's newspaper, ability, prestige, offices, counts, media
events), FinalScores (prestige by source), RulesDc. Lobby: a newspaper
picker for opening and for joining a table; new title line.

Previewed through the dev server against the live backend (a game
created through the API, its seat loaded into the browser): opening
position, a turn in progress, a trash prompt, and the final count all
render; fixed on sight: "new" tags covering costs (and marking every
1796 story), truncated negative labels, empty card middles (now the
flavour line).

### 2026-09-28: milestone 6 -- polish

- High scores: `highScores.php?engine=dc` keeps only the DC game's rows
  (their breakdown carries `prestige`; the older game's scored money) and
  returns each row's newspaper and elections won. The lobby board is now
  "Most honoured papers on record": prestige, newspaper, offices.
- The Herald's text matches its timing: the scooped story joins your next
  hand.
- Playtest notes: the snapshot already stored the whole state;
  `submitReport.php` no longer loads the old engine.
- The old game's screen is retired: BoardStrip, Clipping, CommitBoard,
  Exchange, NationGauge, News, Reveal, Rules and Track removed (bundle
  282 -> 238 kB). A table still on the older rules gets a notice (export,
  or back to the lobby); its engine stays on the server for the API.
- CLAUDE.md describes the DC game, its content pipeline and its tests.
- Wording (the user): the rules say "candidate", not "man" -- the rules
  sheet, the influence tooltips, the engine's error message, and every
  election card's text ("Elect one candidate: reach that candidate's
  threshold ... +N of the candidate's theme"). Historical flavour lines
  ("the Jackson men") are unchanged.

### 2026-09-28: cards in The Historians' style (the user)

Reused from The Historians (`Historians_(Board_Game)/board/frontend`):
the thin card (CardThumbnail: w-28 paper tile, gold-700 border, inset
gilt hairline, `surface-paper` linen weave, hover lift), the CardModal
(paper document with CornerOrnaments and a FleuronDivider, Close, paging
with chevrons and the arrow keys, Escape), the centered hand fan
(BoardHandFan, without its tag flags), and its card shadows and easing.
Clicking a card now opens it; the modal carries Play (hand) or Buy
(exchange) when the server allows it. Titles and abilities are centered;
the hand, exchange and played cards are centered rows. Tightened: the
election panel is about half its height (slim Elect buttons), Buy shows
only under what you can afford, panels use less padding.

### 2026-09-28: drag to play; the exchange moves left (the user)

With clicks now opening a card, there was nowhere to drag one. The table
is three columns on a desktop: the exchange down the left (two cards
abreast, Buy under what you can afford), the election and "the press" in
the middle -- a dashed drop zone holding what has been played this turn,
with the centered hand below -- and the papers and the wire at the right.
Drag a hand card into the press to play it (native drag and drop; only
cards the server lists as playable can be dragged, and the server
re-checks); click still opens it. Checked by firing the drag events on a
live test game: the card carried its key, the play reached the server,
the hand went 4 -> 3. On a phone the columns stack.

### 2026-09-28: first playtest; middle-click to play

- **Playtest, game 38** (the user, the Washington Globe, vs one easy bot,
  the New York Herald): 67 to 70, the bot won; 21 rounds; the user
  elected 9 of 17, the bot 8; history rewritten 3 times (1808, 1824,
  1848). The user: "the game feels ok."
- Middle-click a hand card to play it (the user's request): the mousedown
  is swallowed so Windows does not start its auto-scroll, and the play
  fires on auxclick. Drag and click-to-open still work.

### 2026-09-28: election cards as DC's Super-Villains (the user)

"The election cards should do more powerful things" -- modelled on the
Heroes Unite Super-Villains (a strong power when played, and a "First
Appearance" attack when revealed). One power per election (the user's
choice), plus the elected candidate's themed bonus as before; a First
Appearance event for each election after 1796 (no choices, so nobody
waits; Defense does not stop it; the Intelligencer never gains Scandals).
Prestige is shown as a gilt seal on every card (the user: "cards need to
have prestige on them" = show it prominently).

| year | card | power (when played) | First Appearance |
| --- | --- | --- | --- |
| 1796 | The Farewell Address | +2, draw 1 | -- |
| 1800 | The Revolution of 1800 | +2, draw 2, destroy 1 | Sedition Act: office leaders discard 1 at random |
| 1804 | The Louisiana Purchase | +2, gain a story <=4 | 12th Amendment: each draws 1 |
| 1808 | The Embargo | +4 | Embargo: each discards its dearest card in hand |
| 1812 | Mr. Madison's War | +1 per different kind of card played | Impressment: prestige leaders gain a Scandal |
| 1816 | Good Feelings | draw 3 | Good Feelings: each destroys a Scandal |
| 1820 | The Missouri Compromise | destroy up to 2, draw 1 each | Fire Bell: office leaders discard 1 at random |
| 1824 | The Corrupt Bargain | recover 2 from discard to hand | Corrupt Bargain: prestige leaders gain a Scandal |
| 1828 | The Tariff of Abominations | +3, draw 1 | Mudslinging: prestige leaders gain a Scandal |
| 1832 | The Bank War | gain a story <=6, draw 1 | The Veto: the exchange is swept and dealt afresh |
| 1836 | The Specie Circular | +3, draw 2 | Panic of 1837: office leaders discard 2 |
| 1840 | Log Cabin and Hard Cider | +4 | Tippecanoe: each draws 1 |
| 1844 | Manifest Destiny | +2; look at the top 3, keep 1 | Fifty-four Forty: fewest offices draw 2 |
| 1848 | The Free Soil Revolt | +1 per kind played, draw 1 | Wilmot Proviso: each destroys its cheapest card in hand |
| 1852 | The Compromise of 1850 | destroy up to 3, draw 1 each | Fugitive Slave Act: prestige leaders gain a Scandal |
| 1856 | Bleeding Kansas | +5; attack: each rival gains a Scandal | Bleeding Kansas: office leaders discard 2 |
| 1860 | Secession Winter | +6 | Secession: each destroys its cheapest card in hand |

Simulator (`election_powers`, 450-600 games):
- As first drafted (with two "stays in play" cards, 1796 +1 and 1840 +2
  every turn, and most events hitting everyone), the paper that won most
  of the first six elections won the game 56% (36% with the old cards;
  fair 33%): a snowball. Dampers, each tested: no stays-in-play 52%; three
  Scandal events aimed at the prestige leader 49%; four discard events
  aimed at the office leader 45%. Adopted all three.
- Final: early election leader wins 45%; themes head to head 29/32/39,
  among balanced 31/39/34; papers 29-39% (spread 9); seats 3p 32.9/33.2/
  33.9 and 4p 23.8/24.9/27.2/24.1 (1,500 games); games 16-19 rounds (were
  18-23).

Engine: the powers and First Appearance in `engine_dc.php` (a prompt
queue for destroy-up-to-N-and-draw, recover, and look-at-the-top-3; the
cards being looked at are a zone of their own and go to the bottom of the
main deck if the turn ends). `tools/engine_test.php`: 3,092 rule checks
and 300 random games (133,130 actions, 22.7M invariant checks), 0
failures; it caught stories being lost when a turn ended mid-choice.
Parity with the simulator within ~2% on every aggregate.

### 2026-09-29: 1860 is worth prestige per office (the user)

The game ends after the turn 1860 is decided, so Secession Winter's
"+6 influence" could never be used (the user). Now it has no action and
is worth 1 prestige for each election card its holder owns, itself
included (DC's variable-VP cards; `vp_per_office` in elections-dc.csv).
Simulated (600 games): the paper that wins 1860 holds 6.2 offices on
average, so the card is worth ~6 instead of a flat 7; the 1860 winner
wins the game 59% (62% flat), early leaders 46% (45%), themes unchanged.
Its seal reads "★1/office".


### 2026-09-30: each candidate is his own card (the user)

"Make the candidates different cards." An election had one card and one
power, whichever candidate was elected. Now each of the 34 candidates has
his own card: its name, prestige and power. Choosing whom to elect
changes what you gain: Adams 1796 is +2 and draw 1, Jefferson 1796 draws 2.

- Content: `docs/candidates-dc.csv`, one row per candidate (theme,
  threshold, card name, prestige, power). `docs/elections-dc.csv` keeps
  only the order, the era, history's choice and the First Appearance,
  which still belongs to the election.
- First draft: history's choice keeps the power the election card had
  (it was themed on him), except Taylor 1848 (+4, attack: discard). His
  rival gets a power of about the same strength in the same vocabulary:
  influence, draw, destroy, gain, recover, per kind, look at the top 3,
  attack. Douglas 1860 is worth 2 plus 1 per office; Lincoln is worth 0
  plus 1 per office, but he costs 2 less. The user will edit these.
- Bots used to elect whoever cost the least plain influence. Now they
  weigh each card's worth against that cost (`power_choice` 0.5).

Simulated (900 games, 3 balanced papers; noise is about ±2-3 points):

| | shared card | own cards, cheapest | own cards, weighed |
|---|---|---|---|
| early election leader wins | 46.5% | 47.9% | 48.6% |
| 1860 winner wins | 53.8% | 57.8% | 53.7% |
| history rewritten | 23.7% | 23.3% | 29.8% |
| themes head to head (P/E/S) | 28/33/39 | 30/32/38 | 29/34/38 |
| papers (range) | 30-41% | 29-40% | 28-40% |

Balance is unchanged, and the choice matters: history is rewritten 30% of
the time instead of 24%. Rivals elected most often: Clinton 1812 (+2,
draw 2) 80%, Jackson 1824 81%, Scott 1852 (+5, draw 1) 74%. Least often:
Pinckney 1808 1%, Jefferson 1796 2%, Adams 1800 2%. The paper that elects
Douglas in 1860 wins 67% of the time (Lincoln 55%).

Engine: `dc_view` reads the candidate's card; the election in the public
state carries both cards (`nation.card`, `states.card`); the bots weigh
the cards the same way. `tools/engine_test.php`: 3,101 rule checks, 0
failures. Parity with the simulator: history rewritten 0.29 vs 0.30.
UI: each candidate's box on the election panel shows his card (its
prestige seal and power); clicking it opens the card. The workbook has
an Elections sheet and a Candidates sheet (one column per effect).

### 2026-10-02: card artwork prompts (the user)

The user asked for artwork that follows the printing of each period,
from woodblock to lithograph, made with an image model. Every card now has
a prompt in `docs/art-prompts.csv` (built by `tools/build_art_prompts.py`,
also on the workbook's "Art prompts" sheet): 144 prompts, one per story
kind, starter, Editorial, Scandal and candidate card. Each prompt is the
style of the card's own year plus a subject written for that card:
woodcut to 1819 (66 cards), wood engraving 1820-1835 (27), lithograph
1836-1860 (51). Monochrome, 4:3, no lettering. Cards about enslaved and
Native people ask for dignified figures and no caricature, since period
prints often caricatured them. Images go in `frontend/public/art/<file>`.
The card modal shows a card's image when it exists.

### 2026-10-03: the 2024 copy (the user)

The whole variant, as of commit 668ffe2, copied to a new branch `2024`
with its own site, https://2024.davidsbennett.com, and its own database.
The two now change independently. What differs from the variant: the
deploy workflow (`deploy-2024.yml`, pushes to `2024` only), the site the
dev server, smoke tests and high scores point at, the lobby label and tab
title, and the edition recorded on new games and scores ('2024').

## Revision 5 (2026-10-03, the user): the 2024 electoral college

"Entirely rebuild the game rooted in the 2024 election": the 17 elections
and their candidate pairs become the 51 contests of 2024 (the 50 states
and D.C.). Chosen by the user:

- **Players** stay media outlets, each with an ability (2024 outlets to
  come; the eight 1800s papers stand in for now).
- **All 51 contests**, winner-take-all (Maine to Harris, Nebraska to
  Trump: still 312-226), from the certified results.
- **A map line-up**: 4 states face up, dealt from the state deck; an
  outlet may call as many as it can afford on its turn.
- **Call it for Trump or Harris**: each side has its own threshold and
  card. History's winner is cheaper by how far history is rewritten.
- **Score in electoral votes**: a state is worth its EVs; a story's
  prestige counts 6 EV per star.
- **Safe states first, toss-ups last**, shuffled within each tier; the
  calendar moves on a step every 3 calls, releasing that step's stories.
- **Neutral beats**: Politics, Economy, Culture (the engine's Political /
  Economic / Social).
- **2024 news, dated** (to come): the story deck rewritten for 2024.

Content: `docs/states-2024.csv`, built by `tools/build_states_2024.py`.
History's side costs 1 + 1.8 x sqrt(EV) (3 EV: 4, California 14); the
other side half of 1 + |margin| / 5 more, rounded up (toss-ups +1,
California +3, D.C. +9). Each side is called on its own beat, chosen so
each candidate's votes fall evenly on the three beats (Trump
168/185/185, Harris 181/180/177), so no beat belongs to a side. Each
side's card has a power by the state's size, in two versions of equal
worth alternating between the sides. Simulator: `tools/simulate_2024.py`
(for now on the 1796-1860 story deck, its 17 years as calendar steps).

### 2026-10-03: first simulator passes

Balanced bots, 400-2,400 games a line:

- First draft (thresholds 2 + 2.4 x sqrt(EV), full rewrite premium): 25
  rounds at 3 outlets, history rewritten 0.2% (both sides shared the
  state's beat), seats 39/31/30.
- **The round is played out** after the last call: the game had stopped
  at once, giving the first seat 0.4 more turns. 4 outlets became fair
  (26/23/28/23); 3 still favoured the first seat.
- **Each side its own beat**: history rewritten 5% (from 0.2%). A first
  version gave Harris mostly Politics and Trump mostly Economy and
  Culture; rebalanced so neither side owns a beat.
- **Thresholds lowered** to 1 + 1.8 x sqrt(EV): 20 rounds at 3 outlets.
- **Rewrite premium halved** (the user): rewritten 7-8%; Harris reaches
  270 in 14-17% of games. (Halving with toss-ups at no premium gave 13%
  and 28%.)
- **Catch-up** (the user asked for an extra card): the first seat leads
  on tempo, a turn ahead in every round, and +1 influence on the first
  turn swings a seat by ~10 points. Tried at 900-2,400 games: an extra
  card in the first hand barely helped; an extra Letter in the deck
  hurt the later seats (it thins the deck). **An Editorial shuffled into
  the deck, one more per seat (0/1/2/3)**, works best. Over 6,000 games
  at 3 outlets: 36.8/32.3/30.9 (old +1 influence 40.1/32.1/27.8); 2
  outlets 52.5/47.5; 4 outlets 25.7/23.0/23.9/27.5. Adopted.
- Still open: calls squeeze out buying (8-10 stories bought per outlet,
  ~25 before; stories 20-30% of the score); the early leader wins 52% at
  3 outlets; among the old papers the Herald wins 51% and the Argus 39%,
  and at a three-beat table Politics wins 42%.

### 2026-10-03: two decks, three currencies, Stake (the user)

- **The elections deck is the ten biggest states** (CA, TX, FL, NY, IL,
  PA, OH, NC, GA, MI: 254 EV), shuffled, one up at a time, called once a
  turn for Trump or Harris; each call moves the calendar on a step (10
  steps); the game ends when the tenth is called.
- **The main deck holds the other 41 states** (284 EV), all from the
  start, with 65 of the 106 stories (each kind keeps its share;
  `tools/build_deck_2024.py` -> `docs/deck-2024.csv`). A state on the
  exchange is bought for a side at that side's threshold.
- **Three currencies** replace the beats: neutral (anything), Republican
  (Trump's side of a state, Republican stories), Democratic (Harris's,
  Democratic stories). Most cards pay neutral plus one party; stories lean
  half each way within each kind (33 Republican, 32 Democratic).
- **States are the strongest cards at every price**: each state card is
  worth 0.6 (in the simulator's value()) more than the best story at its
  cost, the big ten 2.1 more. E.g. a 3-EV state costs 4 and pays +4
  neutral, +1 party, draw 1.
- **Stake**: instead of playing its hand, an outlet may spend its turn
  setting one card from it aside, face down, on Trump or Harris. At the
  end the candidate with the most EVs wins (claimed states for their side;
  unclaimed as in 2024); a staked card on the winner scores its worth plus
  a bonus (12 EV to start), on the loser nothing.

First pass (`tools/simulate_2024.py`, 300-400 games, balanced bots):
games of 10-12 rounds (only ten calls, and a rich economy); a played turn
is worth ~24 EV at 3 outlets. **Harris wins 1-3% of games**: ~30% of the
EVs go unclaimed and count as in 2024, and history's side is cheaper, so
every bot stakes Trump and every stake pays (3.4 stakes per outlet).
Counting unclaimed states for nobody: Harris 20%. Seats at 4 outlets
8/15/39/38 (the Editorial catch-up is too much in a short game); early
leader wins 62% at 3.

### 2026-10-03: the race to 270 (the user)

- **Flipping costs by margin**: the other side costs 1 more per full 6
  points of 2024 margin. The seven swing states, and NH, MN, VA and NJ
  (as close), cost the same either way; Texas 12 / 14, California 14 /
  17, D.C. 4 / 17.
- **States count only when claimed**: a state counts for a side once an
  outlet calls or buys it for that side; unclaimed states count for
  nobody. **The game ends when a candidate reaches 270** (the round is
  played out); that candidate wins the stakes.
- Found: games stalled (a third or more, even without Stake) when the
  exchange filled with negative stories nobody wanted and the states
  behind them never came up. **The news cycle** (proposed, not yet
  approved): at the end of every turn the oldest exchange card goes to the
  bottom of the main deck. With it, 3 outlets, no Stake: 18 rounds, 9%
  stalled (39% without it).
- A bug: a bought state that crossed 270 was counted a moment too late.

3 outlets, 300 games: with the 12-EV stake bonus, 12 rounds, 1% stalled,
Harris wins 29%, 2.3 stakes per outlet -- **and every stake wins**: bots
stake only once the race is decided. Staked card worth x2 (no bonus):
17 rounds, Harris 20%, every stake wins too. Seats 26/36/38: the
Editorial catch-up now overshoots.

### 2026-10-03: only the stake pile scores; prestige 1-12 (the user)

- **Stakes are flat**: a staked card scores its prestige if its candidate
  reaches 270, nothing otherwise; no bonus.
- **Only the stake pile scores.** Cards in decks score nothing; states
  still count toward 270 for their side, staked or not.
- **Every card has prestige, 1-12**, relative to California's 12: a state
  is 12 x EV / 54, rounded, at least 1 (Texas 9, Florida 7, New York 6,
  Pennsylvania 4, 3-6 EV states 1). Stories 1 / 3 / 4 for the old 1 / 2 /
  3 stars; Letters, Local Notices, negative stories and Editorials 1;
  Scandals -1. State powers recomputed so each still beats the best story
  at its price with prestige counted (`build_states_2024.py` now measures
  docs/deck-2024.csv itself).
- Bots stake their highest-prestige card when its expected prestige (x
  the chance the side ahead wins) passes a bar falling from 6 to 2 as a
  side nears 270, never two turns running; after 270, always. Without the
  floor every bot staked every turn near the end and no one finished the
  race (a table can stall the race on purpose -- a rules question).
- **The catch-up is off**: with no extra Editorials the seats are 49/51,
  37/31/32 and 25/24/24/28 (900 games); 0/1/1/1 gave 28/36/35.

600 games: games end at 270 (99.5%) in 14.5 rounds at 3 outlets (12.8
at 4); Harris wins 50-53%; history rewritten 22%; ~2.3 stakes per outlet,
mostly states, nearly all on the winner; winning score ~17 prestige.
Staking earlier or later than the rest, backing one side always, attacking
more or never: all within noise of fair at 3 outlets. The stand-in Argus
(+1 prestige per big state) wins 50%.

### 2026-10-03: the tension -- trigger or stall (the user)

"The tension of the game should be rooted in players wanting to trigger
the end game when it's advantageous to them, and others wanting to stall
the game." Chosen: **no clock** (only 270 ends the game); the trigger is
**crossing 270**; the stall is **blocking** (buying states for the side
behind). No new rules; the bots learn the decisions:

- A bot claims the state that takes a side to 270 only if it expects to
  top the table: its own stakes on that side against each rival's staked
  card count (sides hidden: half, at the mean prestige staked), plus one
  sure stake for each rival still to play this round.
- While the side ahead is within 60 of 270 and its finishing would not
  pay, a state for the side behind is worth +2 to the bot (a block).

400 games, naive bots vs these: rounds 16.5 -> 18.1 (2 outlets), 13.9 ->
18.2 (3), 13.0 -> 19.8 (4); games that never reach 270 (80 rounds) 1% ->
5% at 3 and 9% at 4 outlets (no clock: no one will finish); some stakes
now land on the loser (2.5 of 2.7 win at 3). Seats 37/32/31 (3), 26/26/
24/24 (4). Harris 44-51%.

### 2026-10-03: content, engine and screen (the user)

- **Story cards**: the 65 slots' mechanics kept; each is now a dated 2024
  news story (`docs/stories-2024.csv`, merged by `build_deck_2024.py`),
  Republican cards stories that helped Trump, Democratic ones stories that
  helped Biden or Harris; negative stories attacks on the other side; media
  events 2024 media changes. The 10 calendar steps run from November 2022
  to Election Day (`docs/calendar-2024.csv`).
- **Outlets**: NewsNation (Crossover), the AP (Race Calls), the New York
  Times (Paper of Record), the Wall Street Journal (Business Desk),
  Politico (The Scoop), the New York Post (Tabloid), Fox News (Prime Time),
  CNN (Debate Stage) (`docs/outlets-2024.csv`).
- **State cards**: each half named for that side's 2024 base in the state,
  or "Upset in ..." (`docs/state-cards-2024.csv`); flavor = the result.
- **Engine** `backend/engine_2024.php` (tests: 1,575 rule checks and
  random games, 0 failures; parity with the simulator within noise) and
  **screen** `frontend/src/views/Shell2024.jsx`: race bar, map, big state,
  exchange with a buy per side, the press and Stake, outlets, stake pile,
  the final count. New tables play the 2024 game.
