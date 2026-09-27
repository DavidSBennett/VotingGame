/**
 * The current election: who is standing, and which papers have sealed their
 * commitment. The candidates themselves are the drop zones on the
 * commitment board; what each paper committed stays hidden until the reveal.
 */
export default function RacePanel({ race, seats }) {
  if (!race) return null;
  const waiting = seats.filter((p) => !p.conceded && !p.committed);

  return (
    <section className="rounded-lg border border-slate-700 bg-slate-800 p-4">
      <div className="mb-3 flex flex-wrap items-baseline justify-between gap-2">
        <h2 className="text-xs uppercase tracking-widest text-slate-400">The election of {race.year}</h2>
        <span className="text-xs text-slate-500">
          {waiting.length === 0
            ? 'everyone is in'
            : `waiting for ${waiting.map((p) => (p.is_you ? 'you' : p.player_name)).join(', ')}`}
        </span>
      </div>
      {race.note && <p className="mb-3 text-xs italic text-slate-500">{race.note}</p>}
      <div className="grid gap-3 text-xs text-slate-500 sm:grid-cols-2">
        <p>
          <span className="text-rose-300">{race.states.name}</span> — {race.states.note}
        </p>
        <p>
          <span className="text-sky-300">{race.nation.name}</span> — {race.nation.note}
        </p>
      </div>
      <ul className="mt-3 flex flex-wrap gap-2 text-xs">
        {seats.map((p) => (
          <li
            key={p.seat}
            className={
              p.committed
                ? 'rounded border border-emerald-800 bg-emerald-950 px-2 py-0.5 text-emerald-300'
                : 'rounded border border-slate-700 px-2 py-0.5 text-slate-500'
            }
          >
            {p.is_you ? 'you' : p.player_name}: {p.conceded ? 'left' : p.committed ? 'sealed' : 'deciding'}
          </li>
        ))}
      </ul>
    </section>
  );
}
