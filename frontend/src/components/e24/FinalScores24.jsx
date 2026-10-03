/**
 * The final count: who reached 270, and every outlet's stakes revealed --
 * the cards on the winner score their wealth, the rest nothing.
 */
export default function FinalScores24({ state }) {
  const ranked = [...state.players].sort((a, b) => (b.final_score ?? 0) - (a.final_score ?? 0));
  const w = state.race && state.race.winner_side;
  const name = (s) => (s === 'trump' ? 'Trump' : 'Harris');
  return (
    <section className="parchment border border-gold-500 p-6 text-center shadow-lift animate-rise">
      <div className="font-mono text-[10px] uppercase tracking-[0.35em] text-ink-950/60">The final count</div>
      <h2 className="mt-2 font-display text-5xl font-bold text-ink-950">{w ? `${name(w)} Wins` : 'No Result'}</h2>
      <p className="mt-2 font-serif italic text-ink-950/70">
        {state.ended_text || 'The game is over.'} Trump {state.race ? state.race.trump : 0} · Harris {state.race ? state.race.harris : 0}
      </p>
      <div className="mx-auto mt-3 h-px w-1/2 bg-ink-950/30" />
      <ol className="mx-auto mt-4 max-w-xl space-y-3 text-left">
        {ranked.map((p, i) => {
          const b = p.score_breakdown || {};
          return (
            <li key={p.seat} className="border-b border-ink-950/15 pb-2">
              <div className="flex items-baseline justify-between">
                <span className="font-display text-xl text-ink-950">
                  <span className="mr-3 font-mono text-sm text-ink-950/50">{i + 1}.</span>
                  {p.player_name}
                  {p.is_you && <span className="ml-2 font-mono text-[9px] uppercase tracking-[0.2em] text-gold-500">you</span>}
                  {state.winner_seat === p.seat && <span className="ml-2 font-mono text-[9px] uppercase tracking-[0.2em] text-gold-500">★ called it best</span>}
                </span>
                <span className="font-mono text-lg text-wood-700">${p.final_score}</span>
              </div>
              <div className="mt-0.5 font-mono text-[10px] uppercase tracking-[0.12em] text-ink-950/60">
                {p.paper ? p.paper.name + ' · ' : ''}claimed {b.ev_claimed ?? p.ev_claimed} EV{b.argus ? ` · Argus +${b.argus}` : ''}
              </div>
              {(b.stakes || []).length > 0 ? (
                <ul className="mt-1 flex flex-wrap gap-1">
                  {b.stakes.map((s, j) => (
                    <li
                      key={j}
                      className={
                        s.won
                          ? s.side === 'trump'
                            ? 'bg-oxblood-700 px-1.5 font-mono text-[10px] text-cream-50'
                            : 'bg-federal-700 px-1.5 font-mono text-[10px] text-cream-50'
                          : 'border border-ink-950/30 px-1.5 font-mono text-[10px] text-ink-950/50 line-through'
                      }
                      title={s.won ? 'On the winner: it scores' : 'On the loser: nothing'}
                    >
                      {s.card} ${s.vp} · {name(s.side)}
                    </li>
                  ))}
                </ul>
              ) : (
                <p className="mt-1 font-serif text-[12px] italic text-ink-950/50">Staked nothing.</p>
              )}
            </li>
          );
        })}
      </ol>
    </section>
  );
}
