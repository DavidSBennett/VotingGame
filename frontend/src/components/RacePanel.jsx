/**
 * The current election: two candidates, and who the country is carrying.
 *
 * Alignment is public on purpose — a newspaper can read the mood of the
 * country as well as anyone. Showing the per-track breakdown is what makes
 * the central decision legible: you can see WHICH issue is carrying a
 * candidate, and therefore which one you would need to move.
 */
export default function RacePanel({ race, seats, mySeat }) {
  if (!race) return null;

  const leader = race.candidates.reduce((a, b) => (a.alignment >= b.alignment ? a : b));

  const seatName = (n) => {
    const s = seats.find((p) => p.seat === Number(n));
    return s ? s.player_name : `seat ${n}`;
  };

  return (
    <section className="rounded-lg border border-slate-700 bg-slate-800 p-4">
      <div className="mb-3 flex items-baseline justify-between">
        <h2 className="text-xs uppercase tracking-widest text-slate-400">
          The election of {race.year}
        </h2>
        <span className="text-xs text-slate-500">
          turn {race.turns_taken + 1} of {race.turns_needed}
        </span>
      </div>

      {race.note && <p className="mb-3 text-xs italic text-slate-500">{race.note}</p>}

      <div className="grid gap-3 sm:grid-cols-2">
        {race.candidates.map((c) => {
          const winning = c.key === leader.key && c.alignment !== 0;
          const control = Object.entries(c.control || {});
          const mine = control.find(([s]) => Number(s) === mySeat);
          return (
            <div
              key={c.key}
              className={
                winning
                  ? 'rounded border border-amber-600 bg-slate-900 p-3'
                  : 'rounded border border-slate-700 bg-slate-900 p-3'
              }
            >
              <div className="flex items-baseline justify-between">
                <span className="font-medium text-slate-100">{c.name}</span>
                <span
                  className={
                    c.alignment > 0
                      ? 'font-mono text-sm text-emerald-400'
                      : c.alignment < 0
                        ? 'font-mono text-sm text-red-300'
                        : 'font-mono text-sm text-slate-500'
                  }
                >
                  {c.alignment > 0 ? `+${c.alignment}` : c.alignment}
                </span>
              </div>
              <div className="text-xs text-slate-500">{c.party}</div>
              {winning && (
                <div className="mt-1 text-xs text-amber-400">the country is carrying him</div>
              )}

              {/* Which issue is doing the work. */}
              <ul className="mt-2 space-y-0.5 text-xs text-slate-400">
                {Object.entries(c.stance).map(([axis, s]) => (
                  <li key={axis} className="flex justify-between gap-2">
                    <span className="capitalize">{axis}</span>
                    <span className="font-mono">
                      {s.stance > 0 ? `+${s.stance}` : s.stance} × {s.track} ={' '}
                      <span className={s.points > 0 ? 'text-emerald-400' : s.points < 0 ? 'text-red-300' : ''}>
                        {s.points}
                      </span>
                    </span>
                  </li>
                ))}
              </ul>

              <div className="mt-2 border-t border-slate-700 pt-2 text-xs">
                {control.length === 0 ? (
                  <span className="text-slate-600">no support yet</span>
                ) : (
                  <ul className="space-y-0.5">
                    {control.map(([s, pts]) => (
                      <li
                        key={s}
                        className={
                          Number(s) === mySeat ? 'text-amber-300' : 'text-slate-400'
                        }
                      >
                        {seatName(s)} — {pts} control
                      </li>
                    ))}
                  </ul>
                )}
                {mine && (
                  <div className="mt-1 text-slate-500">
                    yours pays {mine[1]} back if he loses
                  </div>
                )}
              </div>

              <p className="mt-2 text-xs italic text-slate-500">{c.note}</p>
            </div>
          );
        })}
      </div>
    </section>
  );
}
