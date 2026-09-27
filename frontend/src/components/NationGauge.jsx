/**
 * The temper of the nation: a tug-of-war between states' rights (left, over
 * the States candidate) and federal power (right, over the Nation
 * candidate), laid out to line up with the table beneath it.
 *
 *   gold lozenge     where the last election's pushes landed
 *   faint marks      every earlier election, fading with age
 *   dashed lozenge   where YOUR commitment would push, this round, on its own
 *
 * The track starts level every election, so this is the record of where
 * the argument has landed, not a position carried forward.
 */
export default function NationGauge({ history = [], min = -5, max = 5, planned = null }) {
  const span = max - min;
  const pos = (v) => ((Math.max(min, Math.min(max, v)) - min) / span) * 100;
  const last = history.length ? history[history.length - 1] : null;
  const statesWon = history.filter((h) => h.winner_side === 'states').length;
  const nationWon = history.filter((h) => h.winner_side === 'nation').length;
  const lean =
    history.length === 0
      ? null
      : history.reduce((sum, h) => sum + h.track, 0) / history.length;

  const ticks = [];
  for (let v = min; v <= max; v++) ticks.push(v);

  return (
    <section className="panel px-4 pb-3 pt-3 animate-fade">
      <div className="flex items-baseline justify-between gap-3">
        <div className="text-left">
          <div className="label text-oxblood-300">States&rsquo; rights</div>
          <div className="font-display text-2xl font-bold leading-none text-oxblood-300">{statesWon}</div>
        </div>
        <div className="text-center">
          <div className="section-title plain text-[11px]">The temper of the nation</div>
          <div className="mt-0.5 font-serif text-xs italic text-cream-200/60">
            {last
              ? `${last.year}: ${last.track > 0 ? `Federal +${last.track}` : last.track < 0 ? `States +${-last.track}` : 'level'} · average lean ${
                  lean > 0.25 ? `Federal ${lean.toFixed(1)}` : lean < -0.25 ? `States ${(-lean).toFixed(1)}` : 'even'
                }`
              : 'No election decided yet. Every race starts level.'}
          </div>
        </div>
        <div className="text-right">
          <div className="label text-federal-300">Federal power</div>
          <div className="font-display text-2xl font-bold leading-none text-federal-300">{nationWon}</div>
        </div>
      </div>

      <div className="relative mt-4 h-10">
        {/* The rope: oxblood to the left of centre, federal blue to the right. */}
        <div className="absolute inset-x-0 top-1/2 flex h-2.5 -translate-y-1/2">
          <div className="h-full flex-1 bg-gradient-to-r from-oxblood-500 to-oxblood-900" />
          <div className="h-full w-px bg-gold-300" />
          <div className="h-full flex-1 bg-gradient-to-r from-federal-900 to-federal-500" />
        </div>
        {/* Scale marks */}
        {ticks.map((v) => (
          <div
            key={v}
            className={v === 0 ? 'absolute top-0 h-10 w-px bg-gold-300/70' : 'absolute top-[11px] h-[18px] w-px bg-ink-950/60'}
            style={{ left: `${pos(v)}%` }}
          />
        ))}
        {/* Earlier elections, fading with age */}
        {history.slice(0, -1).map((h, i, arr) => (
          <div
            key={h.space}
            className={
              h.winner_side === 'nation'
                ? 'absolute top-1/2 h-2 w-2 -translate-x-1/2 -translate-y-1/2 rotate-45 border border-federal-300 bg-federal-700'
                : 'absolute top-1/2 h-2 w-2 -translate-x-1/2 -translate-y-1/2 rotate-45 border border-oxblood-300 bg-oxblood-700'
            }
            style={{ left: `${pos(h.track)}%`, opacity: 0.25 + (0.6 * (i + 1)) / arr.length }}
            title={`${h.year}: ${h.winner_name}`}
          />
        ))}
        {/* Your planned push */}
        {planned !== null && planned !== undefined && (
          <div
            className="absolute top-1/2 h-5 w-5 -translate-x-1/2 -translate-y-1/2 rotate-45 border-2 border-dashed border-cream-50 transition-all duration-500"
            style={{ left: `${pos(planned)}%` }}
            title="Where your commitment alone would push"
          />
        )}
        {/* The last election */}
        {last && (
          <div
            className="absolute top-1/2 h-6 w-6 -translate-x-1/2 -translate-y-1/2 rotate-45 border-2 border-cream-50 bg-gold-500 shadow-glow transition-all duration-700"
            style={{ left: `${pos(last.track)}%` }}
            title={`${last.year}: ${last.winner_name}`}
          />
        )}
      </div>

      <div className="mt-1 flex justify-between font-mono text-[9px] uppercase tracking-[0.2em] text-cream-200/40">
        <span>States +{-min}</span>
        <span className="flex items-center gap-3">
          {last && (
            <span className="flex items-center gap-1">
              <span className="inline-block h-2 w-2 rotate-45 bg-gold-500" /> last election
            </span>
          )}
          {planned !== null && planned !== undefined && (
            <span className="flex items-center gap-1">
              <span className="inline-block h-2 w-2 rotate-45 border border-dashed border-cream-50" /> your push
            </span>
          )}
        </span>
        <span>Federal +{max}</span>
      </div>
    </section>
  );
}
