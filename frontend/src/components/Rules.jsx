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
        <em>The richest paper at the end wins</em> — and money comes only from burying stories.
      </p>

      <H>Each round, in secret</H>
      <p>Every story in your hand faces one decision. Commit as many as you like, or none and pass:</p>
      <ul className="mt-1 list-none space-y-1 pl-0">
        <li>
          <span className="font-semibold text-ink-950">Run it positive</span> — a favourable story for a candidate: its push
          goes on the track, and counts as your influence on him.
        </li>
        <li>
          <span className="font-semibold text-oxblood-700">Run it negative</span> — a hostile story against his opponent. It
          pushes harder than the positive run, but costs the Union stability — only {maxNeg} a round.
        </li>
        <li>
          <span className="font-semibold text-wood-700">Bury it</span> — kill the story and take its profit.
        </li>
      </ul>
      <p className="mt-1">Drop a story on a candidate to run it; it runs whichever way helps him. Star one story you ran to reserve it.</p>

      <H>The reveal</H>
      <ul className="list-none space-y-1 pl-0">
        <li>Every push is added up. The side the track leans toward wins the election.</li>
        <li>
          The most influence on the winner makes you <span className="font-semibold">Patron</span>: next round every story
          you bury pays {mult}×.
        </li>
        <li>Everyone but the new Patron takes their reserved story back. Everyone draws {draw}.</li>
      </ul>

      <H>The Union</H>
      <ul className="list-none space-y-1 pl-0">
        <li>Negative stories wear it down, and so does any election that goes against history.</li>
        <li>Each election it recovers a little.</li>
        <li>
          Every negative story adds to your <span className="font-semibold">exposure</span>.{' '}
          <span className="text-oxblood-700">
            If the Union breaks, the game ends at once and the most exposed paper loses {penalty}.
          </span>
        </li>
      </ul>

      {!rules || rules.deckbuild ? (
        <>
          <H>Your newsroom</H>
          <ul className="list-none space-y-1 pl-0">
            <li>
              Every paper draws from <span className="font-semibold">its own deck</span>, which starts with five stories
              of the founding.
            </li>
            <li>
              A story you <span className="font-semibold">run</span> goes to your discard pile and comes back when your
              deck reshuffles. A story you <span className="font-semibold">bury</span> is sold: it leaves the game.
            </li>
            <li>
              <span className="font-semibold">The exchange</span> offers stories for sale at what they would bury for.
              With your commitment you may buy {!rules || rules.max_buys === 1 ? 'one' : `up to ${rules.max_buys}`} — after the
              election, poorest paper first — into your discard pile. Money spent is score spent: invest early, cash in
              late.
            </li>
            <li>
              Stories are dated. Each campaign&rsquo;s news goes onto the exchange first. Trade stories push nobody but
              are worth money: buy them to bury as Patron.
            </li>
          </ul>
        </>
      ) : (
        <>
          <H>The deck</H>
          <p>
            Stories are dated. The deck opens on the Revolution, and each campaign brings the news of the years since the
            last. Some are the business of the press itself — trade stories, worth money but pushing nobody: bury them.
          </p>
        </>
      )}
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
