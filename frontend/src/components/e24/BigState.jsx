import { PrestigeSeal } from '../dc/Card.jsx';

/**
 * The big state up now (the elections deck: the ten biggest states, one at
 * a time). Each side has its own price and its own card. Its party's
 * currency, Campaign and neutral all count toward calling it (and, for the
 * Washington Globe, either party's). Calling it claims its votes for that
 * side and puts the card in your deck; once a turn.
 *
 * "Your reach" is an advisory sum of the pools the server reported; the
 * Call button appears only when the server lists the side as callable.
 */
export default function BigState({ big, bigTotal, pools, myTurn, canCall = [], onCall, busy, globe, open = null }) {
  if (!big) {
    return (
      <section className="panel px-3 py-2 text-center">
        <p className="font-serif italic text-cream-200/70">Every big state has been called. The race goes on in the main deck.</p>
      </section>
    );
  }
  const reach = (side) => {
    if (!pools) return null;
    const own = side === 'trump' ? pools.rep : pools.dem;
    let r = (own || 0) + (pools.campaign || 0) + (pools.gen || 0);
    if (globe) r += (side === 'trump' ? pools.dem : pools.rep) || 0;
    return r;
  };
  const box = (side) => {
    const s = big[side];
    const trump = side === 'trump';
    const r = myTurn ? reach(side) : null;
    const can = canCall.includes(side);
    const historical = big.winner_2024 === side;
    const frame = can
      ? trump
        ? 'flex flex-1 flex-col border border-oxblood-300 bg-oxblood-900/50 px-3 py-2 shadow-glow'
        : 'flex flex-1 flex-col border border-federal-300 bg-federal-900/60 px-3 py-2 shadow-glow'
      : trump
        ? 'flex flex-1 flex-col border border-oxblood-500/40 bg-ink-900/60 px-3 py-2'
        : 'flex flex-1 flex-col border border-federal-500/40 bg-ink-900/60 px-3 py-2';
    return (
      <div key={side} className={frame}>
        <div className="flex items-baseline justify-between gap-2 font-mono text-[9px] uppercase tracking-[0.18em]">
          <span className={trump ? 'text-oxblood-300' : 'text-federal-300'}>{trump ? 'Republican' : 'Democratic'}</span>
          {historical && <span className="text-cream-200/50">won it in 2024</span>}
        </div>
        <div className="flex items-baseline justify-between gap-2">
          <span className="font-display text-xl font-semibold leading-tight text-cream-50">{trump ? 'Trump' : 'Harris'}</span>
          <span className="shrink-0 font-mono text-[10px] uppercase tracking-[0.12em] text-cream-200/70">
            needs <span className="text-base text-gold-300">{s.threshold}</span>
          </span>
        </div>
        <button
          type="button"
          onClick={open ? () => open([big.trump.card, big.harris.card], trump ? 0 : 1, 'big') : undefined}
          className="mt-1 flex items-start gap-2 border-t border-gold-500/20 pt-1 text-left"
          title="The card you gain"
        >
          <PrestigeSeal vp={s.card.vp} />
          <span className="font-serif text-[13px] leading-snug text-cream-100">{s.card.card_text.replace(/^.*? for (Trump|Harris)\.\s*/, '')}</span>
        </button>
        {myTurn && (
          <div className="mt-1 flex items-center justify-between gap-2">
            <span className={r >= s.threshold ? 'font-mono text-[10px] uppercase tracking-[0.12em] text-gold-300' : 'font-mono text-[10px] uppercase tracking-[0.12em] text-cream-200/50'}>
              your reach {r}
            </span>
            {can && (
              <button type="button" disabled={busy} onClick={() => onCall(side)} className="btn-solid px-3 py-1 text-xs">
                Call it for {trump ? 'Trump' : 'Harris'}
              </button>
            )}
          </div>
        )}
      </div>
    );
  };
  return (
    <section className="panel px-3 py-2 animate-fade">
      <div className="flex flex-wrap items-baseline justify-center gap-x-4 gap-y-0.5 text-center">
        <h2 className="font-display text-2xl font-bold leading-none text-cream-50">
          {big.state} <span className="font-mono text-lg text-gold-300">{big.ev}</span>
        </h2>
        <span className="font-mono text-[9px] uppercase tracking-[0.18em] text-cream-200/60">
          big state {big.index + 1} of {bigTotal} · {big.tier} · 2024: {big.winner_2024 === 'trump' ? 'Trump' : 'Harris'} by {Math.abs(big.margin).toFixed(1)} · prestige {big.vp}
        </span>
      </div>
      <div className="mt-1.5 flex flex-col gap-2 sm:flex-row">
        {box('trump')}
        {box('harris')}
      </div>
    </section>
  );
}
