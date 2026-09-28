/**
 * The seventeen elections, 1796 to 1860: who was elected and by which
 * paper, the one in progress, and those to come. A man history did not
 * elect is marked with a dagger.
 */
export default function ElectionStrip({ elections, history, current, players }) {
  const paperOf = (seat) => {
    const p = players.find((x) => x.seat === seat);
    return p ? (p.is_you ? 'you' : p.player_name.replace(/^The /, '')) : '';
  };
  const decided = Object.fromEntries((history || []).map((h) => [h.index, h]));
  return (
    <div className="flex gap-1 overflow-x-auto pb-1">
      {elections.map((e, i) => {
        const h = decided[i];
        const now = current !== null && current !== undefined && i === current;
        const cls = now
          ? 'min-w-[5.2rem] border border-gold-300 bg-ink-800 px-1.5 py-1 shadow-glow'
          : h
            ? 'min-w-[5.2rem] border border-gold-500/40 bg-ink-900/70 px-1.5 py-1'
            : 'min-w-[5.2rem] border border-cream-200/10 px-1.5 py-1 opacity-60';
        return (
          <div key={e.year} className={cls} title={`${e.year}: ${e.nation} or ${e.states} (history: ${e[e.historical_winner]})`}>
            <div className="font-mono text-[10px] tracking-[0.1em] text-gold-400">
              {e.year} <span className="text-cream-200/40">{e.era}</span>
            </div>
            {h ? (
              <>
                <div className="truncate font-display text-sm leading-tight text-cream-50">
                  {h.winner.split(' ').slice(-1)[0]}
                  {!h.matched_history && <span className="text-oxblood-300" title="History rewritten"> †</span>}
                </div>
                <div className="truncate font-mono text-[8px] uppercase tracking-[0.1em] text-cream-200/50">{paperOf(h.seat)}</div>
              </>
            ) : (
              <div className="truncate font-serif text-[11px] italic text-cream-200/50">{now ? 'in progress' : `★${e.vp}`}</div>
            )}
          </div>
        );
      })}
    </div>
  );
}
