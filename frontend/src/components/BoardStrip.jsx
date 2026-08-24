/**
 * The fourteen presidential spaces, and how each one actually went.
 *
 * The point of the strip is the comparison: a space shows who took the
 * office in THIS game, and marks the ones that came out differently from
 * history. That divergence is the whole fantasy — the premise is that
 * these outcomes were contingent, and this is where you see that you
 * changed one.
 */
export default function BoardStrip({ space, totalSpaces, history }) {
  const bySpace = {};
  (history || []).forEach((h) => {
    bySpace[h.space] = h;
  });

  const cells = [];
  for (let i = 1; i <= totalSpaces; i++) cells.push(i);

  return (
    <section className="rounded-lg border border-slate-700 bg-slate-800 p-4">
      <h2 className="mb-3 text-xs uppercase tracking-widest text-slate-400">The board</h2>
      <ol className="flex flex-wrap gap-1">
        {cells.map((n) => {
          const h = bySpace[n];
          const current = n === space;
          let cls =
            'flex min-w-[3.2rem] flex-col items-center rounded border px-1.5 py-1 text-center';
          if (current) cls += ' border-amber-500 bg-slate-900';
          else if (h) cls += ' border-slate-600 bg-slate-900';
          else cls += ' border-slate-700 bg-slate-800';

          return (
            <li key={n} className={cls} title={h ? h.winner_name : undefined}>
              <span className="font-mono text-xs text-slate-500">
                {h ? h.year : n}
              </span>
              {h ? (
                <>
                  <span className="max-w-[4.5rem] truncate text-xs text-slate-200">
                    {h.winner_name.split(' ').slice(-1)[0]}
                  </span>
                  {h.matched_history ? (
                    <span className="text-xs text-slate-600">as history</span>
                  ) : (
                    <span className="text-xs text-amber-400">changed</span>
                  )}
                </>
              ) : (
                <span className="text-xs text-slate-600">
                  {current ? 'now' : '—'}
                </span>
              )}
            </li>
          );
        })}
      </ol>
    </section>
  );
}
