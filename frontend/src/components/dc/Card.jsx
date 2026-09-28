/**
 * A story card in the DC-style game, drawn as a clipping: cost at the left
 * of its band, prestige at the right, the headline, then what it does as
 * short chips. The full card text and the flavour line are its tooltip.
 *
 * Presentation only: every number comes from the server's card view.
 * Literal class strings only (Tailwind cannot see interpolated names).
 */

/** Each theme's ink, as literal class strings. */
export const THEME = {
  Political: { chip: 'bg-federal-700 text-cream-50', text: 'text-federal-300', ring: 'ring-federal-500', band: 'bg-federal-700' },
  Economic: { chip: 'bg-gold-500 text-ink-950', text: 'text-gold-300', ring: 'ring-gold-500', band: 'bg-gold-500' },
  Social: { chip: 'bg-emerald-700 text-cream-50', text: 'text-emerald-300', ring: 'ring-emerald-500', band: 'bg-emerald-700' },
};

/** The band colour and label for a card's type. */
function band(card) {
  switch (card.type) {
    case 'Political story':
      return ['bg-federal-700 text-cream-50', 'Political'];
    case 'Economic story':
      return ['bg-gold-500 text-ink-950', 'Economic'];
    case 'Social story':
      return ['bg-emerald-700 text-cream-50', 'Social'];
    case 'Negative story':
      return ['bg-oxblood-700 text-cream-50', 'Negative'];
    case 'Media event':
      return ['bg-wood-700 text-cream-100', 'Media event'];
    case 'Editorial':
      return ['bg-ink-700 text-cream-100', 'Editorial'];
    case 'Scandal':
      return ['bg-oxblood-900 text-oxblood-300', 'Scandal'];
    case 'Election':
      return ['bg-gold-300 text-ink-950', 'Office · ' + (card.theme || '')];
    default:
      return ['bg-cream-300 text-ink-950', 'Starter'];
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
  const plain = 'bg-ink-950/80 text-cream-50';
  const note = 'border border-ink-950/30 text-ink-950/80';
  if (card.type === 'Media event') {
    const own = [];
    if (card.ongoing_gen) own.push(`+${card.ongoing_gen}`);
    if (card.ongoing_draw) own.push(`draw ${card.ongoing_draw}`);
    out.push([`each turn: ${own.join(', ')}`, plain]);
    out.push([`others: +${card.others_bonus}${card.others_theme ? ' ' + card.others_theme : ''}`, note]);
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
  if (card.trash) out.push(['destroy a card', note]);
  if (card.gain_upto) out.push([`gain ≤${card.gain_upto}`, note]);
  if (card.attack === 'scandal') out.push(['Attack: Scandal', 'bg-oxblood-700 text-cream-50']);
  if (card.attack === 'discard') out.push(['Attack: discard', 'bg-oxblood-700 text-cream-50']);
  if (card.defense) out.push(['Defense', 'bg-emerald-900 text-emerald-300']);
  return out;
}

export default function Card({ card, size = 'md', onClick, footer = null, lifted = false, dim = false, title, tag = null }) {
  const [bandCls, bandLabel] = band(card);
  const md = size === 'md';
  const shell = lifted
    ? 'parchment relative flex flex-col shadow-lift ring-2 ring-gold-300 -translate-y-1 transition'
    : dim
      ? 'parchment relative flex flex-col opacity-50 shadow-card transition'
      : 'parchment relative flex flex-col shadow-card transition hover:-translate-y-0.5 hover:shadow-lift';
  const tip = title || [card.card_text, card.flavor].filter(Boolean).join('\n\n');
  return (
    <div className="flex shrink-0 flex-col">
      <div
        onClick={onClick}
        title={tip}
        className={md ? `${shell} h-44 w-36` : `${shell} h-28 w-28`}
        style={{ cursor: onClick ? 'pointer' : 'default' }}
      >
        {tag && (
          <span className="absolute -top-2 right-1 z-10 bg-oxblood-500 px-1 font-mono text-[8px] uppercase tracking-[0.15em] text-cream-50">{tag}</span>
        )}
        <div className={`flex items-center justify-between px-1.5 py-0.5 font-mono text-[9px] uppercase tracking-[0.12em] ${bandCls}`}>
          <span className="font-bold">{card.cost ? `${card.cost}` : '·'}</span>
          <span className="truncate px-1">{bandLabel}</span>
          <span>{card.vp > 0 ? `★${card.vp}` : card.vp < 0 ? `★${card.vp}` : '☆0'}</span>
        </div>
        <div className="min-h-0 flex-1 overflow-hidden px-1.5 pt-1">
          <div className={md ? 'font-display text-[15px] font-bold leading-[1.05] text-ink-950' : 'font-display text-[12px] font-bold leading-[1.05] text-ink-950'}>
            {card.name}
          </div>
          {card.year && md ? <div className="font-mono text-[8px] uppercase tracking-[0.15em] text-ink-950/50">{card.year}</div> : null}
          {md && card.flavor ? <p className="mt-1 line-clamp-3 font-serif text-[10px] italic leading-snug text-ink-950/60">{card.flavor}</p> : null}
        </div>
        <div className="flex flex-wrap gap-0.5 px-1 pb-1">
          {effects(card).map(([text, cls]) => (
            <span key={text} className={`px-1 py-px font-mono text-[8px] uppercase tracking-[0.05em] ${cls}`}>
              {text}
            </span>
          ))}
        </div>
      </div>
      {footer}
    </div>
  );
}
