import Card from './Card.jsx';

/**
 * The exchange: five stories for sale (the Line-Up), the Editorial pile
 * (always for sale), and what is left in the main deck. Buy buttons light
 * up for exactly the stories the server says you can afford now.
 */
export default function ExchangeRow({ exchange, editorial, mainCount, scandalsLeft, canBuy = [], onBuy, busy, myTurn, news = [], showNew = true }) {
  // "new": released by the election just decided (in 1796 everything is new, so nothing is marked).
  const newKeys = new Set(news.map((c) => c.key));
  const buyButton = (key) =>
    myTurn ? (
      <button type="button" disabled={busy || !canBuy.includes(key)} onClick={() => onBuy(key)} className="btn mt-1 w-full px-1 py-0.5">
        {canBuy.includes(key) ? 'Buy' : '—'}
      </button>
    ) : null;
  return (
    <section className="panel p-3">
      <div className="mb-2 flex flex-wrap items-baseline justify-between gap-2">
        <div className="section-title plain">The exchange</div>
        <span className="font-mono text-[9px] uppercase tracking-[0.15em] text-cream-200/60">
          main deck {mainCount} · Scandals left {scandalsLeft}
        </span>
      </div>
      <div className="flex gap-2 overflow-x-auto pb-1 pt-2">
        {exchange.length === 0 && <p className="font-serif text-sm italic text-cream-200/50">Nothing for sale.</p>}
        {exchange.map((c) => (
          <Card key={c.key} card={c} footer={buyButton(c.key)} tag={showNew && newKeys.has(c.key) ? 'new' : null} />
        ))}
        {editorial && (
          <div className="border-l border-gold-500/30 pl-2">
            <Card
              card={editorial}
              footer={
                <>
                  {buyButton('editorial')}
                  <div className="mt-0.5 text-center font-mono text-[9px] uppercase tracking-[0.15em] text-cream-200/50">{editorial.left} left</div>
                </>
              }
            />
          </div>
        )}
      </div>
    </section>
  );
}
