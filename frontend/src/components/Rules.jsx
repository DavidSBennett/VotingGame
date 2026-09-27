/**
 * The whole rulebook, printed on a parchment sheet. Numbers come from the
 * game's own config when there is one, so the rules shown can never
 * disagree with the rules played.
 *
 * `inline` renders it in place (the lobby); otherwise it is a sheet over
 * the table with a close button.
 */
function Body({ rules }) {
  const mult = rules ? rules.patron_multiplier : 2;
  const draw = rules ? rules.draw_per_round : 2;
  const maxNeg = rules ? rules.max_negative : 1;
  const penalty = rules ? rules.exposure_penalty : 25;

  const H = ({ children }) => (
    <h3 className="mt-5 font-mono text-[10px] uppercase tracking-[0.3em] text-ink-950/60">{children}</h3>
  );

  return (
    <div className="font-serif text-[15px] leading-relaxed text-ink-950/85">
      <p>
        You run a partisan newspaper, 1796 to 1860. Seventeen elections, each a{' '}
        <span className="font-semibold text-federal-700">Nation</span> man (federal power) against a{' '}
        <span className="font-semibold text-oxblood-700">States</span> man (states&rsquo; rights).{' '}
        <em>The richest paper at the end wins</em> — and money comes only from selling your clippings.
      </p>

      <H>Each round, in secret</H>
      <p>Put as many clippings on the table as you like, or none and pass. Where you put one decides what it does:</p>
      <ul className="mt-1 list-none space-y-1 pl-0">
        <li>
          <span className="font-semibold text-wood-700">Cash in</span> — sell it for its profit.
        </li>
        <li>
          <span className="font-semibold text-ink-950">On a candidate</span> — run the coverage that helps him: its push
          goes on the track, and counts as your influence on him. If that coverage is{' '}
          <span className="font-semibold text-oxblood-700">hostile</span>, it pushes harder than the favourable kind but
          costs the Union stability — only {maxNeg} such clipping a round.
        </li>
      </ul>
      <p className="mt-1">Star one clipping you ran as coverage to reserve it.</p>

      <H>The reveal</H>
      <ul className="list-none space-y-1 pl-0">
        <li>Every push is added up. The side the track leans toward wins the election.</li>
        <li>
          The most influence on the winner makes you <span className="font-semibold">Patron</span>: next round your sales
          pay {mult}×.
        </li>
        <li>Everyone but the new Patron takes their reserved clipping back. Everyone draws {draw}.</li>
      </ul>

      <H>The Union</H>
      <ul className="list-none space-y-1 pl-0">
        <li>Hostile coverage wears it down, and so does any election that goes against history.</li>
        <li>Each election it recovers a little.</li>
        <li>
          Every hostile clipping adds to your <span className="font-semibold">exposure</span>.{' '}
          <span className="text-oxblood-700">
            If the Union breaks, the game ends at once and the most exposed paper loses {penalty}.
          </span>
        </li>
      </ul>

      <H>The deck</H>
      <p>
        Clippings are dated. The deck opens on the Revolution, and each campaign brings the news of the years since the
        last. Some are the business of the press itself — worth money, but they push nobody.
      </p>
    </div>
  );
}

export default function Rules({ rules, inline = false, onClose }) {
  if (inline) {
    return (
      <div className="parchment border border-gold-500 p-6 shadow-card sm:p-8">
        <div className="text-center font-display text-2xl font-bold text-ink-950">How to play</div>
        <div className="mx-auto mb-2 mt-2 h-px w-1/2 bg-ink-950/30" />
        <Body rules={rules} />
      </div>
    );
  }
  return (
    <div
      className="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-ink-950/80 p-4 backdrop-blur-sm animate-fade"
      onClick={onClose}
    >
      <div
        className="parchment relative my-8 w-full max-w-2xl border border-gold-500 p-6 shadow-lift animate-rise sm:p-8"
        onClick={(e) => e.stopPropagation()}
      >
        <button
          type="button"
          onClick={onClose}
          className="absolute right-4 top-3 font-mono text-[10px] uppercase tracking-[0.2em] text-ink-950/60 hover:text-ink-950"
        >
          Close ✕
        </button>
        <div className="text-center font-display text-3xl font-bold text-ink-950">How to play</div>
        <div className="mx-auto mb-2 mt-2 h-px w-1/2 bg-ink-950/30" />
        <Body rules={rules} />
      </div>
    </div>
  );
}
