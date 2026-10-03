import Card from '../dc/Card.jsx';

/**
 * The exchange: five cards from the main deck -- stories and states -- and
 * the Editorial pile. A story has one Buy; a state has two, one per side,
 * each lit only when the server lists it as affordable ('st#pa:trump').
 * Each turn the oldest card slides to the bottom of the main deck.
 *
 *   open(cards, index, source)   opens the CardModal on this row
 */
export default function Exchange24({ exchange, editorial, mainCount, scandalsLeft, canBuy = [], onBuy, busy, myTurn, open }) {
  const row = editorial ? [...exchange, { ...editorial, key: 'editorial' }] : exchange;
  const footer = (c) => {
    if (!myTurn) return null;
    if (c.type === 'State') {
      const t = canBuy.includes(`${c.key}:trump`);
      const h = canBuy.includes(`${c.key}:harris`);
      return (
        <div className="mt-1 flex w-28 gap-1">
          <button type="button" disabled={busy || !t} onClick={() => onBuy(c.key, 'trump')}
            className={t ? 'btn flex-1 border-oxblood-300 px-0.5 py-0.5 text-oxblood-300' : 'btn flex-1 px-0.5 py-0.5 opacity-30'}>
            T ◆{c.sides.trump.cost}
          </button>
          <button type="button" disabled={busy || !h} onClick={() => onBuy(c.key, 'harris')}
            className={h ? 'btn flex-1 border-federal-300 px-0.5 py-0.5 text-federal-300' : 'btn flex-1 px-0.5 py-0.5 opacity-30'}>
            H ◆{c.sides.harris.cost}
          </button>
        </div>
      );
    }
    return canBuy.includes(c.key) ? (
      <button type="button" disabled={busy} onClick={() => onBuy(c.key)} className="btn mt-1 w-28 px-1 py-0.5">
        Buy
      </button>
    ) : (
      <div className="mt-1 h-[22px]" />
    );
  };
  return (
    <section className="panel px-3 py-2">
      <div className="text-center">
        <div className="section-title">The exchange</div>
        <p className="mt-0.5 font-mono text-[9px] uppercase tracking-[0.15em] text-cream-200/60">
          main deck {mainCount} · Scandals {scandalsLeft}
          {editorial ? ` · Editorials ${editorial.left}` : ''}
        </p>
      </div>
      <div className="mt-2 flex flex-wrap justify-center gap-2 lg:grid lg:grid-cols-2 lg:justify-items-center">
        {row.length === 0 && <p className="font-serif text-sm italic text-cream-200/50">Nothing for sale.</p>}
        {row.map((c, i) => (
          <Card key={c.key} card={c} onOpen={() => open(row, i, 'exchange')} footer={footer(c)} />
        ))}
      </div>
    </section>
  );
}
