import { useState } from 'react';
import { artUrl } from './Card24.jsx';

/** "KQED (San Francisco)" -> "KQED": the card has no room for the city. */
const shortOutlet = (o) => String(o || '').split(' (')[0];

const DECKS = [
  ['large', 'Large'],
  ['medium', 'Medium'],
  ['small', 'Small'],
];

/**
 * The three state decks, one state face up on each. Buy the face-up state
 * for a side at that side's price (its party currency, Campaign or neutral;
 * for NewsNation either party's); buying it turns up the next, whose reveal
 * hits every outlet. Tick "+ House" to buy the state's House delegation with
 * it (the 269-269 tiebreaker): only then, never later.
 *
 * Each face-up state is drawn like its print-and-play card: the capitol
 * behind the name, the reveal article, then the Trump half and the Harris
 * half with their prices.
 *
 * The buttons light only when the server lists the buy ('st#pa:trump' in
 * buy, and in buy_house with the delegation).
 */
export default function StateDecks24({ decks, lastReveal, myTurn, canBuy = [], canHouse = [], onBuy, busy, open }) {
  const [house, setHouse] = useState({});
  if (!decks) return null;
  const toggle = (key) => setHouse((h) => ({ ...h, [key]: !h[key] }));

  const half = (up, side) => {
    const s = up.sides[side];
    const trump = side === 'trump';
    const id = `${up.key}:${side}`;
    const withHouse = house[up.key] && up.house_cost > 0;
    const can = withHouse ? canHouse.includes(id) : canBuy.includes(id);
    const panel = trump ? 'border-l-oxblood-700 bg-[#f6e3dc]' : 'border-l-federal-700 bg-[#e1e9f2]';
    return (
      <div key={side} className={`flex flex-1 flex-col rounded border-l-4 px-2 py-1 ${panel}`}>
        <div className="flex items-center justify-between gap-1">
          <span className={trump ? 'font-mono text-[8px] uppercase tracking-[0.14em] text-oxblood-700' : 'font-mono text-[8px] uppercase tracking-[0.14em] text-federal-700'}>
            For {trump ? 'Trump' : 'Harris'}
            {s.historical ? ' · won 2024' : ''}
          </span>
          <span
            className={
              trump
                ? 'flex h-6 w-6 flex-none items-center justify-center rounded-full bg-oxblood-700 font-display text-sm font-bold leading-none text-cream-50'
                : 'flex h-6 w-6 flex-none items-center justify-center rounded-full bg-federal-700 font-display text-sm font-bold leading-none text-cream-50'
            }
            title="Cost"
          >
            {s.cost}
          </span>
        </div>
        <div className="font-display text-[15px] font-bold leading-tight">{up.half_names[side]}</div>
        <div className="font-serif text-[11px] leading-snug">{s.text}</div>
        {myTurn && (
          <button
            type="button"
            disabled={busy || !can}
            onClick={() => onBuy(`st#${up.state}`, side, withHouse)}
            className={
              !can
                ? 'mt-1 cursor-not-allowed border border-ink-950/25 px-2 py-0.5 font-mono text-[10px] uppercase tracking-[0.18em] text-ink-950/35'
                : trump
                  ? 'mt-1 bg-oxblood-700 px-2 py-0.5 font-mono text-[10px] uppercase tracking-[0.18em] text-cream-50 shadow-card transition hover:bg-oxblood-500'
                  : 'mt-1 bg-federal-700 px-2 py-0.5 font-mono text-[10px] uppercase tracking-[0.18em] text-cream-50 shadow-card transition hover:bg-federal-500'
            }
          >
            Buy for {s.cost + (withHouse ? up.house_cost : 0)}
          </button>
        )}
      </div>
    );
  };

  return (
    <section className="panel px-3 py-2 animate-fade">
      <div className="text-center">
        <div className="section-title">The states</div>
        <p className="mt-0.5 font-mono text-[9px] uppercase tracking-[0.15em] text-cream-200/60">
          three decks, one state face up on each · buying one turns up the next, and its reveal hits every outlet
        </p>
      </div>
      {lastReveal && (
        <div className="mt-2 border-l-2 border-gold-500 bg-ink-950/50 px-2 py-1">
          <div className="font-mono text-[8px] uppercase tracking-[0.18em] text-gold-400">
            Revealed · {lastReveal.state} · round {lastReveal.round}
          </div>
          {lastReveal.title ? (
            <p className="font-display text-[14px] italic leading-snug text-cream-50">
              “{lastReveal.title}” <span className="font-mono text-[9px] not-italic uppercase tracking-[0.1em] text-gold-400">{lastReveal.outlet}</span>
            </p>
          ) : null}
          <p className="font-serif text-[12px] text-cream-200/80">{lastReveal.effect}</p>
        </div>
      )}
      <div className="mt-2 grid gap-2 md:grid-cols-3">
        {DECKS.map(([d, label]) => {
          const deck = decks[d];
          const up = deck && deck.up;
          return (
            <div key={d} className="flex flex-col">
              <div className="flex items-baseline justify-between px-1 font-mono text-[8px] uppercase tracking-[0.18em] text-cream-200/60">
                <span>{label} deck</span>
                <span>{deck ? deck.left : 0} face down</span>
              </div>
              {!up ? (
                <p className="mt-2 text-center font-serif text-sm italic text-cream-200/50">Every state claimed.</p>
              ) : (
                <div
                  className={
                    d === 'large'
                      ? 'mt-0.5 flex flex-1 flex-col gap-1 rounded-lg border-4 border-[#3b2a0c] bg-cream-50 p-1.5 text-ink-950'
                      : 'mt-0.5 flex flex-1 flex-col gap-1 rounded-lg border-4 border-gold-700 bg-cream-50 p-1.5 text-ink-950'
                  }
                  style={{ boxShadow: 'inset 0 0 0 1px #b8923a, 0 6px 14px rgba(0,0,0,.35)' }}
                >
                  <button
                    type="button"
                    onClick={() => open([up], 0, 'decks')}
                    title="Open the card"
                    className="flex min-h-[52px] items-end gap-1.5 rounded p-1 text-left"
                    style={{
                      backgroundImage: `linear-gradient(rgba(12,31,34,.25),rgba(12,31,34,.78)),url("${artUrl(up)}")`,
                      backgroundSize: 'cover',
                      backgroundPosition: 'center 35%',
                    }}
                  >
                    <span className="flex h-8 w-8 flex-none flex-col items-center justify-center rounded bg-cream-50 leading-none text-ink-950">
                      <b className="font-display text-base leading-none">{up.ev}</b>
                      <span className="font-mono text-[5px] tracking-[0.1em]">EV</span>
                    </span>
                    <span className="min-w-0 flex-1 text-center">
                      <span className="block font-mono text-[7px] uppercase tracking-[0.12em] text-cream-100">
                        {up.tier} · 2024 {up.winner_2024 === 'trump' ? 'Trump' : 'Harris'} by {Math.abs(up.margin).toFixed(1)}
                      </span>
                      <span className="block font-display text-xl font-bold leading-none text-white" style={{ textShadow: '0 0 5px rgba(0,0,0,.85)' }}>
                        {up.state_name}
                      </span>
                    </span>
                    <span className="flex-none font-display text-base font-bold leading-none text-[#f1d48a]" style={{ textShadow: '0 0 5px rgba(0,0,0,.85)' }} title="Wealth">
                      ${up.vp}
                    </span>
                  </button>
                  {up.reveal && up.reveal.title && (
                    <div className="rounded border-l-4 border-l-gold-700 bg-cream-100 px-2 py-1" title={up.reveal.text}>
                      <div className="font-mono text-[7px] uppercase tracking-[0.14em] text-gold-700">When revealed</div>
                      <p className="line-clamp-2 font-display text-[13px] font-semibold italic leading-[1.1]">“{up.reveal.title}”</p>
                      <p className="text-right font-mono text-[8px] uppercase tracking-[0.06em] text-gold-700">{shortOutlet(up.reveal.outlet)}</p>
                    </div>
                  )}
                  <div className="flex flex-1 flex-col gap-1">
                    {half(up, 'trump')}
                    {half(up, 'harris')}
                  </div>
                  {up.house_seats > 0 ? (
                    <label className="flex cursor-pointer items-center justify-center gap-1.5 rounded border border-gold-500 px-1 py-0.5 font-serif text-[11px] font-medium">
                      <input type="checkbox" checked={Boolean(house[up.key])} onChange={() => toggle(up.key)} className="accent-gold-700" />
                      + House: {up.house_seats} seat{up.house_seats === 1 ? '' : 's'} for {up.house_cost} more
                    </label>
                  ) : (
                    <p className="rounded border border-gold-500 px-1 py-0.5 text-center font-serif text-[11px] font-medium text-ink-950/60">No House seats</p>
                  )}
                </div>
              )}
            </div>
          );
        })}
      </div>
    </section>
  );
}
