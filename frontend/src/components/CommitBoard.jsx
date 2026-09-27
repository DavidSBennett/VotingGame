import { useEffect, useMemo, useState } from 'react';
import Clipping from './Clipping.jsx';
import NationGauge from './NationGauge.jsx';
import Collapsible from './Collapsible.jsx';

/**
 * The table: three drop zones -- the States candidate, Cash in, the Nation
 * candidate -- above your desk of clippings.
 *
 * Drag a clipping (or tap it, then tap a zone) to commit it:
 *
 *   Cash in       played for PROFIT (doubled if you are the Patron)
 *   a candidate   played for the coverage stat that pushes toward that
 *                 candidate's side, as influence on him. A card's positive
 *                 and negative push opposite ways, so at most one fits
 *                 each candidate; a card with nothing for him is refused.
 *
 * Nothing is sent until you press Commit (or Pass), and nobody sees it
 * until every paper has committed. The server re-checks every rule; this
 * only adds up your own choices.
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

function effectOf(card, zone) {
  if (zone === 'cash') return { mode: 'profit', money: card.profit_value };
  const mode = modeFor(card, zone);
  return { mode, push: card[mode], stability: card.stability };
}

export default function CommitBoard({ hand, race, commit, busy, onCommit, rules, stability, seats = [], history = [], track = { min: -5, max: 5 } }) {
  const [place, setPlace] = useState({});       // card key -> 'cash' | 'states' | 'nation'
  const [reserve, setReserve] = useState(null);
  const [editing, setEditing] = useState(false);
  const [dragging, setDragging] = useState(null);
  const [over, setOver] = useState(null);
  const [selected, setSelected] = useState(null);
  const [notice, setNotice] = useState(null);

  const cards = hand || [];
  const byKey = useMemo(() => Object.fromEntries(cards.map((c) => [c.key, c])), [cards]);

  // A new round, or a commitment arriving from the server, resets the table.
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

  const refusal = (card, zone) => {
    if (zone === 'cash' || zone === 'hand') return null;
    const mode = modeFor(card, zone);
    if (!mode) return `${card.name} has no coverage that helps ${race ? race[zone].name : zone}.`;
    if (mode === 'negative' && negativesPlaced(card.key) >= rules.max_negative) {
      return `Only ${rules.max_negative} card a round can run hostile coverage.`;
    }
    return null;
  };

  const drop = (key, zone) => {
    if (locked || !byKey[key]) return;
    // The dropped clipping is remounted in its new zone, so its dragend
    // never fires: always clear the drag state here.
    setDragging(null);
    setOver(null);
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
        push += c[mode];
        influence[z] += Math.abs(c[mode]);
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
  const holding = dragging || selected;

  const submit = () => {
    const plays = committed.map((c) => {
      const z = place[c.key];
      return z === 'cash' ? { card: c.key, action: 'profit' } : { card: c.key, action: modeFor(c, z), side: z };
    });
    onCommit({ plays, reserve: effectiveReserve || undefined });
  };

  // ---- pieces (render functions, not components: see Clipping) ----------

  const placedCard = (c, zone) => (
    <div key={c.key} className="animate-rise">
      <Clipping
        card={c}
        size="sm"
        effect={effectOf(c, zone)}
        lifted={holding === c.key}
        {...cardHandlers(c.key)}
        style={{ cursor: locked ? 'default' : 'grab' }}
      />
      {(!locked || (SIDES.includes(zone) && effectiveReserve === c.key)) && (
        <div className="mt-1 flex items-center justify-between font-mono text-[9px] uppercase tracking-[0.15em]">
          {SIDES.includes(zone) ? (
            <button
              type="button"
              disabled={locked}
              onClick={(e) => {
                e.stopPropagation();
                setReserve(c.key);
              }}
              className={effectiveReserve === c.key ? 'text-gold-300' : 'text-cream-200/50 hover:text-gold-300'}
              title="Reserve: back to your desk unless you become Patron"
            >
              {effectiveReserve === c.key ? '★ Reserved' : '☆ Reserve'}
            </button>
          ) : (
            <span />
          )}
          {!locked && (
            <button
              type="button"
              onClick={(e) => {
                e.stopPropagation();
                drop(c.key, 'hand');
              }}
              className="text-cream-200/50 hover:text-cream-50"
            >
              ↩ Desk
            </button>
          )}
        </div>
      )}
    </div>
  );

  const preview = (zone) => {
    if (!holding || !byKey[holding] || locked) return null;
    const c = byKey[holding];
    if (refusal(c, zone)) return <span className="text-cream-200/40">won&rsquo;t fit</span>;
    if (zone === 'cash') return <span className="text-gold-300">sell for ${c.profit_value}</span>;
    const mode = modeFor(c, zone);
    return (
      <span className={mode === 'negative' ? 'text-oxblood-300' : 'text-cream-50'}>
        {mode === 'negative' ? 'hostile' : 'favourable'} {pushText(c[mode])}
        {mode === 'negative' && ` · union −${c.stability}`}
      </span>
    );
  };

  const zoneShell = (zone) => {
    const hot = over === zone;
    const target = holding && !locked && byKey[holding] && !refusal(byKey[holding], zone);
    const base = 'relative flex min-h-[10rem] flex-col overflow-y-auto border px-3 py-2 transition duration-200 lg:min-h-0';
    if (zone === 'states') {
      return hot
        ? `${base} border-oxblood-300 bg-oxblood-900/70 shadow-glow`
        : target
          ? `${base} border-oxblood-500 bg-oxblood-900/40`
          : `${base} border-oxblood-700/60 bg-oxblood-900/25`;
    }
    if (zone === 'nation') {
      return hot
        ? `${base} border-federal-300 bg-federal-900/80 shadow-glow`
        : target
          ? `${base} border-federal-500 bg-federal-900/50`
          : `${base} border-federal-700/60 bg-federal-900/30`;
    }
    return hot
      ? `${base} border-gold-300 bg-wood-800/70 shadow-glow`
      : target
        ? `${base} border-gold-400 bg-wood-900/60`
        : `${base} border-gold-500/40 bg-wood-950/40`;
  };

  const candidateZone = (side) => {
    const c = race ? race[side] : null;
    const placed = inZone(side);
    return (
      <div key={side} {...zoneHandlers(side)} className={zoneShell(side)}>
        <div className="text-center">
          <div className={side === 'nation' ? 'label text-federal-300' : 'label text-oxblood-300'}>
            {side === 'nation' ? 'Nation · federal power' : 'States · states’ rights'}
          </div>
          <div className="font-display text-xl font-semibold leading-tight text-cream-50">{c ? c.name : side}</div>
          {c && <div className="font-serif text-xs italic text-cream-200/70">{c.party}</div>}
        </div>
        <div className="divider" />
        <div className="mb-2 flex items-baseline justify-between font-mono text-[10px] uppercase tracking-[0.15em]">
          <span className="text-cream-200/60">
            {summary.influence[side] > 0 ? `your influence ${summary.influence[side]}` : 'coverage'}
          </span>
          <span>{preview(side)}</span>
        </div>
        {placed.length === 0 ? (
          <div className="flex flex-1 items-center justify-center border border-dashed border-cream-200/15 font-serif text-sm italic text-cream-200/35">
            {locked ? '—' : 'Drop coverage here'}
          </div>
        ) : (
          <div className="grid grid-cols-1 gap-2 sm:grid-cols-2 md:grid-cols-1 xl:grid-cols-2">
            {placed.map((card) => placedCard(card, side))}
          </div>
        )}
      </div>
    );
  };

  // ---- render ------------------------------------------------------------

  return (
    <section className="flex flex-col gap-2 animate-fade lg:min-h-0 lg:flex-1">
      <Collapsible
        title="The temper of the nation"
        storageKey="temper"
        summary={
          history.length
            ? `States ${history.filter((h) => h.winner_side === 'states').length} · Federal ${history.filter((h) => h.winner_side === 'nation').length} · last ${
                history[history.length - 1].track > 0
                  ? `Federal +${history[history.length - 1].track}`
                  : history[history.length - 1].track < 0
                    ? `States +${-history[history.length - 1].track}`
                    : 'level'
              }${committed.some((c) => SIDES.includes(place[c.key])) ? ` · your push ${pushText(summary.push)}` : ''}`
            : 'no election decided yet'
        }
      >
        <NationGauge
          bare
          history={history}
          min={track.min}
          max={track.max}
          planned={committed.some((c) => SIDES.includes(place[c.key])) ? summary.push : null}
        />
      </Collapsible>
      <div className="grid gap-2 md:grid-cols-3 lg:min-h-0 lg:flex-1">
        {candidateZone('states')}

        <div {...zoneHandlers('cash')} className={zoneShell('cash')}>
          <div className="text-center">
            <div className="label">The counting house</div>
            <div className="font-display text-xl font-semibold leading-tight text-cream-50">Cash in</div>
            <div className="font-serif text-xs italic text-cream-200/70">
              {me && me.is_patron ? 'You are Patron — profit pays double' : 'Money is the only score'}
            </div>
          </div>
          <div className="divider" />
          <div className="mb-2 flex items-baseline justify-between font-mono text-[10px] uppercase tracking-[0.15em]">
            <span className="text-cream-200/60">{summary.money > 0 ? `profit $${summary.money}` : 'profit'}</span>
            <span>{preview('cash')}</span>
          </div>
          {inZone('cash').length === 0 ? (
            <div className="flex flex-1 items-center justify-center border border-dashed border-cream-200/15 font-serif text-sm italic text-cream-200/35">
              {locked ? '—' : 'Sell clippings here'}
            </div>
          ) : (
            <div className="grid grid-cols-1 gap-2 sm:grid-cols-2 md:grid-cols-1 xl:grid-cols-2">
              {inZone('cash').map((card) => placedCard(card, 'cash'))}
            </div>
          )}
        </div>

        {candidateZone('nation')}
      </div>

      {notice && (
        <p className="shrink-0 border-l-2 border-oxblood-500 bg-oxblood-900/40 px-3 py-1 font-serif text-sm italic text-cream-100">
          {notice}
        </p>
      )}

      {/* The desk */}
      <div
        {...zoneHandlers('hand')}
        className={
          over === 'hand'
            ? 'shrink-0 border border-gold-300 bg-gradient-to-b from-wood-800 to-wood-900 px-3 pb-1 pt-1.5 shadow-glow transition'
            : 'shrink-0 border border-wood-700 bg-gradient-to-b from-wood-800 to-wood-950 px-3 pb-1 pt-1.5 transition'
        }
      >
        <div className="section-title mb-1">Your desk · {loose.length}</div>
        {cards.length === 0 ? (
          <p className="text-center font-serif text-sm italic text-cream-200/50">Your desk is empty.</p>
        ) : loose.length === 0 ? (
          <p className="text-center font-serif text-sm italic text-cream-200/50">
            Every clipping is on the table. Drag one back here to keep it.
          </p>
        ) : (
          <div className="flex gap-2 overflow-x-auto pb-1.5 pt-1">
            {loose.map((card) => (
              <Clipping
                key={card.key}
                card={card}
                lifted={holding === card.key}
                dim={locked}
                {...cardHandlers(card.key)}
                style={{ cursor: locked ? 'default' : 'grab' }}
              />
            ))}
          </div>
        )}
        {!locked && cards.length > 0 && (
          <p className="text-center font-mono text-[9px] uppercase tracking-[0.2em] text-cream-200/40 [@media(max-height:820px)]:hidden">
            Drag a clipping to a candidate or the counting house — or tap it, then tap where it goes
          </p>
        )}
      </div>

      {/* The commitment line */}
      <div className="flex shrink-0 flex-wrap items-center justify-between gap-x-3 gap-y-1 border border-gold-500/40 bg-ink-900/90 px-3 py-1.5">
        <div className="flex flex-wrap items-baseline gap-x-5 gap-y-1 font-mono text-[11px] uppercase tracking-[0.12em]">
          <span className="text-cream-200/70">
            committing <span className="text-cream-50">{committed.length}</span> · keeping{' '}
            <span className="text-cream-50">{loose.length}</span>
          </span>
          <span className="text-gold-300">profit ${summary.money}</span>
          <span className="text-cream-200/70">push {pushText(summary.push)}</span>
          {summary.cost > 0 && <span className={danger ? 'text-oxblood-300' : 'text-gold-400'}>union −{summary.cost}</span>}
          {negatives > 0 && <span className={wouldLead ? 'text-oxblood-300' : 'text-cream-200/70'}>exposure → {myExposure}</span>}
        </div>

        <div className="flex items-center gap-2">
          {locked ? (
            <>
              <span className="font-serif text-sm italic text-cream-200/70">
                {commit.plays.length === 0 ? 'You passed. Waiting for the others…' : 'Sealed. Waiting for the others…'}
              </span>
              <button type="button" onClick={() => setEditing(true)} className="btn">
                Change
              </button>
            </>
          ) : (
            <>
              {committed.length > 0 && (
                <button
                  type="button"
                  onClick={() => {
                    setPlace({});
                    setReserve(null);
                    setNotice(null);
                  }}
                  className="btn"
                >
                  Clear
                </button>
              )}
              <button type="button" disabled={busy} onClick={submit} className="btn-solid">
                {committed.length > 0
                  ? `Commit ${committed.length} ${committed.length === 1 ? 'clipping' : 'clippings'}`
                  : 'Pass this round'}
              </button>
            </>
          )}
        </div>

        {(wouldLead || danger || (covered.length > 0 && !effectiveReserve && !locked) || (committed.length === 0 && !locked)) && (
          <div className="w-full space-y-0.5 font-serif text-xs italic">
            {wouldLead && (
              <p className="text-oxblood-300">
                This makes you {myExposure > rivalTop ? 'the most exposed paper' : 'joint most exposed'}. If the Union
                breaks, you lose {rules.exposure_penalty}.
              </p>
            )}
            {danger && (
              <p className="text-oxblood-300">
                The Union stands at {stability}. If the table spends it all, the Union breaks and the game ends.
              </p>
            )}
            {covered.length > 0 && !effectiveReserve && !locked && (
              <p className="text-cream-200/50">No reserve starred: your most profitable coverage will be kept.</p>
            )}
            {committed.length === 0 && !locked && (
              <p className="text-cream-200/50">
                Nothing on the table: you may pass. You still draw {rules.draw_per_round} at the round&rsquo;s end.
              </p>
            )}
          </div>
        )}
      </div>
    </section>
  );
}
