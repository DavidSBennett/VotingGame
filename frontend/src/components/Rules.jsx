/**
 * The whole rulebook. It fits in a panel, which was the point of the
 * simplification. Numbers come from the game's own config when there is
 * one, so the rules shown can never disagree with the rules played.
 */
export default function Rules({ rules, open = false }) {
  const payout = rules ? rules.payout : 1.5;
  const bonus = rules ? rules.patron_bonus : 2;
  const turns = rules ? rules.turns_per_space : 2;
  const crisis = rules && rules.crisis_year ? rules.crisis_year : 1848;

  return (
    <details open={open} className="rounded-lg border border-slate-700 bg-slate-800 p-4">
      <summary className="cursor-pointer text-xs uppercase tracking-widest text-slate-400">
        How to play
      </summary>
      <div className="mt-3 space-y-2 text-sm text-slate-300">
        <p>
          You run a newspaper, 1796 to 1860. Fourteen elections, each a{' '}
          <span className="text-sky-300">Nation</span> candidate against a{' '}
          <span className="text-rose-300">States</span> candidate.{' '}
          <span className="text-slate-100">The richest paper at the end wins.</span>
        </p>
        <p>On your turn, play one card:</p>
        <ul className="list-disc space-y-1 pl-5">
          <li>
            <span className="text-emerald-400">Cash it</span> — take its value in money.
          </li>
          <li>
            <span className="text-amber-300">Print it</span> — move the track by the card&rsquo;s
            push, and stake the card&rsquo;s value on either candidate. The push goes the way
            history says, whoever you back.
          </li>
        </ul>
        <p>
          After everyone has had {turns} turns, the election is held. The side the track leans
          toward wins, and the track goes back to the middle.
        </p>
        <ul className="list-disc space-y-1 pl-5">
          <li>Stakes on the winner pay back {payout}×. Stakes on the loser are lost.</li>
          <li>
            The biggest stake on the winner makes you <span className="text-amber-300">Patron</span>:
            +{bonus} every time you cash, until the next election.
          </li>
          <li>In {crisis} the sectional crisis begins, and its cards join the deck.</li>
        </ul>
      </div>
    </details>
  );
}
