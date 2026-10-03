/**
 * A story card, built on The Historians' CardThumbnail
 * (Historians_(Board_Game)/board/frontend/src/components/Card.jsx): a thin
 * w-28 paper tile with a gold-700 border and an inset gilt hairline, the
 * title in display type, and a small lift on hover. Cost at the top left,
 * wealth at the top right, a thin theme stripe, the type and title and
 * the abilities centered.
 *
 * Clicking a card opens it in the CardModal (with Play / Buy / Choose when
 * the server allows them); `onOpen` does that. With `dragKey` the card can
 * also be dragged (native drag and drop) onto the play area, and with
 * `onMiddle` a middle-button click sends it there too. Every number comes from the
 * server's card view. Literal class strings only (Tailwind cannot see
 * interpolated names).
 */

/** Each theme's ink, as literal class strings. */
export const THEME = {
  Political: { chip: 'bg-federal-700 text-cream-50', text: 'text-federal-300', ring: 'ring-federal-500', band: 'bg-federal-700' },
  Economic: { chip: 'bg-gold-500 text-ink-950', text: 'text-gold-300', ring: 'ring-gold-500', band: 'bg-gold-500' },
  Social: { chip: 'bg-emerald-700 text-cream-50', text: 'text-emerald-300', ring: 'ring-emerald-500', band: 'bg-emerald-700' },
};

/** The two parties' inks (the 2024 game): Republican oxblood, Democratic federal blue. */
export const PARTY = {
  rep: { chip: 'bg-oxblood-700 text-cream-50', text: 'text-oxblood-300', label: 'Rep', name: 'Republican' },
  dem: { chip: 'bg-federal-700 text-cream-50', text: 'text-federal-300', label: 'Dem', name: 'Democratic' },
};

/** The stripe colour, the eyebrow colour on paper, and the label for a card's type. */
export function typeStyle(card) {
  const s = baseTypeStyle(card);
  // 2024 stories lean to a party: say so on the eyebrow.
  if (card.lean && card.type !== 'State') return { ...s, label: s.label + (card.lean === 'rep' ? ' · Rep' : ' · Dem') };
  return s;
}

function baseTypeStyle(card) {
  switch (card.type) {
    case 'State':
      if (card.side === 'trump') return { stripe: 'bg-oxblood-500', eyebrow: 'text-oxblood-700', label: `${card.abbr} · ${card.ev} EV · Trump` };
      if (card.side === 'harris') return { stripe: 'bg-federal-500', eyebrow: 'text-federal-700', label: `${card.abbr} · ${card.ev} EV · Harris` };
      return { stripe: 'bg-gold-500', eyebrow: 'text-gold-700', label: `${card.abbr} · ${card.ev} electoral votes` };
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
    const others = card.lean ? PARTY[card.lean].label : card.others_theme;
    out.push([`others +${card.others_bonus}${others ? ' ' + others : ''}`, note]);
    return out;
  }
  if (card.type === 'State' && !card.side && card.sides) {
    // A state on the exchange: buy it for either side.
    out.push([`Trump ◆${card.sides.trump.cost}`, PARTY.rep.chip]);
    out.push([`Harris ◆${card.sides.harris.cost}`, PARTY.dem.chip]);
    return out;
  }
  if (card.gen) out.push([`+${card.gen}`, plain]);
  if (card.themed && card.lean) out.push([`+${card.themed} ${PARTY[card.lean].label}`, PARTY[card.lean].chip]);
  else if (card.themed && card.theme) out.push([`+${card.themed} ${card.theme}`, themeChip(card.theme)]);
  if (card.campaign) out.push([`+${card.campaign} Campaign`, 'bg-gold-300 text-ink-950']);
  if (card.per_office && card.lean) out.push([`+${card.per_office} per 3 states`, note]);
  if (card.draw) out.push([`draw ${card.draw}`, note]);
  if (card.per_same) out.push([`+${card.per_same} per ${card.theme || card.story_kind}`, note]);
  if (card.chain) out.push([`chain +${card.chain}`, note]);
  if (card.per_office && !card.lean) out.push([`+${card.per_office} per office`, note]);
  if (card.per_kind) out.push([`+${card.per_kind} per kind`, note]);
  if (card.retract) out.push(['Retraction', note]);
  if (card.trash) out.push([card.trash > 1 ? `destroy ${card.trash}${card.trash_draw ? ', draw each' : ''}` : card.trash_draw ? 'destroy, draw' : 'destroy', note]);
  if (card.gain_upto) out.push([`gain ≤${card.gain_upto}`, note]);
  if (card.recover) out.push([`recover ${card.recover}`, note]);
  if (card.scry) out.push([`look ${card.scry}, keep 1`, note]);
  if (card.attack === 'scandal') out.push(['attack: Scandal', 'bg-oxblood-700 text-cream-50']);
  if (card.attack === 'discard') out.push(['attack: discard', 'bg-oxblood-700 text-cream-50']);
  if (card.defense) out.push(['Defense', 'bg-emerald-900 text-emerald-300']);
  return out;
}

/**
 * The wealth seal: a gilt disc with the card's wealth, the score. Muted
 * at 0; oxblood below 0 (a Scandal). `size` 'sm' on a card, 'lg' in the modal.
 */
export function PrestigeSeal({ vp, size = 'sm', perOffice = 0 }) {
  const shape =
    size === 'lg'
      ? 'flex h-12 w-12 flex-col items-center justify-center rounded-full border-2 shadow-card'
      : 'flex h-7 w-7 flex-col items-center justify-center rounded-full border shadow-card';
  if (perOffice) {
    // Worth prestige per office held (1860): the seal says so.
    return (
      <div className={`${shape} border-gold-600 bg-gold-300 text-ink-950`} title={`Wealth: ${perOffice} for each election card you hold`}>
        <span className={size === 'lg' ? 'text-[10px] leading-none' : 'text-[6px] leading-none'}>$</span>
        <span className={size === 'lg' ? 'font-display text-base font-bold leading-none' : 'font-display text-[9px] font-bold leading-none'}>{perOffice}/office</span>
      </div>
    );
  }
  const tone =
    vp > 0 ? 'border-gold-600 bg-gold-300 text-ink-950' : vp < 0 ? 'border-oxblood-500 bg-oxblood-700 text-cream-50' : 'border-cream-300 bg-cream-200 text-ink-700/60';
  return (
    <div className={`${shape} ${tone}`} title={`Wealth ${vp}: what it scores if staked on the winner`}>
      <span className={size === 'lg' ? 'text-[10px] leading-none' : 'text-[6px] leading-none'}>$</span>
      <span className={size === 'lg' ? 'font-display text-xl font-bold leading-none' : 'font-display text-[13px] font-bold leading-none'}>{vp}</span>
    </div>
  );
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
export default function Card({ card, onOpen, footer = null, dim = false, tag = null, size = 'sm', lifted = false, dragKey = null, onMiddle = null }) {
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
        // Middle button: `onMiddle` (a hand card: play it). The mousedown is
        // swallowed so Windows does not start its auto-scroll; the action
        // fires on auxclick, when the middle button comes back up.
        onMouseDown={
          onMiddle
            ? (e) => {
                if (e.button === 1) e.preventDefault();
              }
            : undefined
        }
        onAuxClick={
          onMiddle
            ? (e) => {
                if (e.button === 1) {
                  e.preventDefault();
                  onMiddle();
                }
              }
            : undefined
        }
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
        {/* Cost at the top left; the wealth seal at the top right. */}
        <div className="flex items-start justify-between px-1.5 pt-1">
          <span
            title="Cost"
            className={card.cost ? 'border border-gold-600/60 px-1 font-mono text-[10px] font-bold text-ink-800' : 'px-1 font-mono text-[10px] text-ink-700/40'}
          >
            {card.cost ? `◆${card.cost}` : '·'}
          </span>
          {!xs && <PrestigeSeal vp={card.vp} perOffice={card.vp_per_office} />}
          {xs && <span className="font-mono text-[9px] font-bold text-ink-800">${card.vp}</span>}
        </div>
        <div className="-mt-2 flex min-h-0 flex-1 flex-col items-center px-1.5">
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
          {!xs && card.candidate ? <p className="mt-0.5 font-serif text-[10px] italic leading-tight text-ink-700">Patron of {card.candidate}</p> : null}
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
