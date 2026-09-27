import { useEffect, useMemo, useState } from 'react';

/**
 * The commitment board: three drop zones -- the States candidate, Cash in,
 * the Nation candidate -- and your hand below them.
 *
 * Drag a card (or tap it, then tap a zone) to commit it:
 *
 *   Cash in       played for PROFIT (doubled if you are the Patron)
 *   a candidate   played for the coverage stat that pushes toward that
 *                 candidate's side, as influence on him. A card's positive
 *                 and negative push opposite ways, so at most one fits
 *                 each candidate; a card with nothing for him is refused.
 *
 * Nothing is sent until you press Commit, and nobody sees it until every
 * paper has committed. The server re-checks every rule; this only adds up
 * your own choices so you can see what you are about to do.
 */
const SIDES = ['states', 'nation'];
const pushText = (p) => (p > 0 ? `Nation +${p}` : p < 0 ? `States +${-p}` : '—');
const want = (side) => (side === 'nation' ? 1 : -1);

/** The coverage mode a card uses for a side, or null if it has none. */
function modeFor(card, side) {
  if (card.positive * want(side) > 0) return 'positive';
  if (card.negative * want(side) > 0) return 'negative';
  return null;
}

export default function CommitBoard({ hand, race, commit, busy, onCommit, rules, stability, seats = [] }) {
  const [place, setPlace] = useState({});       // card key -> 'cash' | 'states' | 'nation'
  const [reserve, setReserve] = useState(null);
  const [editing, setEditing] = useState(false);
  const [dragging, setDragging] = useState(null);
  const [over, setOver] = useState(null);
  const [selected, setSelected] = useState(null);
  const [notice, setNotice] = useState(null);

  const cards = hand || [];
  const byKey = useMemo(() => Object.fromEntries(cards.map((c) => [c.key, c])), [cards]);

  // A new round, or a commitment arriving from the server, resets the board.
  const handKey = cards.map((c) => c.key).join(',');
  useEffect(() => {
    const next = {};
    if (commit) {
      commit.plays.forEach((pl) => {
        next[pl.card] = pl.action === 'profit' ? 'cash' : pl.side;
      });
    }
    setPlace(next);
    setReserve(commit ? commit.reserve : null);
    setEditing(false);
    setSelected(null);
    setNotice(null);
  }, [handKey, race && race.space, commit && JSON.stringify(commit)]);

  const locked = Boolean(commit) && !editing;
  const inZone = (zone) => cards.filter((c) => place[c.key] === zone);
  const loose = cards.filter((c) => !place[c.key]);
  const negativesPlaced = (except) =>
    cards.filter((c) => c.key !== except && SIDES.includes(place[c.key]) && modeFor(c, place[c.key]) === 'negative').length;

  /** Why a card cannot go to a zone, or null if it can. */
  const refusal = (card, zone) => {
    if (zone === 'cash' || zone === 'hand') return null;
    const mode = modeFor(card, zone);
    if (!mode) return `${card.name} has no coverage that helps ${race ? race[zone].name : zone}.`;
    if (mode === 'negative' && negativesPlaced(card.key) >= rules.max_negative) {
      return `Only ${rules.max_negative} card a round can run negative coverage.`;
    }
    return null;
  };

  const drop = (key, zone) => {
    if (locked || !byKey[key]) return;
    const why = refusal(byKey[key], zone);
    if (why) {
      setNotice(why);
      return;
    }
    setNotice(null);
    setPlace((prev) => {
      const next = { ...prev };
      if (zone === 'hand') delete next[key];
      else next[key] = zone;
      return next;
    });
    setSelected(null);
  };

  const zoneHandlers = (zone) => ({
    onDragOver: (e) => {
      if (locked) return;
      e.preventDefault();
      setOver(zone);
    },
    onDragLeave: () => setOver((o) => (o === zone ? null : o)),
    onDrop: (e) => {
      e.preventDefault();
      setOver(null);
      drop(e.dataTransfer.getData('text/plain'), zone);
    },
    onClick: () => {
      if (selected) drop(selected, zone);
    },
  });

  const cardHandlers = (key) => ({
    draggable: !locked,
    onDragStart: (e) => {
      e.dataTransfer.setData('text/plain', key);
      e.dataTransfer.effectAllowed = 'move';
      setDragging(key);
    },
    onDragEnd: () => {
      setDragging(null);
      setOver(null);
    },
    onClick: (e) => {
      e.stopPropagation();
      if (!locked) setSelected((s) => (s === key ? null : key));
    },
  });

  // The totals of what is on the board.
  const summary = useMemo(() => {
    let money = 0;
    let push = 0;
    let cost = 0;
    const influence = { nation: 0, states: 0 };
    cards.forEach((c) => {
      const z = place[c.key];
      if (z === 'cash') money += c.profit_value;
      if (SIDES.includes(z)) {
        const mode = modeFor(c, z);
        const p = c[mode];
        push += p;
        influence[z] += Math.abs(p);
        if (mode === 'negative') cost += c.stability;
      }
    });
    return { money, push, cost, influence };
  }, [cards, place]);

  const committed = cards.filter((c) => place[c.key]);
  const covered = committed.filter((c) => SIDES.includes(place[c.key]));
  const effectiveReserve = covered.some((c) => c.key === reserve) ? reserve : null;
  const negatives = negativesPlaced(null);
  const me = seats.find((p) => p.is_you);
  const myExposure = (me ? me.exposure : 0) + negatives;
  const rivalTop = Math.max(0, ...seats.filter((p) => !p.is_you).map((p) => p.exposure));
  const wouldLead = negatives > 0 && myExposure >= rivalTop;
  const danger = summary.cost > 0 && stability - summary.cost <= 3;

  const submit = () => {
    const plays = committed.map((c) => {
      const z = place[c.key];
      return z === 'cash'
        ? { card: c.key, action: 'profit' }
        : { card: c.key, action: modeFor(c, z), side: z };
    });
    onCommit({ plays, reserve: effectiveReserve || undefined });
  };

  // ---- pieces ------------------------------------------------------------

  const renderCard = (c, zone) => {
    const mode = SIDES.includes(zone) ? modeFor(c, zone) : null;
    const lift = dragging === c.key || selected === c.key;
    return (
      <div
        key={c.key}
        {...cardHandlers(c.key)}
        title={c.flavor}
        className={
          lift
            ? 'cursor-grab rounded border border-amber-400 bg-slate-900 p-2 text-left shadow-lg ring-2 ring-amber-400'
            : locked
              ? 'rounded border border-slate-700 bg-slate-900 p-2 text-left'
              : 'cursor-grab rounded border border-slate-600 bg-slate-900 p-2 text-left hover:border-slate-400'
        }
      >
        <div className="flex items-baseline justify-between gap-2">
          <span className="text-sm font-medium leading-tight text-slate-100">{c.name}</span>
          <span className="font-mono text-xs text-slate-500">{c.year}</span>
        </div>

        {zone === 'cash' ? (
          <div className="mt-1 font-mono text-xs text-emerald-400">
            profit +{c.profit_value}
            {c.profit_value !== c.profit && ' (Patron ×2)'}
          </div>
        ) : mode ? (
          <div className={mode === 'negative' ? 'mt-1 font-mono text-xs text-red-300' : 'mt-1 font-mono text-xs text-slate-200'}>
            {mode} {pushText(c[mode])} · influence {Math.abs(c[mode])}
            {mode === 'negative' && ` · union −${c.stability}`}
          </div>
        ) : (
          <div className="mt-1 grid grid-cols-3 gap-1 font-mono text-xs">
            <span className="text-emerald-400">
              P {c.profit}
              {c.profit_value !== c.profit && `→${c.profit_value}`}
            </span>
            <span className={c.positive > 0 ? 'text-sky-300' : c.positive < 0 ? 'text-rose-300' : 'text-slate-600'}>
              + {pushText(c.positive)}
            </span>
            <span className={c.negative > 0 ? 'text-sky-300' : c.negative < 0 ? 'text-rose-300' : 'text-slate-600'}>
              − {pushText(c.negative)}
              {c.stability > 0 && <span className="text-red-400"> ·{c.stability}</span>}
            </span>
          </div>
        )}

        {zone && !locked && (
          <div className="mt-1 flex items-center justify-between gap-2 text-xs">
            {SIDES.includes(zone) ? (
              <button
                type="button"
                onClick={(e) => {
                  e.stopPropagation();
                  setReserve(c.key);
                }}
                className={effectiveReserve === c.key ? 'text-amber-300' : 'text-slate-500 hover:text-amber-300'}
                title="Reserve: back to your hand unless you become Patron"
              >
                {effectiveReserve === c.key ? '★ reserved' : '☆ reserve'}
              </button>
            ) : (
              <span />
            )}
            <button
              type="button"
              onClick={(e) => {
                e.stopPropagation();
                drop(c.key, 'hand');
              }}
              className="text-slate-500 hover:text-slate-200"
            >
              ↩ back to hand
            </button>
          </div>
        )}
        {zone && locked && SIDES.includes(zone) && effectiveReserve === c.key && (
          <div className="mt-1 text-xs text-amber-300">★ reserved</div>
        )}
      </div>
    );
  };

  /** What the card being dragged or selected would do in a zone. */
  const hint = (zone) => {
    const key = dragging || selected;
    if (!key || !byKey[key] || locked) return null;
    const c = byKey[key];
    const why = refusal(c, zone);
    if (why) return <span className="text-slate-500">won&rsquo;t fit here</span>;
    if (zone === 'cash') return <span className="text-emerald-300">drop: profit +{c.profit_value}</span>;
    const mode = modeFor(c, zone);
    return (
      <span className={mode === 'negative' ? 'text-red-300' : 'text-slate-200'}>
        drop: {mode} {pushText(c[mode])}
        {mode === 'negative' && `, union −${c.stability}`}
      </span>
    );
  };

  const zoneClass = (zone, tone) => {
    const hot = over === zone;
    const base = 'flex min-h-[11rem] flex-col rounded-lg border-2 p-3 transition-colors';
    if (tone === 'states') {
      return hot ? `${base} border-rose-400 bg-rose-950` : `${base} border-dashed border-rose-900 bg-slate-900`;
    }
    if (tone === 'nation') {
      return hot ? `${base} border-sky-400 bg-sky-950` : `${base} border-dashed border-sky-900 bg-slate-900`;
    }
    return hot ? `${base} border-emerald-400 bg-emerald-950` : `${base} border-dashed border-emerald-900 bg-slate-900`;
  };

  const renderCandidateZone = (side) => {
    const c = race ? race[side] : null;
    return (
      <div key={side} {...zoneHandlers(side)} className={zoneClass(side, side)}>
        <div className="flex items-baseline justify-between gap-2">
          <span className={side === 'nation' ? 'font-medium text-sky-200' : 'font-medium text-rose-200'}>
            {c ? c.name : side}
          </span>
          <span className={side === 'nation' ? 'text-xs text-sky-400' : 'text-xs text-rose-400'}>
            {side === 'nation' ? 'Nation' : 'States'}
          </span>
        </div>
        {c && <div className="text-xs text-slate-500">{c.party}</div>}
        <div className="mt-1 text-xs">
          {summary.influence[side] > 0 ? (
            <span className="text-slate-300">your influence {summary.influence[side]}</span>
          ) : (
            <span className="text-slate-600">drop coverage here</span>
          )}
          <span className="ml-2">{hint(side)}</span>
        </div>
        <div className="mt-2 space-y-2">
          {inZone(side).map((card) => renderCard(card, side))}
        </div>
      </div>
    );
  };

  // ---- render ------------------------------------------------------------

  if (cards.length === 0) {
    return (
      <section className="rounded-lg border border-slate-700 bg-slate-800 p-4">
        <p className="text-sm text-slate-500">No cards in hand.</p>
        {!commit && (
          <button
            type="button"
            disabled={busy}
            onClick={() => onCommit({ plays: [] })}
            className="mt-3 rounded bg-amber-600 px-4 py-2 text-sm font-medium text-slate-950 hover:bg-amber-500 disabled:opacity-40"
          >
            Pass this round
          </button>
        )}
      </section>
    );
  }

  return (
    <section className="rounded-lg border border-slate-700 bg-slate-800 p-4">
      <div className="mb-3 flex flex-wrap items-baseline justify-between gap-2">
        <h2 className="text-xs uppercase tracking-widest text-slate-400">Your commitment</h2>
        <span className="text-xs text-slate-500">
          {locked ? 'Committed — sealed until everyone is in' : 'Drag cards (or tap a card, then a zone). Sealed until everyone is in.'}
        </span>
      </div>

      <div className="grid gap-3 md:grid-cols-3">
        {renderCandidateZone('states')}
        <div {...zoneHandlers('cash')} className={zoneClass('cash', 'cash')}>
          <div className="flex items-baseline justify-between gap-2">
            <span className="font-medium text-emerald-200">Cash in</span>
            <span className="text-xs text-emerald-400">profit</span>
          </div>
          <div className="text-xs text-slate-500">
            {me && me.is_patron ? 'You are Patron: profit pays double' : 'Money is the only score'}
          </div>
          <div className="mt-1 text-xs">
            {summary.money > 0 ? (
              <span className="text-emerald-300">+{summary.money}</span>
            ) : (
              <span className="text-slate-600">drop cards to sell here</span>
            )}
            <span className="ml-2">{hint('cash')}</span>
          </div>
          <div className="mt-2 space-y-2">
            {inZone('cash').map((card) => renderCard(card, 'cash'))}
          </div>
        </div>
        {renderCandidateZone('nation')}
      </div>

      {notice && <p className="mt-2 text-sm text-amber-300">{notice}</p>}

      <div
        {...zoneHandlers('hand')}
        className={
          over === 'hand'
            ? 'mt-4 rounded-lg border-2 border-slate-400 bg-slate-900 p-3'
            : 'mt-4 rounded-lg border-2 border-dashed border-slate-700 bg-slate-900 p-3'
        }
      >
        <div className="mb-2 text-xs uppercase tracking-widest text-slate-500">
          Your hand · {loose.length} kept
        </div>
        {loose.length === 0 ? (
          <p className="text-xs text-slate-600">Every card is committed. Drag one back here to keep it.</p>
        ) : (
          <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
            {loose.map((card) => renderCard(card, null))}
          </div>
        )}
      </div>

      <div className="mt-4 rounded border border-slate-700 bg-slate-900 p-3 text-sm text-slate-300">
        <div className="flex flex-wrap gap-x-4 gap-y-1">
          <span>
            Committing <span className="font-mono text-slate-100">{committed.length}</span>, keeping{' '}
            <span className="font-mono text-slate-100">{loose.length}</span>
          </span>
          <span className="text-emerald-400">profit +{summary.money}</span>
          <span className="text-slate-400">your push {pushText(summary.push)}</span>
          {summary.cost > 0 && <span className={danger ? 'text-red-400' : 'text-amber-400'}>union −{summary.cost}</span>}
          {negatives > 0 && <span className={wouldLead ? 'text-red-400' : 'text-slate-400'}>exposure → {myExposure}</span>}
        </div>
        {wouldLead && (
          <p className="mt-1 text-xs text-red-400">
            This makes you {myExposure > rivalTop ? 'the most exposed paper' : 'joint most exposed'}. If the Union
            breaks, you lose {rules.exposure_penalty}.
          </p>
        )}
        {danger && (
          <p className="mt-1 text-xs text-red-400">
            Stability is {stability}. If the table spends it all, the Union breaks and the game ends.
          </p>
        )}
        {covered.length > 0 && !effectiveReserve && !locked && (
          <p className="mt-1 text-xs text-slate-500">
            No reserve starred: your most profitable coverage card will be kept.
          </p>
        )}
        <div className="mt-3 flex flex-wrap gap-2">
          {locked ? (
            <button
              type="button"
              onClick={() => setEditing(true)}
              className="rounded border border-slate-600 px-4 py-2 text-sm text-slate-200 hover:border-amber-500"
            >
              Change my commitment
            </button>
          ) : (
            <>
              <button
                type="button"
                disabled={busy || committed.length === 0}
                onClick={submit}
                className="rounded bg-amber-600 px-4 py-2 text-sm font-medium text-slate-950 hover:bg-amber-500 disabled:opacity-40"
              >
                Commit {committed.length} {committed.length === 1 ? 'card' : 'cards'}
              </button>
              {committed.length > 0 && (
                <button
                  type="button"
                  onClick={() => {
                    setPlace({});
                    setReserve(null);
                    setNotice(null);
                  }}
                  className="rounded px-3 py-2 text-sm text-slate-400 hover:text-slate-200"
                >
                  Clear the board
                </button>
              )}
            </>
          )}
        </div>
      </div>
    </section>
  );
}
