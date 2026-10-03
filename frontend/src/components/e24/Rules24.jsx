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
        only on Trump (calling or buying a state for him) and on Republican stories;{' '}
        <span className="font-semibold text-federal-700">Democratic</span> only on Harris and Democratic stories.{' '}
        <span className="font-semibold">Campaign</span> spends only on calling a big state.
      </p>

      <H>Your hand pays at once</H>
      <p>
        Every card's currency counts the moment it is in your hand on your turn: at the start, and whenever you draw. You never play a
        card for money. <span className="font-semibold">Using</span> a card plays only its ability (draw, destroy, gain, attack, Retraction,
        a chain, compounding, a media event into play); a card with no ability simply pays from your hand.
      </p>

      <H>Your turn: use and spend, or stake</H>
      <ul className="list-none space-y-1 pl-0">
        <li>
          <span className="font-semibold">Stake</span> instead of playing: set one card from your hand aside, face down, on Trump or Harris.
          Discard the rest and draw five. Rivals see that you staked, never what or on whom.
        </li>
        <li>
          Or <span className="font-semibold">use</span> the abilities of the cards in your hand, and spend your currency:
        </li>
        <li>
          <span className="font-semibold">Call</span> the big state up (once a turn): the ten biggest states come up one at a time. Reach either
          side's price and claim its electoral votes for that side; its card goes into your deck.
        </li>
        <li>
          <span className="font-semibold">Buy</span> off the exchange: stories, Editorials, and the other 41 states, each bought for a side.
          States are the strongest cards at their price.
        </li>
        <li>
          <span className="font-semibold">End your turn</span>: discard, draw five. The oldest card on the exchange slides to the bottom of the
          main deck.
        </li>
      </ul>

      <H>Prices</H>
      <p>
        A state costs more the more votes it has. Flipping it costs 1 more for every 6 points it was won by in 2024: Pennsylvania, Georgia, North
        Carolina, Michigan, Arizona, Wisconsin and Nevada (and New Hampshire, Minnesota, Virginia, New Jersey) cost the same either way; D.C. for
        Trump is very dear.
      </p>

      <H>The end, and the score</H>
      <p>
        A state counts for its side once claimed. When a side reaches 270, the round is played out (later outlets get one more turn, often
        a stake), then every outlet reveals its stakes. A card staked on the winner scores its <span className="font-semibold">wealth</span> ($,
        1 to 12: California 12; a staked Letter or Local Notice costs 1); on the loser, nothing. Cards left in your deck never score. So claim the state that ends the race only when
        the end pays you, and buy for the side behind when it does not.
      </p>

      <H>Stories</H>
      <p>
        Political stories pay Campaign and grow with the states you hold; Economic stories compound; Social stories draw. A{' '}
        <span className="font-semibold text-oxblood-700">negative story</span> attacks every rival (a discard, or a Scandal); a Defense card
        in hand stops it. Media events stay in play. New stories are released each time a big state is called.
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
