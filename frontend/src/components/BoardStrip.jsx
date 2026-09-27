/**
 * The seventeen elections as a timeline: each past race marked in the ink of
 * the side that won it, with a small notch where the result departed from
 * history; the current race in gold.
 */
export default function BoardStrip({ space, totalSpaces, history, years = [], compact = false }) {
  const bySpace = {};
  (history || []).forEach((h) => {
    bySpace[h.space] = h;
  });
  const cells = [];
  for (let i = 1; i <= totalSpaces; i++) cells.push(i);

  return (
    <div className="overflow-x-auto">
    <div className="relative min-w-[34rem] px-1">
      <div className="absolute left-3 right-3 top-[13px] h-px bg-gold-500/40" />
      <ol className="relative flex justify-between">
        {cells.map((n) => {
          const h = bySpace[n];
          const current = n === space;
          return (
            <li
              key={n}
              className="flex w-8 flex-col items-center"
              title={h ? `${h.year}: ${h.winner_name} beat ${h.loser_name}${h.patron_name ? ` · Patron ${h.patron_name}` : ''}${h.matched_history ? '' : ' · history changed'}` : undefined}
            >
              <span
                className={
                  current
                    ? 'flex h-[26px] w-[26px] items-center justify-center rotate-45 border-2 border-gold-300 bg-ink-950 shadow-glow'
                    : h
                      ? h.winner_side === 'nation'
                        ? 'flex h-[18px] w-[18px] items-center justify-center rotate-45 border border-federal-300 bg-federal-700 mt-1'
                        : 'flex h-[18px] w-[18px] items-center justify-center rotate-45 border border-oxblood-300 bg-oxblood-700 mt-1'
                      : 'mt-1.5 h-[14px] w-[14px] rotate-45 border border-gold-500/40 bg-ink-950'
                }
              >
                {h && !h.matched_history && <span className="h-1.5 w-1.5 -rotate-45 bg-cream-50" />}
              </span>
              <span
                className={
                  current
                    ? 'mt-2 font-mono text-[10px] text-gold-300'
                    : h
                      ? 'mt-2 font-mono text-[10px] text-cream-200/80'
                      : 'mt-2 font-mono text-[10px] text-cream-200/30'
                }
              >
                {h ? h.year : years[n - 1] || n}
              </span>
              {h && !compact && (
                <span className="max-w-[4.5rem] truncate font-display text-[11px] italic text-cream-200/60">
                  {h.winner_name.split(' ').slice(-1)[0]}
                </span>
              )}
            </li>
          );
        })}
      </ol>
    </div>
    </div>
  );
}
