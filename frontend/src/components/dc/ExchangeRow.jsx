import Card from './Card.jsx';

/**
 * The exchange: five stories for sale (the Line-Up) and the Editorial pile
 * (always for sale), centered. Click a card to open it; the Buy button under
 * it (and in the card) lights up for exactly what the server says you can
 * afford now.
 *
 *   open(cards, index, source)   opens the CardModal on this row
 */
export default function ExchangeRow({ exchange, editorial, mainCount, scandalsLeft, canBuy = [], onBuy, busy, myTurn, news = [], showNew = true, open }) {
  // "new": released by the election just decided (in 1796 everything is new, so nothing is marked).
  const newKeys = new Set(news.map((c) => c.key));
  const row = editorial ? [...exchange, { ...editorial, key: 'editorial' }] : exchange;
  // Buy shows only under what you can afford; the rest keep an empty line so the row stays level.
  const buyButton = (key) =>
    myTurn ? (
      canBuy.includes(key) ? (
        <button type="button" disabled={busy} onClick={() => onBuy(key)} className="btn mt-1 w-28 px-1 py-0.5">
          Buy
        </button>
      ) : (
        <div className="mt-1 h-[22px]" />
      )
    ) : null;
  return (
    <section className="panel px-3 py-2">
      <div className="text-center">
        <div className="section-title">The exchange</div>
        <p className="mt-0.5 font-mono text-[9px] uppercase tracking-[0.15em] text-cream-200/60">
          main deck {mainCount} · Scandals left {scandalsLeft}
          {editorial ? ` · Editorials left ${editorial.left}` : ''}
        </p>
      </div>
      <div className="mt-2 flex flex-wrap justify-center gap-3">
        {row.length === 0 && <p className="font-serif text-sm italic text-cream-200/50">Nothing for sale.</p>}
        {row.map((c, i) => (
          <Card
            key={c.key}
            card={c}
            onOpen={() => open(row, i, 'exchange')}
            footer={buyButton(c.key)}
            tag={showNew && newKeys.has(c.key) ? 'new' : null}
          />
        ))}
      </div>
    </section>
  );
}
