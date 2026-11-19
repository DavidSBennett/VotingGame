/**
 * The 2024 game's cards, drawn like the print-and-play edition
 * (tools/build_print_*_2024.py): a rounded cream card with a dark gilt
 * border, the cost in a round ink disc at the top left, the wealth ($) at
 * the top right, the card's photograph, and tinted party panels -- oxblood
 * for the Republican side, federal blue for the Democratic one.
 *
 *   Tile24   the thumbnail inside the Card button (hand, exchange, prompts)
 *   Face24   the whole card, as printed, for the CardModal
 *
 * The photographs are the print edition's (docs/print/art, copied to
 * public/art24 by scripts/copy-art.mjs before every dev and build run).
 * Literal class strings only.
 */

const BASE = import.meta.env.BASE_URL;

/** True for a card of the 2024 game (its views always carry top_party). */
export function is24(card) {
  return Boolean(card) && Object.prototype.hasOwnProperty.call(card, 'top_party');
}

/** The card's photograph: a state's capitol, or the story's own picture. */
export function artUrl(card) {
  if (card.type === 'State') return `${BASE}art24/state-${card.state}.jpg`;
  return `${BASE}art24/${card.kind || String(card.key).split('#')[0]}.jpg`;
}

export function outletArtUrl(key) {
  return `${BASE}art24/outlet-${key}.jpg`;
}

const PARTY_NAME = { rep: 'Republican', dem: 'Democratic' };
const SET_NAME = { biden: 'Biden set', harris: 'Harris set', switch: 'The switch' };
const other = (p) => (p === 'rep' ? 'dem' : 'rep');

/** A tinted party panel: [panel classes, tag ink, outlet ink]. */
function tint(p) {
  return p === 'rep'
    ? ['border-l-oxblood-700 bg-[#f6e3dc]', 'text-oxblood-700', 'text-oxblood-500']
    : ['border-l-federal-700 bg-[#e1e9f2]', 'text-federal-700', 'text-federal-500'];
}

function eyebrow(card) {
  const set = SET_NAME[card.era];
  if (card.type === 'Plank') return `${PARTY_NAME[card.lean]} plank${set ? ' · ' + set : ''}`;
  if (card.type === 'State') return card.side ? `${card.state_name} · ${card.ev} EV` : `${card.deck} deck · ${card.tier}`;
  if (card.type === 'Starter') return 'Starting card';
  if (card.type === 'Editorial') return 'Always on offer';
  if (card.type === 'Scandal') return 'Scandal';
  const kind = String(card.type).replace(' story', '');
  return set ? `${kind} · ${set}` : kind;
}

/** The frame of a card: its border and ground, by type. */
export function frame24(card) {
  if (card.type === 'Switch') return 'border-gold-500 bg-ink-950 text-cream-50';
  if (card.type === 'Plank') return card.lean === 'rep' ? 'border-oxblood-700 bg-cream-50 text-ink-950' : 'border-federal-700 bg-cream-50 text-ink-950';
  if (card.type === 'Scandal') return 'border-oxblood-900 bg-cream-50 text-ink-950';
  if (card.type === 'State' && card.big) return 'border-[#3b2a0c] bg-cream-50 text-ink-950';
  return 'border-gold-700 bg-cream-50 text-ink-950';
}

function Photo({ card, className, children = null, position = 'center 22%' }) {
  return (
    <div
      className={className}
      style={{ backgroundImage: `url("${artUrl(card)}")`, backgroundSize: 'cover', backgroundPosition: position, boxShadow: 'inset 0 0 0 1px rgba(12,31,34,.45)' }}
    >
      {children}
    </div>
  );
}

/** A title with a word too long for the tile's width at full size ("Weaponization"). */
const longWord = (name) => String(name || '').split(/\s+/).some((w) => w.length >= 12);

const wealthText = (vp) => `${vp < 0 ? '−' : ''}$${Math.abs(vp)}`;

/** A plank's two standing effects, short. */
function plankLines(card) {
  const mine = [];
  if (card.ongoing_gen) mine.push(`+${card.ongoing_gen} neutral`);
  if (card.ongoing_draw) mine.push(`draw ${card.ongoing_draw}`);
  return [`Each turn: ${mine.join(', ') || 'nothing'}`, `Others: +${card.others_bonus} ${PARTY_NAME[card.lean] || ''}`];
}

// ---------------------------------------------------------------------
// The thumbnail
// ---------------------------------------------------------------------

function TileHead({ card, xs, light = false }) {
  return (
    <div className="flex items-center gap-1">
      {card.type === 'State' ? (
        <span className={`flex h-5 w-5 flex-none flex-col items-center justify-center rounded-sm leading-none ${light ? 'bg-cream-50 text-ink-950' : 'bg-ink-950 text-cream-50'}`} title="Electoral votes">
          <b className="font-display text-[11px] leading-none">{card.ev}</b>
        </span>
      ) : (
        <span className="flex h-5 w-5 flex-none items-center justify-center rounded-full bg-ink-950 font-display text-[12px] font-bold leading-none text-cream-50" title="Cost">
          {card.cost}
        </span>
      )}
      <span className="min-w-0 flex-1 text-center">
        {!xs && <span className={`block truncate font-mono text-[5.5px] uppercase tracking-[0.1em] ${light ? 'text-cream-100' : 'text-[#5b5446]'}`}>{eyebrow(card)}</span>}
        <span
          className={`block hyphens-auto break-words font-display font-bold leading-[1.02] ${xs ? 'line-clamp-2 text-[9px]' : longWord(card.name) ? 'line-clamp-2 text-[9px]' : 'line-clamp-2 text-[11.5px]'} ${light ? 'text-white' : ''}`}
          style={light ? { textShadow: '0 0 4px rgba(0,0,0,.85)' } : undefined}
        >
          {card.name}
        </span>
      </span>
      <span
        className={`flex-none font-display text-[11px] font-bold leading-none ${light ? 'text-[#f1d48a]' : card.type === 'Switch' ? 'text-gold-300' : 'text-gold-700'}`}
        style={light ? { textShadow: '0 0 4px rgba(0,0,0,.85)' } : undefined}
        title="Wealth: what it scores if staked on the winner"
      >
        {wealthText(card.vp)}
      </span>
    </div>
  );
}

function TileSide({ party, label, text, grow = 'flex-1' }) {
  const [panel, tag] = tint(party);
  return (
    <div className={`flex min-h-0 ${grow} flex-col overflow-hidden rounded-sm border-l-[3px] px-1 py-0.5 text-left ${panel}`}>
      <span className={`font-mono text-[5px] uppercase tracking-[0.12em] ${tag}`}>{label}</span>
      <span className="line-clamp-3 font-serif text-[7.5px] leading-[1.15] text-ink-950">{text}</span>
    </div>
  );
}

/** The inside of the Card button for a 2024 card. `xs`: the small, already-used size. */
export function Tile24({ card, xs = false }) {
  if (xs) {
    const strip =
      card.type === 'Switch' ? 'bg-gold-500' : card.lean === 'rep' ? 'bg-oxblood-700' : card.lean === 'dem' ? 'bg-federal-700' : 'bg-gold-700';
    return (
      <div className="flex h-full w-full flex-col gap-0.5 p-0.5">
        <TileHead card={card} xs />
        <Photo card={card} className="min-h-0 flex-1 rounded-sm" />
        <div className={`h-1 flex-none rounded-sm ${strip}`} />
      </div>
    );
  }
  if (card.type === 'State') {
    return (
      <div className="flex h-full w-full flex-col gap-0.5 p-1">
        <div
          className="flex min-h-[34px] flex-none flex-col justify-end rounded-sm p-0.5"
          style={{
            backgroundImage: `linear-gradient(rgba(12,31,34,.25),rgba(12,31,34,.78)),url("${artUrl(card)}")`,
            backgroundSize: 'cover',
            backgroundPosition: 'center 35%',
          }}
        >
          <TileHead card={card} light />
        </div>
        {card.side ? (
          <TileSide party={card.lean} label={`For ${card.side === 'trump' ? 'Trump' : 'Harris'}`} text={card.sides[card.side].text} />
        ) : (
          <>
            <TileSide party="rep" label={`For Trump · ${card.sides.trump.cost}`} text={card.sides.trump.text} />
            <TileSide party="dem" label={`For Harris · ${card.sides.harris.cost}`} text={card.sides.harris.text} />
          </>
        )}
      </div>
    );
  }
  if (card.type === 'Switch') {
    return (
      <div className="flex h-full w-full flex-col gap-1 p-1 text-center">
        <span className="font-mono text-[5.5px] uppercase tracking-[0.12em] text-gold-500">Between the two sets</span>
        <span className="font-display text-[15px] font-bold leading-none">{card.name}</span>
        <Photo card={card} className="h-14 flex-none rounded-sm" />
        <span className="h-px bg-gold-500" />
        <span className="line-clamp-5 font-serif text-[7.5px] leading-[1.2]">{card.top_text}</span>
      </div>
    );
  }
  if (card.type === 'Plank') {
    const [mine, others] = plankLines(card);
    return (
      <div className="flex h-full w-full flex-col gap-0.5 p-1">
        <TileHead card={card} />
        <Photo card={card} className="h-12 flex-none rounded-sm" />
        <TileSide party={card.lean} label="From the platform · stays in play" text={`${mine}. ${others}.`} />
      </div>
    );
  }
  if (card.lean && card.bottom_text) {
    return (
      <div className="flex h-full w-full flex-col p-1">
        <TileHead card={card} />
        <span className="my-0.5 block truncate border-y border-gold-500 text-center font-serif text-[6.5px] leading-[1.5]">In hand: {card.card_text}</span>
        <TileSide party={card.lean} label={`${PARTY_NAME[card.lean]} side`} text={card.top_text} />
        <Photo card={card} className="my-0.5 flex h-8 flex-none items-end rounded-sm">
          <span className="mb-0.5 ml-0.5 rounded-full border border-gold-700 bg-cream-50 px-1.5 font-display text-[8px] italic leading-none text-gold-700">or</span>
        </Photo>
        <TileSide party={other(card.lean)} label={`${PARTY_NAME[other(card.lean)]} side`} text={card.bottom_text} />
      </div>
    );
  }
  // Letters, Local Notices, Editorials, Scandals.
  const body = card.type === 'Scandal' ? 'bg-[#f6e3dc]' : card.type === 'Editorial' ? 'bg-[#efe6c8]' : 'bg-cream-100';
  return (
    <div className="flex h-full w-full flex-col gap-0.5 p-1">
      <TileHead card={card} />
      <Photo card={card} className="h-14 flex-none rounded-sm" />
      <div className={`flex min-h-0 flex-1 flex-col justify-center rounded-sm px-1 text-center ${body}`}>
        <span className="line-clamp-4 font-display text-[10px] font-semibold leading-[1.1]">{card.card_text}</span>
      </div>
    </div>
  );
}

// ---------------------------------------------------------------------
// The whole card
// ---------------------------------------------------------------------

function FaceHead({ card }) {
  return (
    <div className="flex items-center gap-2">
      <span className="flex h-11 w-11 flex-none items-center justify-center rounded-full bg-ink-950 font-display text-2xl font-bold leading-none text-cream-50" title="Cost">
        {card.cost}
      </span>
      <span className="min-w-0 flex-1 text-center">
        <span className="block font-mono text-[9px] uppercase tracking-[0.12em] text-[#5b5446]">{eyebrow(card)}</span>
        <span className="block font-display text-2xl font-bold leading-[1.02]">{card.name}</span>
      </span>
      <span className="flex-none font-display text-xl font-bold leading-none text-gold-700" title="Wealth: what it scores if staked on the winner">
        {wealthText(card.vp)}
      </span>
    </div>
  );
}

const TAG = 'font-mono text-[9px] uppercase tracking-[0.14em]';
const HEADLINE = 'font-display text-[19px] font-semibold italic leading-[1.1]';
const OUTLET = 'font-mono text-[10px] font-medium uppercase tracking-[0.06em]';
const DOES = 'mt-1.5 border-t border-dotted border-ink-950/35 pt-1 font-serif text-[13px] leading-snug';

function FaceSide({ party, label, title, outlet, text, outletFirst = false }) {
  const [panel, tag, out] = tint(party);
  return (
    <div className={`rounded-md border-l-[6px] px-3 py-2 text-left ${panel}`}>
      <div className="flex items-baseline justify-between gap-2">
        <span className={`${TAG} ${tag}`}>{label}</span>
        {outletFirst && outlet && <span className={`${OUTLET} text-right ${out}`}>{outlet}</span>}
      </div>
      {title && <p className={HEADLINE}>“{title}”</p>}
      {!outletFirst && outlet && <p className={`${OUTLET} text-right ${out}`}>{outlet}</p>}
      {text && <p className={DOES}>{text}</p>}
    </div>
  );
}

function StateFace({ card }) {
  const r = card.reveal || {};
  const outlet = r.outlet ? String(r.outlet).replace(/\s*\(.*\)$/, '') : '';
  const half = (side) => {
    const s = card.sides[side];
    const trump = side === 'trump';
    const [panel, tag] = tint(trump ? 'rep' : 'dem');
    return (
      <div key={side} className={`rounded-md border-l-[6px] px-3 py-2 text-left ${panel}`}>
        <div className="flex items-center justify-between gap-2">
          <span className={`${TAG} ${tag}`}>
            For {trump ? 'Trump' : 'Harris'}
            {s.historical ? ' · won 2024' : ''}
          </span>
          <span
            className={`flex h-8 w-8 flex-none items-center justify-center rounded-full font-display text-lg font-bold leading-none text-cream-50 ${trump ? 'bg-oxblood-700' : 'bg-federal-700'}`}
            title="Cost"
          >
            {s.cost}
          </span>
        </div>
        <p className="font-display text-lg font-bold leading-tight">{card.half_names[side]}</p>
        <p className="font-serif text-[13px] leading-snug">{s.text}</p>
      </div>
    );
  };
  return (
    <>
      <div
        className="flex min-h-[88px] items-end gap-2 rounded-md p-2"
        style={{
          backgroundImage: `linear-gradient(rgba(12,31,34,.25),rgba(12,31,34,.78)),url("${artUrl(card)}")`,
          backgroundSize: 'cover',
          backgroundPosition: 'center 35%',
        }}
      >
        <span className="flex h-12 w-12 flex-none flex-col items-center justify-center rounded-md bg-cream-50 leading-none text-ink-950">
          <b className="font-display text-2xl leading-none">{card.ev}</b>
          <span className="font-mono text-[7px] tracking-[0.1em]">EV</span>
        </span>
        <span className="min-w-0 flex-1 text-center">
          <span className="block font-mono text-[9px] uppercase tracking-[0.12em] text-cream-100">
            {card.deck} deck · {card.tier}
            {card.sides.trump.knock ? ' · swing state' : ''}
          </span>
          <span className="block font-display text-3xl font-bold leading-none text-white" style={{ textShadow: '0 0 6px rgba(0,0,0,.85)' }}>
            {card.state_name}
          </span>
        </span>
        <span className="flex-none font-display text-xl font-bold leading-none text-[#f1d48a]" style={{ textShadow: '0 0 6px rgba(0,0,0,.85)' }} title="Wealth">
          {wealthText(card.vp)}
        </span>
      </div>
      {card.side ? (
        half(card.side)
      ) : (
        <>
          <div className="rounded-md border-l-[6px] border-l-gold-700 bg-cream-100 px-3 py-2 text-left">
            <span className={`${TAG} text-gold-700`}>When revealed{r.date ? ` · ${r.date}` : ''}</span>
            {r.title ? (
              <>
                <p className={HEADLINE}>“{r.title}”</p>
                <p className={`${OUTLET} text-right text-gold-700`}>{outlet}</p>
              </>
            ) : (
              <p className="font-serif italic text-[#5b5446]">The state's own press: article to come.</p>
            )}
            {r.text && <p className={DOES}>{r.text}</p>}
          </div>
          {half('trump')}
          {half('harris')}
        </>
      )}
      <p className="rounded border border-gold-500 px-2 py-0.5 text-center font-serif text-[12px] font-medium">
        {card.house_seats > 0
          ? `House: ${card.house_seats} seat${card.house_seats === 1 ? '' : 's'} · +${card.house_cost} with the state (269-269 tiebreak)`
          : 'No House seats'}
      </p>
      {card.flavor && <p className="font-serif text-[11px] leading-snug text-[#5b5446]">{card.flavor}</p>}
    </>
  );
}

/** The whole card, laid out as it is printed. */
export function Face24({ card }) {
  let body;
  if (card.type === 'State') {
    body = <StateFace card={card} />;
  } else if (card.type === 'Switch') {
    body = (
      <div className="flex flex-col gap-2 text-center">
        <span className="font-mono text-[10px] uppercase tracking-[0.14em] text-gold-500">Between the two sets</span>
        <span className="font-display text-4xl font-bold leading-none">{card.name}</span>
        <Photo card={card} className="h-40 rounded-md" />
        <span className="mx-10 h-px bg-gold-500" />
        <span className="font-mono text-[11px] text-gold-500">{card.date}</span>
        <span className="font-serif text-[14px] italic">{card.flavor}</span>
        <span className="mx-10 h-px bg-gold-500" />
        <span className="font-serif text-[14px]">{card.top_text}</span>
        <div className="mt-1 flex gap-2 text-left">
          {[
            [card.top_title, card.top_outlet],
            [card.bottom_title, card.bottom_outlet],
          ].map(([title, outlet]) =>
            title ? (
              <div key={title} className="flex-1 rounded bg-white/5 p-2">
                <p className="font-display text-[14px] font-semibold italic leading-tight">“{title}”</p>
                <p className={`${OUTLET} mt-1 text-right text-gold-500`}>{outlet}</p>
              </div>
            ) : null,
          )}
        </div>
      </div>
    );
  } else if (card.type === 'Plank') {
    body = (
      <>
        <FaceHead card={card} />
        <Photo card={card} className="h-40 rounded-md" />
        <FaceSide party={card.lean} label="From the platform" title={card.top_title} outlet={card.top_outlet} text={card.top_text} />
        {card.flavor && <p className="font-serif text-[13px] italic leading-snug text-[#5b5446]">{card.flavor}</p>}
        <p className="font-serif text-[11px] text-[#5b5446]">{card.top_date ? `${card.top_date} · ` : ''}Plank · stays in play</p>
      </>
    );
  } else if (card.lean && card.bottom_text) {
    body = (
      <>
        <FaceHead card={card} />
        <p className="border-y border-gold-500 py-0.5 text-center font-serif text-[13px]">In hand: {card.card_text}</p>
        <FaceSide party={card.lean} label={`${PARTY_NAME[card.lean]} side`} title={card.top_title} outlet={card.top_outlet} text={card.top_text} />
        <Photo card={card} className="flex h-36 items-end rounded-md">
          <span className="mb-1.5 ml-1.5 rounded-full border border-gold-700 bg-cream-50 px-3 py-0.5 font-display text-[15px] italic leading-none text-gold-700">or</span>
        </Photo>
        <FaceSide
          party={other(card.lean)}
          label={`${PARTY_NAME[other(card.lean)]} side`}
          title={card.bottom_title}
          outlet={card.bottom_outlet}
          text={card.bottom_text}
          outletFirst
        />
        <p className="font-serif text-[11px] leading-snug text-[#5b5446]">
          {card.date ? `${card.date} · ` : ''}
          {card.flavor}
        </p>
      </>
    );
  } else {
    const ground = card.type === 'Scandal' ? 'bg-[#f6e3dc]' : card.type === 'Editorial' ? 'bg-[#efe6c8]' : 'bg-cream-100';
    body = (
      <>
        <FaceHead card={card} />
        <Photo card={card} className="h-48 rounded-md" />
        <div className={`rounded-md px-4 py-5 text-center ${ground}`}>
          <p className="font-display text-2xl font-semibold leading-tight">{card.card_text}</p>
          {card.flavor && <p className="mt-3 font-serif text-[13px] italic text-[#5b5446]">{card.flavor}</p>}
        </div>
      </>
    );
  }
  return (
    <div className={`flex flex-col gap-2 rounded-2xl border-[7px] p-3 ${frame24(card)}`} style={{ boxShadow: 'inset 0 0 0 1.5px #b8923a, 0 20px 60px rgba(0,0,0,.7)' }}>
      {body}
    </div>
  );
}
