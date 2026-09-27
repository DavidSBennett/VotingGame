/**
 * What this campaign added to the deck, run as a line of headlines. Cards
 * enter the game when their events happened: the Louisiana Purchase turns
 * up in 1808, the telegraph in 1844.
 */
export default function News({ news, space }) {
  if (!news || news.length === 0) return null;

  if (space === 1) {
    return (
      <p className="text-center font-serif text-sm italic text-cream-200/70">
        The deck opens on the Revolution and the founding — {news.length} clippings, {news[0].year} to{' '}
        {news[news.length - 1].year}. Later news arrives as it happens.
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
