/**
 * The current election: the States candidate against the Nation candidate,
 * and who has money riding on each.
 *
 * Stakes are public — every paper can see who is backing whom, which is
 * what makes a late counter-stake a decision rather than a guess.
 */
export default function RacePanel({ race, seats, mySeat, payout }) {
  if (!race) return null;

  const seatName = (n) => {
    const s = seats.find((p) => p.seat === n);
    return s ? s.player_name : `seat ${n}`;
  };

  const column = (c) => {
    const leading = race.leading === c.side;
    const mine = c.stakes.find((st) => st.seat === mySeat);
    const top = c.stakes.reduce((m, st) => Math.max(m, st.amount), 0);
    const topCount = c.stakes.filter((st) => st.amount === top).length;
    return (
      <div
        key={c.side}
        className={
          leading
            ? 'rounded border border-amber-600 bg-slate-900 p-3'
            : 'rounded border border-slate-700 bg-slate-900 p-3'
        }
      >
        <div className="flex items-baseline justify-between gap-2">
          <span className="font-medium text-slate-100">{c.name}</span>
          <span className={c.side === 'nation' ? 'text-xs text-sky-300' : 'text-xs text-rose-300'}>
            {c.side === 'nation' ? 'Nation' : 'States'}
          </span>
        </div>
        <div className="text-xs text-slate-500">{c.party}</div>
        {leading && <div className="mt-1 text-xs text-amber-400">leading</div>}

        <div className="mt-2 border-t border-slate-700 pt-2 text-xs">
          {c.stakes.length === 0 ? (
            <span className="text-slate-600">no paper has staked on him</span>
          ) : (
            <ul className="space-y-0.5">
              {c.stakes.map((st) => (
                <li
                  key={st.seat}
                  className={st.seat === mySeat ? 'text-amber-300' : 'text-slate-400'}
                >
                  {seatName(st.seat)} — {st.amount}
                  {st.amount === top && topCount === 1 && (
                    <span className="text-slate-500"> · Patron if he wins</span>
                  )}
                </li>
              ))}
            </ul>
          )}
          {mine && (
            <div className="mt-1 text-slate-500">
              yours pays {Math.floor(mine.amount * payout)} if he wins
            </div>
          )}
        </div>

        <p className="mt-2 text-xs italic text-slate-500">{c.note}</p>
      </div>
    );
  };

  return (
    <section className="rounded-lg border border-slate-700 bg-slate-800 p-4">
      <div className="mb-3 flex items-baseline justify-between">
        <h2 className="text-xs uppercase tracking-widest text-slate-400">
          The election of {race.year}
        </h2>
        <span className="text-xs text-slate-500">
          turn {Math.min(race.turns_taken + 1, race.turns_needed)} of {race.turns_needed}
        </span>
      </div>
      {race.note && <p className="mb-3 text-xs italic text-slate-500">{race.note}</p>}
      <div className="grid gap-3 sm:grid-cols-2">
        {column(race.states)}
        {column(race.nation)}
      </div>
    </section>
  );
}
