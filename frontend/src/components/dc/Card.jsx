/**
 * A story card, built on The Historians' CardThumbnail
 * (Historians_(Board_Game)/board/frontend/src/components/Card.jsx): a thin
 * w-28 paper tile with a gold-700 border and an inset gilt hairline, the
 * title in display type, and a small lift on hover. Cost at the top left,
 * prestige at the top right, a thin theme stripe, the type and title and
 * the abilities centered.
 *
 * Clicking a card opens it in the CardModal (with Play / Buy / Choose when
 * the server allows them); `onOpen` does that. With `dragKey` the card can
 * also be dragged (native drag and drop) onto the play area. Every number comes from the
 * server's card view. Literal class strings only (Tailwind cannot see
 * interpolated names).
 */

/** Each theme's ink, as literal class strings. */
export const THEME = {
  Political: { chip: 'bg-federal-700 text-cream-50', text: 'text-federal-300', ring: 'ring-federal-500', band: 'bg-federal-700' },
  Economic: { chip: 'bg-gold-500 text-ink-950', text: 'text-gold-300', ring: 'ring-gold-500', band: 'bg-gold-500' },
  Social: { chip: 'bg-emerald-700 text-cream-50', text: 'text-emerald-300', ring: 'ring-emerald-500', band: 'bg-emerald-700' },
};

/** The stripe colour, the eyebrow colour on paper, and the label for a card's type. */
export function typeStyle(card) {
  switch (card.type) {
    case 'Political story':
      return { stripe: 'bg-federal-700', eyebrow: 'text-federal-700', label: 'Political' };
    case 'Economic story':
      return { stripe: 'bg-gold-500', eyebrow: 'text-gold-700', label: 'Economic' };
    case 'Social story':
      return { stripe: 'bg-emerald-700', eyebrow: 'text-emerald-800', label: 'Social' };
    case 'Negative story':
      return { stripe: 'bg-oxblood-700', eyebrow: 'text-oxblood-700', label: 'Negative · ' + (card.theme || '') };
    case 'Media event':
      return { stripe: 'bg-wood-700', eyebrow: 'text-wood-700', label: 'Media event' };
    case 'Editorial':
      return { stripe: 'bg-ink-700', eyebrow: 'text-ink-700', label: 'Editorial' };
    case 'Scandal':
      return { stripe: 'bg-oxblood-900', eyebrow: 'text-oxblood-700', label: 'Scandal' };
    case 'Election':
      return { stripe: 'bg-gold-300', eyebrow: 'text-gold-700', label: 'Office · ' + (card.theme || '') };
    default:
      return { stripe: 'bg-cream-300', eyebrow: 'text-ink-700', label: 'Starter' };
  }
}

function themeChip(theme) {
  if (theme === 'Political') return 'bg-federal-700 text-cream-50';
  if (theme === 'Economic') return 'bg-gold-500 text-ink-950';
  if (theme === 'Social') return 'bg-emerald-700 text-cream-50';
  return 'bg-ink-700 text-cream-100';
}

/** What a card does, as short chips: [text, classes]. */
export function effects(card) {
  const out = [];
  const plain = 'bg-ink-950/85 text-cream-50';
  const note = 'border border-ink-950/30 text-ink-950/80';
  if (card.type === 'Media event') {
    const own = [];
    if (card.ongoing_gen) own.push(`+${card.ongoing_gen}`);
    if (card.ongoing_draw) own.push(`draw ${card.ongoing_draw}`);
    out.push([`each turn ${own.join(', ')}`, plain]);
    out.push([`others +${card.others_bonus}${card.others_theme ? ' ' + card.others_theme : ''}`, note]);
    return out;
  }
  if (card.gen) out.push([`+${card.gen}`, plain]);
  if (card.themed && card.theme) out.push([`+${card.themed} ${card.theme}`, themeChip(card.theme)]);
  if (card.campaign) out.push([`+${card.campaign} Campaign`, 'bg-gold-300 text-ink-950']);
  if (card.draw) out.push([`draw ${card.draw}`, note]);
  if (card.per_same) out.push([`+${card.per_same} per ${card.theme}`, note]);
  if (card.chain) out.push([`chain +${card.chain}`, note]);
  if (card.per_office) out.push([`+${card.per_office} per office`, note]);
  if (card.retract) out.push(['Retraction', note]);
  if (card.trash) out.push(['destroy', note]);
  if (card.gain_upto) out.push([`gain ≤${card.gain_upto}`, note]);
  if (card.attack === 'scandal') out.push(['attack: Scandal', 'bg-oxblood-700 text-cream-50']);
  if (card.attack === 'discard') out.push(['attack: discard', 'bg-oxblood-700 text-cream-50']);
  if (card.defense) out.push(['Defense', 'bg-emerald-900 text-emerald-300']);
  return out;
}

/** The ability chips, centered. `size` 'sm' on the card, 'md' in the modal. */
export function Abilities({ card, size = 'sm' }) {
  const cls = size === 'md' ? 'px-2 py-0.5 font-mono text-[11px] uppercase tracking-wider' : 'px-1 py-px font-mono text-[7px] uppercase tracking-wider';
  return (
    <div className={size === 'md' ? 'flex flex-wrap justify-center gap-1.5' : 'flex flex-wrap justify-center gap-0.5'}>
      {effects(card).map(([text, c]) => (
        <span key={text} className={`${cls} ${c}`}>
          {text}
        </span>
      ))}
    </div>
  );
}

/**
 * The card thumbnail. size 'sm' (w-28, the Historians' hand size) or 'xs'
 * (w-20, for cards already played this turn).
 */
export default function Card({ card, onOpen, footer = null, dim = false, tag = null, size = 'sm', lifted = false, dragKey = null }) {
  const t = typeStyle(card);
  const xs = size === 'xs';
  const tip = [card.name, card.card_text].filter(Boolean).join('\n\n');
  return (
    <div className="flex shrink-0 flex-col items-center">
      <button
        type="button"
        onClick={onOpen}
        title={tip}
        draggable={Boolean(dragKey)}
        onDragStart={
          dragKey
            ? (e) => {
                e.dataTransfer.setData('text/plain', dragKey);
                e.dataTransfer.effectAllowed = 'move';
              }
            : undefined
        }
        style={dragKey ? { cursor: 'grab' } : undefined}
        className={`group relative flex flex-col overflow-hidden border border-gold-700 text-center surface-paper shadow-card transition-all duration-200 ease-desk hover:shadow-card-hover ${
          xs ? 'h-28 w-20' : 'h-44 w-28'
        } ${dim ? 'opacity-50' : 'hover:-translate-y-1'} ${lifted ? '-translate-y-1 ring-2 ring-gold-300' : ''}`}
      >
        <div className="pointer-events-none absolute inset-1 border border-gold-500/20" />
        <div className={`h-1 w-full shrink-0 ${t.stripe}`} />
        <div className="flex items-baseline justify-between px-2 pt-1 font-mono text-[9px] text-ink-700">
          <span title="Cost">{card.cost ? `◆${card.cost}` : '·'}</span>
          <span title="Prestige">{card.vp > 0 ? `★${card.vp}` : card.vp < 0 ? `★${card.vp}` : '☆'}</span>
        </div>
        <div className="flex min-h-0 flex-1 flex-col items-center px-1.5">
          <p className={`font-mono text-[7px] uppercase tracking-[0.18em] ${t.eyebrow}`}>{t.label}</p>
          <h3
            className={
              xs
                ? 'mt-0.5 line-clamp-3 font-display text-[11px] font-bold leading-tight text-ink-900'
                : 'mt-0.5 line-clamp-3 font-display text-sm font-bold leading-tight text-ink-900'
            }
          >
            {card.name}
          </h3>
          {!xs && card.year ? <p className="mt-0.5 font-mono text-[8px] text-ink-700/70">{card.year}</p> : null}
        </div>
        <div className="px-1 pb-1.5">
          <Abilities card={card} />
        </div>
        {tag && (
          <span className="absolute right-1 top-2 bg-oxblood-500 px-1 font-mono text-[7px] uppercase tracking-[0.15em] text-cream-50">{tag}</span>
        )}
      </button>
      {footer}
    </div>
  );
}
