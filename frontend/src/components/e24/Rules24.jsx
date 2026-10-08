/**
 * The rulebook of the 2024 game, on a parchment sheet. `inline` for the
 * lobby; otherwise a sheet over the table with a close button.
 */
function Body() {
  const H = ({ children }) => <h3 className="mt-5 font-mono text-[10px] uppercase tracking-[0.3em] text-ink-950/60">{children}</h3>;
  return (
    <div className="font-serif text-[15px] leading-relaxed text-ink-950/85">
      <p>
        You run a news outlet in the 2024 election. The outlets claim the 51 contests of the electoral college for Trump or for Harris, and
        the game ends when a candidate reaches <span className="font-semibold">270</span>.{' '}
        <em>Only what you staked on the winner scores.</em>
      </p>

      <H>Three currencies</H>
      <p>
        <span className="font-semibold">Neutral</span> spends on anything. <span className="font-semibold text-oxblood-700">Republican</span> spends
        only on Trump (buying a state for him) and on Republican stories and planks;{' '}
        <span className="font-semibold text-federal-700">Democratic</span> only on Harris and Democratic cards.{' '}
        <span className="font-semibold">Campaign</span> spends only on states.
      </p>

      <H>Your hand pays at once</H>
      <p>
        Every card's currency counts the moment it is in your hand on your turn: at the start, and whenever you draw. You never play a
        card for money. <span className="font-semibold">Using</span> a card plays only what it does; a card with nothing to use simply pays from
        your hand.
      </p>

      <H>Two sides to every story</H>
      <p>
        Each story carries two real headlines. Use it on its <span className="font-semibold">top</span>, the positive framing, for its ability
        (draw, destroy, gain, Retraction, a chain, compounding, or +1 of its party); or on its{' '}
        <span className="font-semibold">bottom</span>, the oppositional framing, for +1 or +2 of the <em>other</em> party's currency. Twenty
        bottoms also knock out a rival's plank.
      </p>

      <H>Party planks</H>
      <p>
        Twenty planks, ten from each party's 2024 platform. Use one to put it into play: it stays there, paying you each turn (neutral or a
        card), while every other outlet gets +1 of its party's currency, until a rival <span className="font-semibold">knocks it out</span> (a
        story's bottom, or a swing state used for the other side). A plank knocked out goes to its owner's discard pile.
      </p>

      <H>The main deck: Biden, then Harris</H>
      <p>
        The stories and planks come in two sets: the Biden set on top, then the switch card (Biden steps aside), then the Harris set. When the
        switch comes up it is set aside, and the Harris stories begin.
      </p>

      <H>Setting up</H>
      <p>
        Each outlet starts with 7 Letters to the Editor and 3 Local Notices. To even out the turn order, the second outlet also starts with one
        Editorial, and the third, fourth and fifth with two each, from the supply.
      </p>

      <H>Your turn: use and spend, or stake</H>
      <ul className="list-none space-y-1 pl-0">
        <li>
          <span className="font-semibold">Stake</span> instead of playing: set one card from your hand aside, face down, on Trump or Harris.
          Discard the rest and draw five. Rivals see that you staked, never what or on whom.
        </li>
        <li>
          Or <span className="font-semibold">use</span> the cards in your hand, and spend your currency:
        </li>
        <li>
          <span className="font-semibold">Buy a state</span> for a side: three decks (large, medium, small), one state face up on each. Buying it
          claims its votes for that side and puts its card in your deck; the next state turns up, and its{' '}
          <span className="font-semibold">reveal</span> (a real article from that state's own press) hits every outlet: a Scandal, a discard, or
          a fresh exchange.
        </li>
        <li>
          <span className="font-semibold">Buy</span> off the exchange: stories, planks and Editorials.
        </li>
        <li>
          <span className="font-semibold">End your turn</span>: discard (planks in play stay), draw five. The oldest card on the exchange slides to
          the bottom of the main deck.
        </li>
      </ul>

      <H>Prices</H>
      <p>
        A state costs more the more votes it has. Flipping it costs 1 more for every 5 points it was won by in 2024: Pennsylvania, Georgia, North
        Carolina, Michigan, Arizona, Wisconsin and Nevada (and New Hampshire and Minnesota) cost the same either way; D.C. for Trump is very dear.
        The seven swing states also knock out a plank when used.
      </p>

      <H>The House</H>
      <p>
        When you buy a state you may also buy its <span className="font-semibold">House delegation</span> for the same side: its seats in the
        House (electoral votes less 2; D.C. has none), at 1 per 4 seats. Only then, never later. If every state is claimed and neither side has
        270 (269 to 269), the side with more House seats wins; with a tied House the election is deadlocked and no stake scores.
      </p>

      <H>The end, and the score</H>
      <p>
        A state counts for its side once claimed. When a side reaches 270, the round is played out (later outlets get one more turn, often
        a stake), then every outlet reveals its stakes. A card staked on the winner scores its <span className="font-semibold">wealth</span> ($,
        1 to 12: California 12; a staked Letter or Local Notice costs 1); on the loser, nothing. Cards left in your deck never score. So claim the
        state that ends the race only when the end pays you, and buy for the side behind when it does not.
      </p>
    </div>
  );
}

export default function Rules24({ inline = false, onClose }) {
  if (inline) {
    return (
      <div className="parchment border border-gold-500 p-6 shadow-card sm:p-8">
        <div className="text-center font-display text-2xl font-bold text-ink-950">How to play</div>
        <div className="mx-auto mb-2 mt-2 h-px w-1/2 bg-ink-950/30" />
        <Body />
      </div>
    );
  }
  return (
    <div className="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-ink-950/80 p-4 backdrop-blur-sm animate-fade" onClick={onClose}>
      <div className="parchment relative my-8 w-full max-w-2xl border border-gold-500 p-6 shadow-lift animate-rise sm:p-8" onClick={(e) => e.stopPropagation()}>
        <button type="button" onClick={onClose} className="absolute right-4 top-3 font-mono text-[10px] uppercase tracking-[0.2em] text-ink-950/60 hover:text-ink-950">
          Close ✕
        </button>
        <div className="text-center font-display text-3xl font-bold text-ink-950">How to play</div>
        <div className="mx-auto mb-2 mt-2 h-px w-1/2 bg-ink-950/30" />
        <Body />
      </div>
    </div>
  );
}
