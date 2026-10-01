/**
 * The rulebook of the DC-style game, on a parchment sheet. `inline` for
 * the lobby; otherwise a sheet over the table with a close button.
 */
function Body() {
  const H = ({ children }) => <h3 className="mt-5 font-mono text-[10px] uppercase tracking-[0.3em] text-ink-950/60">{children}</h3>;
  return (
    <div className="font-serif text-[15px] leading-relaxed text-ink-950/85">
      <p>
        You run a partisan newspaper, 1796 to 1860. Seventeen elections, each between two candidates.{' '}
        <em>The most honoured paper at the end wins</em>: add up the <span className="font-semibold">prestige</span> (★) on every card you own.
      </p>

      <H>Your turn</H>
      <ul className="list-none space-y-1 pl-0">
        <li>
          <span className="font-semibold">Play</span> the stories in your hand. Each makes <span className="font-semibold">influence</span>: plain
          influence spends on anything; <span className="font-semibold text-federal-700">Political</span>,{' '}
          <span className="font-semibold text-wood-700">Economic</span> and <span className="font-semibold text-emerald-800">Social</span> influence spend
          only on stories of that theme or on electing a candidate of that theme; <span className="font-semibold">Campaign</span> only on elections.
        </li>
        <li>
          <span className="font-semibold">Elect</span> (once a turn): reach either candidate's threshold and take his card as his Patron. Each candidate has his own
          card, with its own power and prestige, so whom you elect changes what you gain. It goes
          into your deck, pays its bonus whenever you play it, and is worth its prestige. History's choice is 2 cheaper; the other candidate rewrites
          history.
        </li>
        <li>
          <span className="font-semibold">Buy</span> stories off the exchange, or an Editorial (always for sale). They go into your discard pile.
        </li>
        <li>
          <span className="font-semibold">End your turn</span>: what you played and anything left in hand is discarded; draw five. The exchange
          refills.
        </li>
      </ul>

      <H>The three kinds of paper</H>
      <ul className="list-none space-y-1 pl-0">
        <li>
          <span className="font-semibold text-federal-700">Political</span> stories win offices: themed influence, Campaign, more per office held,
          and <span className="font-semibold">Retraction</span> (draw a card and destroy a Scandal).
        </li>
        <li>
          <span className="font-semibold text-wood-700">Economic</span> stories build an engine: +1 for every other Economic story played that
          turn, and some destroy your weak cards or gain stories.
        </li>
        <li>
          <span className="font-semibold text-emerald-800">Social</span> stories bring momentum: they draw cards, reward playing two together, and
          some are Defense.
        </li>
      </ul>

      <H>Negative stories and Scandals</H>
      <p>
        A <span className="font-semibold text-oxblood-700">negative story</span> attacks every rival: each discards a card, or gains a{' '}
        <span className="font-semibold">Scandal</span> (★−1, a dead card). If it hits anyone, you get +1 influence. A Defense card in hand is used for
        you automatically. Negative stories are worth no prestige: nobody is honoured for a smear.
      </p>

      <H>Media events and newspapers</H>
      <p>
        A media event stays in play for good: a bonus for you every turn, and a smaller one for everyone else. Each paper also has its own
        newspaper, with an ability of its own.
      </p>

      <H>Dates and the end</H>
      <p>
        Stories are dated: each election's news joins the main deck when that election comes up. You begin with seven Letters to the Editor
        (+1) and three Local Notices (nothing). Later seats get a little extra influence on their first turn. The game ends after the turn in
        which 1860 is decided.
      </p>
    </div>
  );
}

export default function RulesDc({ inline = false, onClose }) {
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
