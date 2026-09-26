/**
 * The current election: the States candidate against the Nation candidate,
 * and which papers have sealed their commitment. What they committed stays
 * hidden until the reveal.
 */
export default function RacePanel({ race, seats }) {
  if (!race) return null;
  const waiting = seats.filter((p) => !p.conceded && !p.committed);

  const column = (c) => (
    <div key={c.side} className="rounded border border-slate-700 bg-slate-900 p-3">
      <div className="flex items-baseline justify-between gap-2">
        <span className="font-medium text-slate-100">{c.name}</span>
        <span className={c.side === 'nation' ? 'text-xs text-sky-300' : 'text-xs text-rose-300'}>
          {c.side === 'nation' ? 'Nation' : 'States'}
        </span>
      </div>
      <div className="text-xs text-slate-500">{c.party}</div>
      <p className="mt-2 text-xs italic text-slate-500">{c.note}</p>
    </div>
  );

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
      <div className="grid gap-3 sm:grid-cols-2">
        {column(race.states)}
        {column(race.nation)}
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
