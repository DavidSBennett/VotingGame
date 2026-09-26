/**
 * The whole rulebook. It fits in a panel, which was the point of the
 * simplification. Numbers come from the game's own config when there is
 * one, so the rules shown can never disagree with the rules played.
 */
export default function Rules({ rules, open = false }) {
  const payout = rules ? rules.payout : 1.5;
  const bonus = rules ? rules.patron_bonus : 2;
  const draw = rules ? rules.draw_per_round : 2;

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
        <p>
          Each election is one round, and every paper plays it <span className="text-slate-100">in secret</span>.
          Commit as many cards from your hand as you like (at least one). For each, choose:
        </p>
        <ul className="list-disc space-y-1 pl-5">
          <li>
            <span className="text-emerald-400">Cash</span> — take its value in money.
          </li>
          <li>
            <span className="text-amber-300">Print</span> — its push goes on the track, and its value is staked
            on the candidate you name. The push goes the way history says, whoever you back.
          </li>
        </ul>
        <p>Mark one committed card to reserve. When everyone is in, all cards are revealed:</p>
        <ul className="list-disc space-y-1 pl-5">
          <li>All the pushes are added up. The side the track leans toward wins.</li>
          <li>Stakes on the winner pay back {payout}×. Stakes on the loser are lost.</li>
          <li>
            The biggest stake on the winner makes you <span className="text-amber-300">Patron</span>: +{bonus} on
            every card you cash next round.
          </li>
          <li>Everyone except the new Patron takes their reserved card back.</li>
          <li>Then everyone draws {draw} cards.</li>
          <li>
            Cards are dated. The deck opens on the Revolution, and each round adds the events since the last one.{' '}
            <span className="text-emerald-400">Profit</span> cards are the business of the press: worth a lot, but
            they push nobody.
          </li>
        </ul>
      </div>
    </details>
  );
}
