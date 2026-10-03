import Card from '../dc/Card.jsx';

/**
 * Stake: in place of your whole turn, set one card from your hand aside,
 * face down, on Trump or Harris. The rest of your hand is discarded and you
 * draw five. When a side reaches 270, every card staked on it scores its
 * wealth; cards staked on the other side score nothing. Rivals see that
 * you staked, never which card or on whom.
 */
export default function StakeModal({ hand, act, busy, onClose }) {
  return (
    <div className="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-ink-950/80 p-4 backdrop-blur-sm animate-fade" onClick={onClose}>
      <div className="my-8 w-full max-w-3xl border border-gold-700 p-5 text-center shadow-lift surface-paper animate-rise" onClick={(e) => e.stopPropagation()}>
        <div className="font-mono text-[10px] uppercase tracking-[0.3em] text-ink-950/60">In place of your turn</div>
        <h3 className="mt-1 font-display text-3xl font-bold text-ink-950">Stake a card</h3>
        <p className="mx-auto mt-1 max-w-xl font-serif italic text-ink-950/70">
          Face down, on the candidate you think will reach 270. It scores its wealth if you are right, nothing if you are wrong, and leaves
          your deck either way. The rest of your hand is discarded.
        </p>
        <div className="mt-4 flex flex-wrap justify-center gap-3">
          {hand.map((c) => (
            <Card
              key={c.key}
              card={c}
              footer={
                <div className="mt-1 flex w-28 gap-1">
                  <button type="button" disabled={busy} onClick={() => act('stake', { card: c.key, side: 'trump' })}
                    className="btn flex-1 border-oxblood-700 px-0.5 py-0.5 text-oxblood-700">
                    Trump
                  </button>
                  <button type="button" disabled={busy} onClick={() => act('stake', { card: c.key, side: 'harris' })}
                    className="btn flex-1 border-federal-700 px-0.5 py-0.5 text-federal-700">
                    Harris
                  </button>
                </div>
              }
            />
          ))}
        </div>
        <button type="button" className="btn mt-4 border-ink-950/40 text-ink-950" disabled={busy} onClick={onClose}>
          Not now
        </button>
      </div>
    </div>
  );
}
