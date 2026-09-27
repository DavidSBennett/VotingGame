import Track from './Track.jsx';

/**
 * "The Returns": last round, face up, printed like a broadsheet. Shown as a
 * sheet over the table after every election (and on request), then
 * dismissed to get on with the next campaign.
 */
const decidedText = {
  track: 'The pushes decided it.',
  influence: 'The track stood level; the greater influence decided it.',
  history: 'Track and influence stood level; history decided it.',
};

function PlayLine({ pl }) {
  const tone =
    pl.action === 'profit'
      ? 'text-wood-700'
      : pl.action === 'negative'
        ? 'text-oxblood-700'
        : pl.push > 0
          ? 'text-federal-700'
          : 'text-oxblood-700';
  return (
    <li className="flex items-baseline justify-between gap-3 border-b border-ink-950/10 py-1">
      <span className="font-display text-[15px] font-semibold leading-tight text-ink-950">{pl.name}</span>
      <span className={`whitespace-nowrap font-mono text-[10px] uppercase tracking-[0.12em] ${tone}`}>
        {pl.action === 'profit'
          ? `sold $${pl.money}`
          : `${pl.action === 'negative' ? 'hostile' : 'favourable'} ${pl.push > 0 ? 'N' : 'S'}+${Math.abs(pl.push)} for ${pl.side === 'nation' ? 'Nation' : 'States'}${pl.stability ? ` · union −${pl.stability}` : ''}`}
      </span>
    </li>
  );
}

export default function Reveal({ reveal, seats, track, onClose }) {
  if (!reveal) return null;
  const name = (n) => {
    const s = seats.find((p) => p.seat === n);
    return s ? (s.is_you ? 'You' : s.player_name) : `Seat ${n}`;
  };
  const blamed = (reveal.blamed || []).map(name);

  return (
    <div
      className="fixed inset-0 z-40 flex items-start justify-center overflow-y-auto bg-ink-950/80 p-4 backdrop-blur-sm animate-fade"
      onClick={onClose}
    >
      <article
        className="parchment relative my-8 w-full max-w-3xl border border-gold-500 p-6 shadow-lift animate-rise sm:p-8"
        onClick={(e) => e.stopPropagation()}
      >
        <header className="text-center">
          <div className="font-mono text-[10px] uppercase tracking-[0.35em] text-ink-950/60">
            Extra · The returns of {reveal.year}
          </div>
          {reveal.broke ? (
            <h2 className="mt-2 font-display text-4xl font-bold leading-none text-oxblood-700 sm:text-5xl">The Union Breaks</h2>
          ) : (
            <h2 className="mt-2 font-display text-4xl font-bold leading-none text-ink-950 sm:text-5xl">
              {reveal.winner_name} <span className="font-medium italic">elected</span>
            </h2>
          )}
          <div className="mx-auto mt-3 h-px w-2/3 bg-ink-950/30" />
          <p className="mt-2 font-serif text-sm italic text-ink-950/75">
            {reveal.broke
              ? reveal.broke_by === 'history'
                ? `${reveal.winner_name || 'An unlooked-for victor'} was never meant to win, and the country could not bear it.`
                : `Hostile coverage cost the Union ${reveal.stability_spent}; it had ${reveal.stability_before} left.`
              : `${reveal.winner_name} defeats ${reveal.loser_name}. ${decidedText[reveal.decided_by] || ''}`}
            {blamed.length > 0 &&
              ` ${blamed.join(' and ')} ${blamed.length === 1 ? 'was' : 'were'} the most exposed, and paid ${reveal.penalty}.`}
          </p>
        </header>

        <div className="mt-5">
          <Track value={reveal.track} min={track.min} max={track.max} />
        </div>

        <div className="mt-4 grid gap-2 font-mono text-[10px] uppercase tracking-[0.15em] text-ink-950/70 sm:grid-cols-3">
          <div className="border border-ink-950/20 px-2 py-1.5 text-center">
            Patron · <span className="text-ink-950">{reveal.patron_seat !== null && reveal.patron_seat !== undefined ? name(reveal.patron_seat) : 'none'}</span>
          </div>
          <div className="border border-ink-950/20 px-2 py-1.5 text-center">
            Hostile coverage · <span className="text-oxblood-700">−{reveal.stability_spent || 0}</span>
          </div>
          <div className="border border-ink-950/20 px-2 py-1.5 text-center">
            History changed ·{' '}
            <span className={reveal.history_shock ? 'text-oxblood-700' : 'text-ink-950'}>
              {reveal.history_shock ? `−${reveal.history_shock}` : 'no'}
            </span>
          </div>
        </div>

        <div className="mt-5 grid gap-5 sm:grid-cols-2">
          {reveal.seats.map((r) => (
            <section key={r.seat} className="border-t-2 border-ink-950/70 pt-2">
              <div className="flex items-baseline justify-between">
                <h3 className="font-display text-xl font-bold text-ink-950">
                  {name(r.seat)}
                  {reveal.patron_seat === r.seat && (
                    <span className="ml-2 font-mono text-[9px] uppercase tracking-[0.2em] text-gold-500">★ Patron</span>
                  )}
                </h3>
                <span className="font-mono text-[11px] text-wood-700">{r.earned > 0 ? `+$${r.earned}` : ''}</span>
              </div>
              {r.plays.length === 0 ? (
                <p className="py-1 font-serif text-sm italic text-ink-950/60">Passed.</p>
              ) : (
                <ul>
                  {r.plays.map((pl) => (
                    <PlayLine key={pl.card} pl={pl} />
                  ))}
                </ul>
              )}
              <div className="mt-1 flex flex-wrap gap-x-3 font-mono text-[9px] uppercase tracking-[0.15em] text-ink-950/60">
                {r.influence.states > 0 && <span className="text-oxblood-700">influence States {r.influence.states}</span>}
                {r.influence.nation > 0 && <span className="text-federal-700">influence Nation {r.influence.nation}</span>}
                {r.kept && <span>kept {r.kept}</span>}
              </div>
            </section>
          ))}
        </div>

        {onClose && (
          <div className="mt-6 text-center">
            <button
              type="button"
              onClick={onClose}
              className="bg-ink-950 px-6 py-2 font-display text-sm font-semibold uppercase tracking-[0.25em] text-cream-100 shadow-card transition hover:bg-ink-800"
            >
              {reveal.broke ? 'See the final count' : 'To the next campaign'}
            </button>
          </div>
        )}
      </article>
    </div>
  );
}
