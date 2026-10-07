import { useState } from 'react';
import { PrestigeSeal } from '../dc/Card.jsx';

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
    const frame = trump ? 'border-oxblood-500/50 bg-oxblood-900/30' : 'border-federal-500/50 bg-federal-900/30';
    return (
      <div key={side} className={`flex flex-1 flex-col border px-2 py-1 ${frame}`}>
        <div className="flex items-baseline justify-between gap-1 font-mono text-[8px] uppercase tracking-[0.15em]">
          <span className={trump ? 'text-oxblood-300' : 'text-federal-300'}>for {trump ? 'Trump' : 'Harris'}</span>
          {s.historical && <span className="text-cream-200/50">won 2024</span>}
        </div>
        <div className="font-display text-[13px] font-semibold leading-tight text-cream-50">{up.half_names[side]}</div>
        <div className="mt-0.5 font-serif text-[11px] leading-snug text-cream-200/80">{s.text}</div>
        {myTurn && (
          <button
            type="button"
            disabled={busy || !can}
            onClick={() => onBuy(`st#${up.state}`, side, withHouse)}
            className={can ? 'btn-solid mt-1 px-2 py-0.5 text-[11px]' : 'btn mt-1 px-2 py-0.5 text-[11px] opacity-40'}
          >
            Buy ◆{s.cost + (withHouse ? up.house_cost : 0)}
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
            <div key={d} className="flex flex-col border border-gold-500/30 bg-ink-950/30 px-2 py-1.5">
              <div className="flex items-baseline justify-between font-mono text-[8px] uppercase tracking-[0.18em] text-cream-200/60">
                <span>{label} deck</span>
                <span>{deck ? deck.left : 0} face down</span>
              </div>
              {!up ? (
                <p className="mt-2 text-center font-serif text-sm italic text-cream-200/50">Every state claimed.</p>
              ) : (
                <>
                  <button type="button" onClick={() => open([up], 0, 'decks')} className="mt-0.5 flex items-center justify-between gap-2 text-left">
                    <span>
                      <span className="font-display text-lg font-bold leading-none text-cream-50">{up.state_name}</span>
                      <span className="ml-1 font-mono text-sm text-gold-300">{up.ev}</span>
                      <span className="block font-mono text-[8px] uppercase tracking-[0.12em] text-cream-200/60">
                        {up.tier} · 2024 {up.winner_2024 === 'trump' ? 'Trump' : 'Harris'} by {Math.abs(up.margin).toFixed(1)}
                      </span>
                    </span>
                    <PrestigeSeal vp={up.vp} />
                  </button>
                  <div className="mt-1 flex flex-col gap-1">
                    {half(up, 'trump')}
                    {half(up, 'harris')}
                  </div>
                  {up.house_seats > 0 ? (
                    <label className="mt-1 flex cursor-pointer items-center gap-1.5 font-mono text-[9px] uppercase tracking-[0.12em] text-cream-200/70">
                      <input type="checkbox" checked={Boolean(house[up.key])} onChange={() => toggle(up.key)} className="accent-gold-500" />
                      + House: {up.house_seats} seat{up.house_seats === 1 ? '' : 's'} for ◆{up.house_cost}
                    </label>
                  ) : (
                    <p className="mt-1 font-mono text-[9px] uppercase tracking-[0.12em] text-cream-200/40">No House seats</p>
                  )}
                </>
              )}
            </div>
          );
        })}
      </div>
    </section>
  );
}
