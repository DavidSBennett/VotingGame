import Card from './Card.jsx';

/**
 * A card that asks a question: destroy a card (in hand or discard pile), or
 * gain a story from the exchange. Nothing else can happen until it is
 * answered; "Decline" answers it with nothing.
 */
export default function PromptModal({ pending, act, busy }) {
  if (!pending) return null;
  const trash = pending.type === 'trash';
  return (
    <div className="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-ink-950/80 p-4 backdrop-blur-sm animate-fade">
      <div className="my-8 w-full max-w-3xl border border-gold-700 p-5 text-center shadow-lift surface-paper animate-rise">
        <div className="font-mono text-[10px] uppercase tracking-[0.3em] text-ink-950/60">{pending.card ? pending.card.name : ''}</div>
        <h3 className="mt-1 font-display text-3xl font-bold text-ink-950">
          {trash ? 'Destroy a card?' : 'Gain a story from the exchange'}
        </h3>
        <p className="mt-1 font-serif italic text-ink-950/70">
          {trash
            ? 'It leaves your deck for good. Local Notices and Scandals are the usual choice.'
            : 'It goes into your discard pile, free.'}
        </p>
        <div className="mt-4 flex flex-wrap justify-center gap-3">
          {pending.options.map((c) => (
            <Card
              key={c.key}
              card={c}
              onOpen={busy ? undefined : () => act('choose', { card: c.key })}
              footer={
                <button type="button" className="btn mt-1 w-28 border-ink-950/40 px-1 py-0.5 text-ink-950" disabled={busy} onClick={() => act('choose', { card: c.key })}>
                  {trash ? 'Destroy' : 'Gain'}
                </button>
              }
            />
          ))}
        </div>
        <button type="button" className="btn mt-4 border-ink-950/40 text-ink-950" disabled={busy} onClick={() => act('choose', { card: null })}>
          Decline
        </button>
      </div>
    </div>
  );
}
