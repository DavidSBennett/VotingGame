import { useEffect, useState } from 'react';
import CornerOrnament from './CornerOrnament.jsx';
import FleuronDivider from './FleuronDivider.jsx';
import { Abilities, PrestigeSeal, typeStyle } from './Card.jsx';

/**
 * CardModal -- the open card, ported from The Historians' CardModal
 * (Historians_(Board_Game)/board/frontend/src/components/Card.jsx): a
 * paper document with gilt corner ornaments and a fleuron divider, a
 * prominent Close, and photo-gallery paging through a row of cards
 * (chevrons outside the document; the arrow keys; Escape closes).
 *
 *   card       the card view from the server
 *   actions    optional buttons (Play, Buy, Choose) -- only what the
 *              server allows; the parent decides
 *   onPrev / onNext / position   paging through the row it came from
 */
/**
 * The card's artwork, if there is one: frontend/public/art/<key>.png, made
 * from the prompt in docs/art-prompts.csv (a story by its kind, a candidate
 * card as <year>-<side>). Nothing is shown until an image exists.
 */
export function artFile(card) {
  if (card.kind === 'election') return `${card.year}-${card.side}.png`;
  return `${card.kind || String(card.key).split('#')[0]}.png`;
}

function CardArt({ card }) {
  const file = artFile(card);
  const [missing, setMissing] = useState(null);
  if (missing === file) return null;
  return (
    <div className="mx-auto mb-4 max-w-sm border border-gold-700/60 p-1 shadow-card">
      <img
        key={file}
        src={`${import.meta.env.BASE_URL}art/${file}`}
        alt=""
        onError={() => setMissing(file)}
        className="block aspect-[4/3] w-full object-cover"
      />
    </div>
  );
}

/**
 * The 2024 game's sides of a card: a story's two framings (its positive top,
 * the outlet at the lower right of the headline; its oppositional bottom, the
 * outlet at the upper right), a plank's platform wording, or a state's reveal
 * and House delegation. Nothing for the other games' cards.
 */
function Framings({ card }) {
  const party = (p) => (p === 'rep' ? 'Republican' : 'Democratic');
  const tone = (p) => (p === 'rep' ? 'border-oxblood-700 bg-oxblood-300/15' : 'border-federal-700 bg-federal-300/15');
  const outlet = (o) => <span className="block text-right font-mono text-[10px] uppercase tracking-[0.12em] text-ink-700">{o}</span>;
  if (card.type === 'State' && card.reveal) {
    const r = card.reveal;
    return (
      <div className="mx-auto mt-4 max-w-md space-y-2 text-left">
        <div className="border-l-4 border-gold-700 bg-cream-200/50 px-3 py-2">
          <div className="font-mono text-[10px] uppercase tracking-[0.18em] text-gold-700">When revealed{r.date ? ` · ${r.date}` : ''}</div>
          {r.title ? (
            <>
              <p className="font-display text-lg italic leading-snug text-ink-900">“{r.title}”</p>
              {outlet(r.outlet)}
            </>
          ) : (
            <p className="font-serif italic text-ink-700">The state's own press: article to come.</p>
          )}
          {r.text && <p className="mt-1 font-serif text-sm text-ink-900">{r.text}</p>}
        </div>
        <p className="text-center font-mono text-[10px] uppercase tracking-[0.15em] text-ink-700">
          {card.house_seats > 0
            ? `House: ${card.house_seats} seat${card.house_seats === 1 ? '' : 's'} · +◆${card.house_cost} with the state (the 269-269 tiebreaker)`
            : 'No House seats'}
        </p>
      </div>
    );
  }
  if (!card.top_title && !card.top_text) return null;
  if (card.type === 'Plank') {
    return (
      <div className={`mx-auto mt-4 max-w-md border-l-4 px-3 py-2 text-left ${tone(card.lean)}`}>
        <div className="font-mono text-[10px] uppercase tracking-[0.18em] text-ink-700">{party(card.lean)} plank · from the platform</div>
        {card.top_title && <p className="font-display text-lg italic leading-snug text-ink-900">“{card.top_title}”</p>}
        {card.top_outlet && outlet(card.top_outlet)}
        {card.top_text && <p className="mt-1 font-serif text-sm text-ink-900">{card.top_text}</p>}
      </div>
    );
  }
  const other = card.lean === 'rep' ? 'dem' : 'rep';
  return (
    <div className="mx-auto mt-4 max-w-md space-y-2 text-left">
      <div className={`border-l-4 px-3 py-2 ${tone(card.lean)}`}>
        <div className="font-mono text-[10px] uppercase tracking-[0.18em] text-ink-700">▲ Top · {party(card.lean)} side</div>
        {card.top_title && <p className="font-display text-lg italic leading-snug text-ink-900">“{card.top_title}”</p>}
        {card.top_outlet && outlet(card.top_outlet)}
        {card.top_text && <p className="mt-1 font-serif text-sm text-ink-900">{card.top_text}</p>}
      </div>
      {card.bottom_text && (
        <div className={`border-l-4 px-3 py-2 ${tone(other)}`}>
          {card.bottom_outlet && outlet(card.bottom_outlet)}
          <div className="font-mono text-[10px] uppercase tracking-[0.18em] text-ink-700">▼ Bottom · {party(other)} side</div>
          {card.bottom_title && <p className="font-display text-lg italic leading-snug text-ink-900">“{card.bottom_title}”</p>}
          <p className="mt-1 font-serif text-sm text-ink-900">{card.bottom_text}</p>
        </div>
      )}
    </div>
  );
}

export default function CardModal({ card, onClose, actions = null, onPrev = null, onNext = null, position = null }) {
  useEffect(() => {
    function handleKey(e) {
      const tag = e.target?.tagName;
      if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') return;
      if (e.key === 'ArrowLeft' && onPrev) {
        e.preventDefault();
        onPrev();
      } else if (e.key === 'ArrowRight' && onNext) {
        e.preventDefault();
        onNext();
      } else if (e.key === 'Escape' && onClose) {
        e.preventDefault();
        onClose();
      }
    }
    window.addEventListener('keydown', handleKey);
    return () => window.removeEventListener('keydown', handleKey);
  }, [onPrev, onNext, onClose]);

  if (!card) return null;
  const t = typeStyle(card);

  const chevron = (label, onClick, side) => (
    <button
      type="button"
      onClick={(e) => {
        e.stopPropagation();
        onClick();
      }}
      className={
        side === 'left'
          ? 'group absolute right-full top-1/2 z-10 mr-2 flex -translate-y-1/2 items-center justify-center px-2 focus:outline-none sm:mr-4'
          : 'group absolute left-full top-1/2 z-10 ml-2 flex -translate-y-1/2 items-center justify-center px-2 focus:outline-none sm:ml-4'
      }
      aria-label={label}
    >
      <span
        className="select-none font-display leading-none text-cream-200 transition-colors group-hover:text-gold-400"
        style={{ fontSize: '7rem', textShadow: '0 2px 12px rgba(0,0,0,0.6)' }}
      >
        {side === 'left' ? '‹' : '›'}
      </span>
    </button>
  );

  return (
    <div className="fixed inset-0 z-[70] flex items-center justify-center bg-ink-950/80 p-6 backdrop-blur-sm animate-fade" onClick={onClose}>
      <div className="relative w-full max-w-xl" onClick={(e) => e.stopPropagation()}>
        {onPrev && chevron('Previous card', onPrev, 'left')}
        {onNext && chevron('Next card', onNext, 'right')}

        <button
          type="button"
          onClick={(e) => {
            e.stopPropagation();
            onClose();
          }}
          className="absolute right-4 top-4 z-30 border border-gold-500 bg-cream-50 px-3 py-1.5 font-mono text-xs uppercase tracking-wider text-ink-900 shadow-md transition-colors hover:border-oxblood-500 hover:bg-oxblood-500 hover:text-cream-50"
          aria-label="Close"
        >
          ✕ Close
        </button>

        <article
          className="relative max-h-[90vh] overflow-y-auto surface-paper animate-rise"
          style={{ boxShadow: '0 20px 60px rgba(0,0,0,0.7), 0 0 0 1px rgba(184, 146, 58, 0.5)' }}
        >
          <div className="pointer-events-none absolute inset-2 border border-gold-500/30" />
          <div className="pointer-events-none absolute left-3 top-3 text-gold-500">
            <CornerOrnament corner="tl" size={24} />
          </div>
          <div className="pointer-events-none absolute right-3 top-3 text-gold-500">
            <CornerOrnament corner="tr" size={24} />
          </div>
          <div className="pointer-events-none absolute bottom-3 left-3 text-gold-500">
            <CornerOrnament corner="bl" size={24} />
          </div>
          <div className="pointer-events-none absolute bottom-3 right-3 text-gold-500">
            <CornerOrnament corner="br" size={24} />
          </div>
          {position && position.total > 1 && (
            <p className="absolute left-5 top-4 z-10 font-mono text-[10px] uppercase tracking-widest text-ink-900">
              {position.current} of {position.total}
            </p>
          )}
          <div className={`absolute inset-x-0 top-0 h-1.5 ${t.stripe}`} />

          <div className="px-10 pb-8 pt-12 text-center">
            <CardArt card={card} />
            <p className={`font-mono text-[10px] uppercase tracking-[0.25em] ${t.eyebrow}`}>
              {t.label}
              {card.year ? ` · ${card.year}` : ''}
            </p>
            <h2 className="mt-1 font-display text-3xl font-bold leading-tight text-ink-900">{card.name}</h2>
            {card.candidate && <p className="mt-1 font-serif italic text-ink-700">Patron of {card.candidate}</p>}
            <div className="mt-3 flex items-center justify-center gap-4">
              <span className="font-mono text-[11px] uppercase tracking-[0.2em] text-ink-700">{card.cost ? `Cost ◆${card.cost}` : 'No cost'}</span>
              <PrestigeSeal vp={card.vp} size="lg" perOffice={card.vp_per_office} />
              <span className="font-mono text-[11px] uppercase tracking-[0.2em] text-ink-700">wealth</span>
            </div>

            <FleuronDivider className="my-5" />

            <Abilities card={card} size="md" />
            {card.card_text && <p className="mx-auto mt-4 max-w-md font-serif text-base leading-relaxed text-ink-900">{card.card_text}</p>}
            <Framings card={card} />
            {card.flavor && <p className="mx-auto mt-4 max-w-md font-serif text-sm italic leading-relaxed text-ink-700">{card.flavor}</p>}

            {actions && <div className="mt-6 flex flex-wrap justify-center gap-2 border-t border-gold-500/30 pt-4">{actions}</div>}
          </div>
        </article>
      </div>
    </div>
  );
}
