import Card from './Card.jsx';

/**
 * A card that asks a question: destroy a card (in hand or discard pile), or
 * gain a story from the exchange. Nothing else can happen until it is
 * answered; "Decline" answers it with nothing.
 */
export default function PromptModal({ pending, act, busy }) {
  if (!pending) return null;
  const trash = pending.type === 'trash';
  const left = pending.left || 1;
  const HEAD = {
    trash: left > 1 ? `Destroy a card? (up to ${left} more)` : 'Destroy a card?',
    gain: 'Gain a story from the exchange',
    recover: left > 1 ? `Take a card back (up to ${left})` : 'Take a card back',
    scry: 'Keep one of the top stories',
    knock: `Knock out a ${pending.party === 'rep' ? 'Republican' : 'Democratic'} plank`,
  };
  const NOTE = {
    trash: `It leaves your deck for good.${pending.draw_each ? ' You draw a card for each one.' : ''} Local Notices and Scandals are the usual choice.`,
    gain: 'It goes into your discard pile, free.',
    recover: 'From your discard pile into your hand, to play this turn.',
    scry: 'It goes into your hand; the others go to the bottom of the main deck.',
    knock: "A rival's plank in play: it goes to its owner's discard pile.",
  };
  const BUTTON = { trash: 'Destroy', gain: 'Gain', recover: 'Take', scry: 'Keep', knock: 'Knock out' };
  return (
    <div className="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-ink-950/80 p-4 backdrop-blur-sm animate-fade">
      <div className="my-8 w-full max-w-3xl border border-gold-700 p-5 text-center shadow-lift surface-paper animate-rise">
        <div className="font-mono text-[10px] uppercase tracking-[0.3em] text-ink-950/60">{pending.card ? pending.card.name : ''}</div>
        <h3 className="mt-1 font-display text-3xl font-bold text-ink-950">{HEAD[pending.type] || 'Choose'}</h3>
        <p className="mt-1 font-serif italic text-ink-950/70">{NOTE[pending.type] || ''}</p>
        <div className="mt-4 flex flex-wrap justify-center gap-3">
          {pending.options.map((c) => (
            <Card
              key={c.key}
              card={c}
              tag={c.owner ? c.owner.name : null}
              onOpen={busy ? undefined : () => act('choose', { card: c.key })}
              footer={
                <button type="button" className="btn mt-1 w-28 border-ink-950/40 px-1 py-0.5 text-ink-950" disabled={busy} onClick={() => act('choose', { card: c.key })}>
                  {BUTTON[pending.type] || 'Choose'}
                </button>
              }
            />
          ))}
        </div>
        <button type="button" className="btn mt-4 border-ink-950/40 text-ink-950" disabled={busy} onClick={() => act('choose', { card: null })}>
          {left > 1 && pending.type !== 'gain' ? 'Done' : 'Decline'}
        </button>
      </div>
    </div>
  );
}
