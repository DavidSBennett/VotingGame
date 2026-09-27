/**
 * A card, drawn as a newspaper clipping: dateline, headline, a line of
 * flavour, and its three stats along the foot.
 *
 * `effect` (optional) replaces the stat line with what the card will
 * actually do where it has been placed -- stamped across the foot like a
 * compositor's mark: { mode: 'profit'|'positive'|'negative', push, money,
 * stability }.
 *
 * Defined at module level (never inside another component's render) so
 * React keeps the same element while it is being dragged.
 */
const pushShort = (p) => (p > 0 ? `N${p}` : `S${-p}`);

function PushCell({ sign, push, cost }) {
  if (!push) {
    return <span className="flex-1 py-1 text-center text-ink-950/25">—</span>;
  }
  return (
    <span
      className={
        push > 0
          ? 'flex flex-1 items-center justify-center gap-0.5 py-1 text-federal-700'
          : 'flex flex-1 items-center justify-center gap-0.5 py-1 text-oxblood-700'
      }
      title={`${sign === '+' ? 'Positive' : 'Negative'} coverage: ${push > 0 ? 'Nation' : 'States'} +${Math.abs(push)}${cost ? `, costs the Union ${cost}` : ''}`}
    >
      <span className="text-ink-950/50">{sign}</span>
      {pushShort(push)}
      {cost > 0 && <span className="text-oxblood-500">·{cost}</span>}
    </span>
  );
}

/**
 * What a card offers one side: whichever of its coverage stats pushes that
 * way (at most one does -- positive and negative always point opposite).
 */
function sideCell(card, side) {
  const want = side === 'nation' ? 1 : -1;
  if (card.positive * want > 0) return <PushCell sign="+" push={card.positive} cost={0} />;
  if (card.negative * want > 0) return <PushCell sign="−" push={card.negative} cost={card.stability} />;
  return <PushCell sign="+" push={0} cost={0} />;
}

export default function Clipping({ card, effect = null, size = 'md', lifted = false, dim = false, ...rest }) {
  const md = size === 'md';
  const shell = lifted
    ? 'parchment relative flex flex-col shadow-lift ring-2 ring-gold-300 -translate-y-1 transition'
    : dim
      ? 'parchment relative flex flex-col opacity-60 shadow-card transition'
      : 'parchment relative flex flex-col shadow-card transition hover:-translate-y-0.5 hover:shadow-lift';

  return (
    <div {...rest} className={md ? `${shell} h-40 w-36 shrink-0` : `${shell} w-full`} title={card.flavor}>
      <div className="flex items-baseline justify-between border-b border-ink-950/20 px-2 pb-0.5 pt-1.5 font-mono text-[9px] uppercase tracking-[0.18em] text-ink-950/60">
        <span>{card.year}</span>
        <span>{card.kind === 'profit' ? 'The press' : 'Event'}</span>
      </div>

      <div className={md ? 'min-h-0 flex-1 overflow-hidden px-2 pt-1' : 'px-2 pb-1 pt-1'}>
        <div
          className={
            md
              ? 'font-display text-[15px] font-bold leading-[1.05] text-ink-950'
              : 'font-display text-[15px] font-bold leading-tight text-ink-950'
          }
        >
          {card.name}
        </div>
        {md && (
          <p className="mt-1 line-clamp-3 font-serif text-[10px] italic leading-snug text-ink-950/70">{card.flavor}</p>
        )}
      </div>

      {effect ? (
        <div
          className={
            effect.mode === 'profit'
              ? 'm-1.5 border border-wood-700/60 py-1 text-center font-mono text-[10px] uppercase tracking-[0.18em] text-wood-700'
              : effect.mode === 'negative'
                ? 'm-1.5 border border-oxblood-700/70 py-1 text-center font-mono text-[10px] uppercase tracking-[0.15em] text-oxblood-700'
                : effect.push > 0
                  ? 'm-1.5 border border-federal-700/70 py-1 text-center font-mono text-[10px] uppercase tracking-[0.15em] text-federal-700'
                  : 'm-1.5 border border-oxblood-700/70 py-1 text-center font-mono text-[10px] uppercase tracking-[0.15em] text-oxblood-700'
          }
        >
          {effect.mode === 'profit'
            ? `Sold · $${effect.money}`
            : `${effect.mode === 'negative' ? 'Hostile' : 'Favourable'} · ${effect.push > 0 ? 'Nation' : 'States'} +${Math.abs(effect.push)}${effect.mode === 'negative' ? ` · Union −${effect.stability}` : ''}`}
        </div>
      ) : (
        // The foot is laid out like the table it is played on: what the card
        // does for the States man on the left, its profit in the middle (the
        // counting house), what it does for the Nation man on the right.
        <div className="flex items-stretch divide-x divide-ink-950/15 border-t border-ink-950/20 font-mono text-[11px] font-medium">
          {sideCell(card, 'states')}
          <span className="flex-1 py-1 text-center text-wood-700" title="Profit">
            ${card.profit}
            {card.profit_value !== undefined && card.profit_value !== card.profit && (
              <span className="text-gold-500">→{card.profit_value}</span>
            )}
          </span>
          {sideCell(card, 'nation')}
        </div>
      )}
    </div>
  );
}
