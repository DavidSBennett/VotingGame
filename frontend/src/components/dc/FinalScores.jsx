/**
 * The final count: every paper's prestige and where it came from.
 */
const PARTS = [
  ['elections', 'offices'],
  ['stories', 'stories'],
  ['editorials', 'editorials'],
  ['media', 'media'],
  ['argus', 'Argus'],
  ['scandals', 'Scandals'],
];

export default function FinalScores({ state }) {
  const ranked = [...state.players].sort((a, b) => (b.final_score ?? 0) - (a.final_score ?? 0));
  return (
    <section className="parchment border border-gold-500 p-6 text-center shadow-lift animate-rise">
      <div className="font-mono text-[10px] uppercase tracking-[0.35em] text-ink-950/60">The final count</div>
      <h2 className="mt-2 font-display text-5xl font-bold text-ink-950">{state.ended_reason === 'board_completed' ? 'It is 1860' : 'The Presses Stop'}</h2>
      <p className="mt-2 font-serif italic text-ink-950/70">{state.ended_text || 'The game is over.'}</p>
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
                  {state.winner_seat === p.seat && (
                    <span className="ml-2 font-mono text-[9px] uppercase tracking-[0.2em] text-gold-500">★ most honoured</span>
                  )}
                </span>
                <span className="font-mono text-lg text-wood-700">★{p.final_score}</span>
              </div>
              <div className="mt-0.5 font-mono text-[10px] uppercase tracking-[0.12em] text-ink-950/60">
                {p.paper ? p.paper.name + ' · ' : ''}
                {PARTS.filter(([k]) => b[k]).map(([k, label]) => `${label} ${b[k]}`).join(' · ')}
                {b.elections_won !== undefined ? ` · ${b.elections_won} elected` : ''}
              </div>
            </li>
          );
        })}
      </ol>
    </section>
  );
}
