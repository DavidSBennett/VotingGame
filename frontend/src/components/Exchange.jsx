import Clipping from './Clipping.jsx';
import Collapsible from './Collapsible.jsx';

/**
 * VARIANT -- the newsroom. The exchange (other papers’ stories, clipped and
 * reprinted, as papers really did): stories for sale at what they would
 * bury for. Tap one to buy it this round (sealed with your commitment);
 * after the election, poorest paper first, it goes into your discard pile
 * and comes round when your deck reshuffles. Below it, your own deck.
 *
 * `buys` is the ordered list of exchange keys this commitment names; `cash`
 * is your money plus what the stories you are burying will pay.
 */
export default function Exchange({ exchange = [], buys, setBuys, maxBuys = 1, cash, locked, you }) {
  const toggle = (key) => {
    if (locked) return;
    if (buys.includes(key)) setBuys(buys.filter((k) => k !== key));
    else setBuys(maxBuys === 1 ? [key] : [...buys, key].slice(-maxBuys));
  };
  const deck = (you && you.deck) || [];
  const discard = (you && you.discard) || [];
  const buying = exchange.filter((c) => buys.includes(c.key));
  const cost = buying.reduce((n, c) => n + c.price, 0);

  return (
    <Collapsible
      title="The exchange"
      storageKey="exchange"
      summary={`${exchange.length} for sale${buying.length ? ` · buying ${buying.map((c) => c.name).join(', ')} −$${cost}` : ''} · your deck ${deck.length} · discard ${discard.length}`}
    >
      <p className="mb-1 font-serif text-xs italic text-cream-200/60">
        Buy a story at what it would bury for. It joins your own deck: run it and it comes back; bury it and it is gone.
        Buys are sealed with your commitment and settled after the election, poorest paper first.
      </p>
      {exchange.length === 0 ? (
        <p className="font-serif text-sm italic text-cream-200/50">Nothing on the exchange.</p>
      ) : (
        <div className="flex gap-2 overflow-x-auto pb-1.5 pt-1">
          {exchange.map((c) => {
            const chosen = buys.includes(c.key);
            const dear = c.price > cash && !chosen;
            return (
              <div key={c.key} className="shrink-0">
                <Clipping
                  card={c}
                  size="sm"
                  lifted={chosen}
                  dim={dear || (locked && !chosen)}
                  onClick={() => !dear && toggle(c.key)}
                  style={{ cursor: locked || dear ? 'default' : 'pointer' }}
                />
                <div className="mt-1 flex items-center justify-between font-mono text-[9px] uppercase tracking-[0.15em]">
                  <span className={dear ? 'text-oxblood-300' : 'text-gold-300'}>${c.price}</span>
                  {chosen ? (
                    <span className="text-gold-300">★ Buying</span>
                  ) : dear ? (
                    <span className="text-cream-200/40">can&rsquo;t afford</span>
                  ) : (
                    !locked && <span className="text-cream-200/50">tap to buy</span>
                  )}
                </div>
              </div>
            );
          })}
        </div>
      )}
      <div className="mt-1 space-y-0.5 font-serif text-xs text-cream-200/60">
        <p>
          <span className="label mr-2">Your deck · {deck.length}</span>
          {deck.length ? deck.map((c) => c.name).join(' · ') : <em>empty</em>}
        </p>
        <p>
          <span className="label mr-2">Discard · {discard.length}</span>
          {discard.length ? discard.map((c) => c.name).join(' · ') : <em>empty</em>}
        </p>
      </div>
    </Collapsible>
  );
}
