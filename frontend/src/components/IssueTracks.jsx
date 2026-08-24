/**
 * The three issue tracks — where the country currently sits.
 *
 * These decide elections, so they are the most important thing on the
 * screen and get the most room. A track that has transitioned is marked,
 * because the fact that the country stopped arguing about tariffs and
 * started arguing about slavery is the single biggest event in a game.
 */
export default function IssueTracks({ tracks, min = -5, max = 5 }) {
  return (
    <section className="rounded-lg border border-slate-700 bg-slate-800 p-4">
      <h2 className="mb-3 text-xs uppercase tracking-widest text-slate-400">
        The national argument
      </h2>
      <div className="space-y-4">
        {tracks.map((t) => {
          // Percentage across the bar. Inline style, not an interpolated
          // Tailwind class — those are absent from the built CSS.
          const pct = ((t.value - min) / (max - min)) * 100;
          return (
            <div key={t.axis}>
              <div className="mb-1 flex items-baseline justify-between gap-2">
                <span className="text-sm font-medium text-slate-100">
                  {t.name}
                  {t.transitioned && (
                    <span className="ml-2 rounded bg-amber-900 px-1.5 py-0.5 text-xs font-normal text-amber-300">
                      new question
                    </span>
                  )}
                </span>
                <span className="font-mono text-sm text-slate-300">
                  {t.value > 0 ? `+${t.value}` : t.value}
                </span>
              </div>

              <div className="relative h-2 rounded bg-slate-900">
                {/* centre line: the country has not decided */}
                <div className="absolute left-1/2 top-0 h-2 w-px bg-slate-600" />
                <div
                  className="absolute top-1/2 h-3 w-3 -translate-x-1/2 -translate-y-1/2 rounded-full border border-slate-900 bg-amber-400"
                  style={{ left: `${pct}%` }}
                />
              </div>

              <div className="mt-1 flex justify-between text-xs text-slate-500">
                <span>{t.low}</span>
                <span>{t.high}</span>
              </div>
            </div>
          );
        })}
      </div>
    </section>
  );
}
