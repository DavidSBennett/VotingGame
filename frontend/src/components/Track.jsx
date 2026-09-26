/**
 * The track: States on the left, Nation on the right. Every printed card's
 * push is added up at the reveal, and the side it leans toward wins.
 */
export default function Track({ value, min, max }) {
  const cells = [];
  for (let v = min; v <= max; v++) cells.push(v);
  const left = (((value - min) + 0.5) / cells.length) * 100;

  return (
    <div>
      <div className="relative">
        <div className="flex gap-0.5">
          {cells.map((v) => (
            <div
              key={v}
              className={
                v === 0
                  ? 'h-5 flex-1 rounded-sm bg-slate-600'
                  : v < 0
                    ? 'h-5 flex-1 rounded-sm bg-rose-950'
                    : 'h-5 flex-1 rounded-sm bg-sky-950'
              }
            />
          ))}
        </div>
        <div
          className="absolute top-1/2 h-4 w-4 -translate-x-1/2 -translate-y-1/2 rounded-full border-2 border-slate-900 bg-amber-400"
          style={{ left: `${left}%` }}
        />
      </div>
      <div className="mt-1 flex justify-between text-xs">
        <span className="text-rose-300">States</span>
        <span className="font-mono text-slate-300">
          {value > 0 ? `Nation +${value}` : value < 0 ? `States +${-value}` : 'level'}
        </span>
        <span className="text-sky-300">Nation</span>
      </div>
    </div>
  );
}
