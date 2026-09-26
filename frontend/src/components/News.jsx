/**
 * What this campaign added to the deck. Cards enter the game when their
 * events happened, so this is the history arriving: the Louisiana Purchase
 * turns up in 1808, the telegraph in 1844.
 */
export default function News({ news, space }) {
  if (!news || news.length === 0) return null;

  if (space === 1) {
    return (
      <div className="rounded border border-slate-700 bg-slate-800 px-3 py-2 text-sm text-slate-400">
        The deck opens on the Revolution and the founding: {news.length} cards, from{' '}
        {news[0].year} to {news[news.length - 1].year}. Later events arrive as they happen.
      </div>
    );
  }

  return (
    <div className="rounded border border-slate-700 bg-slate-800 px-3 py-2 text-sm">
      <span className="text-xs uppercase tracking-widest text-slate-400">News reaches the presses</span>
      <div className="mt-1 flex flex-wrap gap-x-3 gap-y-1">
        {news.map((n) => (
          <span key={n.key} className={n.kind === 'profit' ? 'text-emerald-300' : 'text-slate-200'}>
            {n.name} <span className="font-mono text-xs text-slate-500">{n.year}</span>
          </span>
        ))}
      </div>
    </div>
  );
}
