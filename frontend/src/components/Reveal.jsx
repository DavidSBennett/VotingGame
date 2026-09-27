import Track from './Track.jsx';

/**
 * Last round, face up: what every paper committed, where the pushes left
 * the track, what the Union paid, who won, who is Patron, who kept a card.
 */
export default function Reveal({ reveal, seats, track }) {
  if (!reveal) return null;
  const name = (n) => {
    const s = seats.find((p) => p.seat === n);
    return s ? (s.is_you ? 'You' : s.player_name) : `seat ${n}`;
  };
  const decided = {
    track: 'the pushes decided it',
    influence: 'the track was level, so the greater influence decided it',
    history: 'track and influence level, so history decided it',
  }[reveal.decided_by];

  return (
    <section
      className={
        reveal.broke
          ? 'rounded-lg border border-red-700 bg-slate-800 p-4'
          : 'rounded-lg border border-slate-700 bg-slate-800 p-4'
      }
    >
      <div className="mb-2 flex flex-wrap items-baseline justify-between gap-2">
        <h2 className="text-xs uppercase tracking-widest text-slate-400">The reveal · {reveal.year}</h2>
        {reveal.broke ? (
          <span className="text-sm font-medium text-red-400">The Union breaks.</span>
        ) : (
          <span className="text-sm text-slate-200">
            <span className={reveal.winner_side === 'nation' ? 'text-sky-300' : 'text-rose-300'}>
              {reveal.winner_name}
            </span>{' '}
            beat {reveal.loser_name}
          </span>
        )}
      </div>
      <Track value={reveal.track} min={track.min} max={track.max} />
      <p className="mt-2 text-xs text-slate-500">
        {reveal.broke
          ? `Hostile coverage cost the Union ${reveal.stability_spent}; it had ${reveal.stability_before} left.`
          : `${decided}. ${reveal.patron_name ? `${reveal.patron_name} is Patron.` : 'Nobody is Patron.'}`}
        {!reveal.broke && reveal.stability_spent > 0 && ` Hostile coverage cost the Union ${reveal.stability_spent}.`}
      </p>

      <ul className="mt-3 space-y-2 text-sm">
        {reveal.seats.map((r) => (
          <li key={r.seat} className="rounded border border-slate-700 bg-slate-900 px-3 py-2">
            <div className="flex flex-wrap items-baseline justify-between gap-2">
              <span className="font-medium text-slate-100">
                {name(r.seat)}
                {reveal.patron_seat === r.seat && <span className="ml-2 text-xs text-amber-400">Patron</span>}
              </span>
              <span className="font-mono text-xs">
                {r.earned > 0 && <span className="text-emerald-400">profit +{r.earned}</span>}
                {r.influence.states > 0 && <span className="ml-2 text-rose-300">influence States {r.influence.states}</span>}
                {r.influence.nation > 0 && <span className="ml-2 text-sky-300">influence Nation {r.influence.nation}</span>}
              </span>
            </div>
            <div className="mt-1 flex flex-wrap gap-x-3 gap-y-0.5 text-xs">
              {r.plays.map((pl) => (
                <span
                  key={pl.card}
                  className={
                    pl.action === 'profit'
                      ? 'text-emerald-300'
                      : pl.action === 'negative'
                        ? 'text-red-300'
                        : pl.push > 0
                          ? 'text-sky-300'
                          : 'text-rose-300'
                  }
                >
                  {pl.name}{' '}
                  <span className="text-slate-500">
                    {pl.action === 'profit'
                      ? `profit ${pl.money}`
                      : `${pl.action} ${pl.push > 0 ? `N+${pl.push}` : `S+${-pl.push}`} for ${pl.side === 'nation' ? 'Nation' : 'States'}${pl.stability ? `, union −${pl.stability}` : ''}`}
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
