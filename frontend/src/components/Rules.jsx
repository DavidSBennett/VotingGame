/**
 * The whole rulebook. Numbers come from the game's own config when there is
 * one, so the rules shown can never disagree with the rules played.
 */
export default function Rules({ rules, open = false }) {
  const mult = rules ? rules.patron_multiplier : 2;
  const draw = rules ? rules.draw_per_round : 2;
  const maxNeg = rules ? rules.max_negative : 1;

  return (
    <details open={open} className="rounded-lg border border-slate-700 bg-slate-800 p-4">
      <summary className="cursor-pointer text-xs uppercase tracking-widest text-slate-400">
        How to play
      </summary>
      <div className="mt-3 space-y-2 text-sm text-slate-300">
        <p>
          You run a newspaper, 1796 to 1860. Fourteen elections, each a{' '}
          <span className="text-sky-300">Nation</span> candidate (federal power) against a{' '}
          <span className="text-rose-300">States</span> candidate (states&rsquo; rights).{' '}
          <span className="text-slate-100">The richest paper at the end wins</span> — and money comes only from
          playing cards for profit.
        </p>
        <p>
          Each election is one round, played by every paper <span className="text-slate-100">in secret</span>.
          Commit as many cards as you like (at least one). Each card can be played one way:
        </p>
        <ul className="list-disc space-y-1 pl-5">
          <li>
            <span className="text-emerald-400">Profit</span> — take its profit in money.
          </li>
          <li>
            <span className="text-sky-300">Positive</span> coverage — its positive push goes on the track, and counts
            as that much influence on the candidate you name.
          </li>
          <li>
            <span className="text-red-300">Negative</span> coverage — its negative push, the same way, but it costs
            the Union stability. Only {maxNeg} card{maxNeg === 1 ? '' : 's'} a round.
          </li>
        </ul>
        <p>Mark one coverage card to reserve. When everyone is in, all cards are revealed:</p>
        <ul className="list-disc space-y-1 pl-5">
          <li>
            Negative coverage is paid from the Union&rsquo;s stability.{' '}
            <span className="text-red-400">If it reaches zero, the Union breaks and every paper loses.</span>
          </li>
          <li>All the pushes are added up. The side the track leans toward wins.</li>
          <li>
            The most influence on the winner makes you <span className="text-amber-300">Patron</span>: your profit
            plays pay {mult}× next round.
          </li>
          <li>Everyone except the new Patron takes their reserved coverage card back.</li>
          <li>Everyone draws {draw} cards, and the Union recovers a little.</li>
          <li>
            Cards are dated: the deck opens on the Revolution, and each round adds the events since the last.
          </li>
        </ul>
      </div>
    </details>
  );
}
