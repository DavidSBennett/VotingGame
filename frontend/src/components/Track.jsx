/**
 * The one track: States on the left, Nation on the right, the country's
 * current lean as a marker. It decides the election, so it gets the room.
 *
 * `preview` is an optional second marker: where the track would land if
 * the selected card were printed.
 */
export default function Track({ track, race, preview = null }) {
  const { value, min, max } = track;
  const pct = (v) => ((v - min) / (max - min)) * 100;
  const cells = [];
  for (let v = min; v <= max; v++) cells.push(v);

  const leading = race ? race[race.leading] : null;

  return (
    <section className="rounded-lg border border-slate-700 bg-slate-800 p-4">
      <div className="mb-3 flex items-baseline justify-between gap-2">
        <h2 className="text-xs uppercase tracking-widest text-slate-400">The national argument</h2>
        <span className="font-mono text-sm text-slate-300">
          {value > 0 ? `Nation +${value}` : value < 0 ? `States +${-value}` : 'undecided'}
        </span>
      </div>

      <div className="relative">
        <div className="flex gap-0.5">
          {cells.map((v) => (
            <div
              key={v}
              className={
                v === 0
                  ? 'h-6 flex-1 rounded-sm bg-slate-600'
                  : v < 0
                    ? 'h-6 flex-1 rounded-sm bg-rose-950'
                    : 'h-6 flex-1 rounded-sm bg-sky-950'
              }
            />
          ))}
        </div>
        {preview !== null && preview !== value && (
          <div
            className="absolute top-1/2 h-4 w-4 -translate-x-1/2 -translate-y-1/2 rounded-full border-2 border-dashed border-amber-300"
            style={{ left: `${pct(preview) * ((cells.length - 1) / cells.length) + 50 / cells.length}%` }}
          />
        )}
        <div
          className="absolute top-1/2 h-5 w-5 -translate-x-1/2 -translate-y-1/2 rounded-full border-2 border-slate-900 bg-amber-400"
          style={{ left: `${pct(value) * ((cells.length - 1) / cells.length) + 50 / cells.length}%` }}
        />
      </div>

      <div className="mt-2 flex justify-between text-xs">
        <span className="text-rose-300">States</span>
        <span className="text-sky-300">Nation</span>
      </div>

      {leading && (
        <p className="mt-2 text-xs text-slate-400">
          If the vote were now: <span className="text-slate-200">{leading.name}</span>
          {race.decided_by === 'stakes' && ' (track level, so the bigger stake decides)'}
          {race.decided_by === 'history' && ' (track level and stakes even, so history decides)'}
        </p>
      )}
    </section>
  );
}
