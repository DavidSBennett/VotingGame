/**
 * The track: States on the left, Nation on the right, where the pushes
 * left the country. Printed on parchment, like a newspaper's result chart.
 */
export default function Track({ value, min, max }) {
  const cells = [];
  for (let v = min; v <= max; v++) cells.push(v);
  const left = ((value - min + 0.5) / cells.length) * 100;

  return (
    <div>
      <div className="relative">
        <div className="flex gap-[3px]">
          {cells.map((v) => (
            <div
              key={v}
              className={
                // Fill the cells between the centre and the marker.
                v === 0
                  ? 'h-4 flex-1 bg-ink-950/30'
                  : v < 0
                    ? value < 0 && v >= value
                      ? 'h-4 flex-1 bg-oxblood-700/75'
                      : 'h-4 flex-1 bg-oxblood-700/20'
                    : value > 0 && v <= value
                      ? 'h-4 flex-1 bg-federal-700/75'
                      : 'h-4 flex-1 bg-federal-700/20'
              }
            />
          ))}
        </div>
        <div
          className="absolute top-1/2 h-6 w-6 -translate-x-1/2 -translate-y-1/2 rotate-45 border-2 border-cream-50 bg-gold-500 shadow-card transition-all duration-700"
          style={{ left: `${left}%` }}
        />
      </div>
      <div className="mt-1.5 flex justify-between font-mono text-[10px] uppercase tracking-[0.2em]">
        <span className="text-oxblood-700">States</span>
        <span className="text-ink-950/70">
          {value > 0 ? `Nation +${value}` : value < 0 ? `States +${-value}` : 'level'}
        </span>
        <span className="text-federal-700">Nation</span>
      </div>
    </div>
  );
}
