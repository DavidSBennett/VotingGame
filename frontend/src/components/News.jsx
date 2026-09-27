/**
 * What this campaign added to the deck, run as a line of headlines. Cards
 * enter the game when their events happened: the Louisiana Purchase turns
 * up in 1808, the telegraph in 1844.
 */
export default function News({ news, space, compact = false }) {
  if (!news || news.length === 0) return null;

  if (space === 1) {
    return (
      <p className={compact ? 'shrink-0 truncate font-serif text-xs italic text-cream-200/60' : 'text-center font-serif text-sm italic text-cream-200/70'}>
        The deck opens on the Revolution and the founding — {news.length} clippings, {news[0].year} to{' '}
        {news[news.length - 1].year}. Later news arrives as it happens.
      </p>
    );
  }

  if (compact) {
    return (
      <p className="shrink-0 truncate text-xs" title={news.map((n) => `${n.name} (${n.year})`).join(', ')}>
        <span className="label mr-2">News</span>
        {news.map((n, i) => (
          <span key={n.key} className="font-display italic text-cream-100">
            {i > 0 && <span className="text-gold-500"> · </span>}
            {n.name}
          </span>
        ))}
      </p>
    );
  }

  return (
    <div className="text-center">
      <span className="label">News reaches the presses</span>
      <div className="mt-1 flex flex-wrap justify-center gap-x-4 gap-y-0.5">
        {news.map((n) => (
          <span key={n.key} className="font-display text-[15px] italic text-cream-100">
            {n.name} <span className="font-mono text-[10px] not-italic text-gold-500">{n.year}</span>
          </span>
        ))}
      </div>
    </div>
  );
}
