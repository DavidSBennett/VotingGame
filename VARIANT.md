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

