import Track from './Track.jsx';

/**
 * Last round, face up: what every paper committed, where the pushes left
 * the track, who won, who collected, who is Patron, and who kept a card.
 */
export default function Reveal({ reveal, seats, track }) {
  if (!reveal) return null;
  const name = (n) => {
    const s = seats.find((p) => p.seat === n);
    return s ? (s.is_you ? 'You' : s.player_name) : `seat ${n}`;
  };
  const decided = {
    track: 'the pushes decided it',
    stakes: 'the track was level, so the bigger stake decided it',
    history: 'track and stakes level, so history decided it',
  }[reveal.decided_by];

  return (
    <section className="rounded-lg border border-slate-700 bg-slate-800 p-4">
      <div className="mb-2 flex flex-wrap items-baseline justify-between gap-2">
        <h2 className="text-xs uppercase tracking-widest text-slate-400">The reveal · {reveal.year}</h2>
        <span className="text-sm text-slate-200">
          <span className={reveal.winner_side === 'nation' ? 'text-sky-300' : 'text-rose-300'}>
            {reveal.winner_name}
          </span>{' '}
          beat {reveal.loser_name}
        </span>
      </div>
      <Track value={reveal.track} min={track.min} max={track.max} />
      <p className="mt-2 text-xs text-slate-500">
        {decided}. {reveal.patron_name ? `${reveal.patron_name} is Patron.` : 'Nobody is Patron.'}
      </p>

      <ul className="mt-3 space-y-2 text-sm">
        {reveal.seats.map((r) => (
          <li key={r.seat} className="rounded border border-slate-700 bg-slate-900 px-3 py-2">
            <div className="flex flex-wrap items-baseline justify-between gap-2">
              <span className="font-medium text-slate-100">
                {name(r.seat)}
                {reveal.patron_seat === r.seat && <span className="ml-2 text-xs text-amber-400">Patron</span>}
              </span>
              <span className="font-mono text-xs text-emerald-400">
                {r.cashed > 0 && `cash +${r.cashed}`}
                {r.cashed > 0 && r.paid > 0 && ' · '}
                {r.paid > 0 && `collected +${r.paid}`}
              </span>
            </div>
            <div className="mt-1 flex flex-wrap gap-x-3 gap-y-0.5 text-xs">
              {r.plays.map((pl) => (
                <span
                  key={pl.card}
                  className={
                    pl.action === 'cash'
                      ? 'text-emerald-300'
                      : pl.side === 'nation'
                        ? 'text-sky-300'
                        : 'text-rose-300'
                  }
                >
                  {pl.name}{' '}
                  <span className="text-slate-500">
                    {pl.action === 'cash' ? `cashed ${pl.value}` : `printed, stake ${pl.value}`}
                  </span>
                </span>
              ))}
            </div>
            {r.kept && <div className="mt-1 text-xs text-slate-500">kept {r.kept}</div>}
          </li>
        ))}
      </ul>
    </section>
  );
}
