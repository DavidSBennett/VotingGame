import { useState } from 'react';
import Card from '../dc/Card.jsx';
import HandFan from '../dc/HandFan.jsx';
import StakeModal from './StakeModal.jsx';

const POOLS = [
  ['gen', 'Neutral', 'text-cream-50', 'border-cream-200/40', 'spends on anything'],
  ['rep', 'Republican', 'text-oxblood-300', 'border-oxblood-500/60', 'Trump: buying a state for him, or a Republican story or plank'],
  ['dem', 'Democratic', 'text-federal-300', 'border-federal-500/60', 'Harris: buying a state for her, or a Democratic story or plank'],
  ['campaign', 'Campaign', 'text-gold-300', 'border-gold-500/60', 'states only'],
];

/** The currency on hand this turn: four counters. */
export function Pools24({ pools, big = false }) {
  if (!pools) return null;
  return (
    <div className={big ? 'grid grid-cols-2 gap-2 sm:grid-cols-4' : 'flex flex-wrap justify-center gap-x-4 gap-y-1'}>
      {POOLS.map(([k, label, cls, frame, tip]) =>
        big ? (
          <div key={k} title={tip} className={`border ${frame} bg-ink-950/50 px-3 py-1.5 text-center`}>
            <div className={`font-display text-3xl font-bold leading-none ${cls}`}>{pools[k] || 0}</div>
            <div className="mt-0.5 font-mono text-[9px] uppercase tracking-[0.18em] text-cream-200/60">{label}</div>
          </div>
        ) : (
          <span key={k} className="font-mono text-[10px] uppercase tracking-[0.15em] text-cream-200/60" title={tip}>
            {label} <span className={`text-lg ${cls}`}>{pools[k] || 0}</span>
          </span>
        ),
      )}
    </div>
  );
}

/**
 * Your desk. Every card's currency counts from your hand the moment your
 * turn starts (and when you draw), so the four counters are your budget at
 * once: spend it on the states and the exchange. Playing a card only USES
 * IT: a story on its TOP (its ability) or its BOTTOM (the other party's
 * currency, and on some a plank knockout); a plank into play; a swing
 * state's knockout. Only cards with a use carry buttons. Before you use or
 * buy anything you may instead stake a card.
 */
export default function Turn24({ state, me, act, play, busy, open }) {
  const [staking, setStaking] = useState(false);
  const turn = state.turn;
  const you = state.you;
  const av = state.available_actions || {};
  const myTurn = turn && you && turn.seat === you.seat;
  const canUse = myTurn && !busy && !(you && you.pending);
  const usable = av.play || [];
  const canStake = myTurn && (av.stake || []).length > 0;
  const onTurn = state.players.find((p) => p.on_turn);
  const paper = me && me.paper;
  const paperLabel =
    paper && paper.key === 'sun' ? 'Tabloid: discard a Scandal, draw 3' : paper && paper.key === 'herald' ? 'The Scoop: pay 2' : null;

  const footer = (c) => {
    if (!myTurn) return null;
    if (usable.includes(c.key)) {
      const frames = (av.framings || {})[c.key] || ['top'];
      if (frames.includes('bottom')) {
        const other = c.lean === 'rep' ? 'Dem' : 'Rep';
        return (
          <div className="mt-1 flex w-28 gap-1">
            <button type="button" disabled={!canUse} onClick={() => play(c.key, 'top')} title={c.top_text}
              className="btn flex-1 border-gold-300 px-0.5 py-0.5 text-[10px] text-gold-300">
              ▲ Top
            </button>
            <button type="button" disabled={!canUse} onClick={() => play(c.key, 'bottom')} title={c.bottom_text}
              className={c.lean === 'rep' ? 'btn flex-1 border-federal-300 px-0.5 py-0.5 text-[10px] text-federal-300' : 'btn flex-1 border-oxblood-300 px-0.5 py-0.5 text-[10px] text-oxblood-300'}>
              ▼ +{c.bottom_party} {other}
            </button>
          </div>
        );
      }
      return (
        <button type="button" disabled={!canUse} onClick={() => play(c.key, 'top')} className="btn mt-1 w-28 border-gold-300 px-1 py-0.5 text-gold-300">
          {c.type === 'Plank' ? 'Into play' : 'Use'}
        </button>
      );
    }
    return <div className="mt-1 w-28 py-0.5 text-center font-mono text-[8px] uppercase tracking-[0.15em] text-cream-200/40">counts in hand</div>;
  };

  return (
    <section className={myTurn ? 'panel border-gold-300 px-3 py-2 shadow-glow' : 'panel px-3 py-2'}>
      <div className="flex flex-wrap items-baseline justify-between gap-2">
        <div className="section-title">{myTurn ? 'Your desk' : onTurn ? `${onTurn.player_name} is on turn` : 'Waiting'}</div>
        {myTurn && (
          <span className="font-serif text-[12px] italic text-cream-200/60">Your hand pays at once. Use each story on its top or its bottom, spend, or stake a card instead.</span>
        )}
      </div>

      {turn && (
        <div className="mt-2">
          <Pools24 pools={turn.pools} big={myTurn} />
        </div>
      )}

      {/* What has been used this turn (yours, or the rival's on their turn). */}
      {turn && turn.played.length > 0 && (
        <div className="mt-2 border-t border-gold-500/20 pt-1.5">
          <div className="label text-cream-200/50">{myTurn ? 'Used this turn' : 'Used by ' + (onTurn ? onTurn.player_name : 'the outlet on turn')}</div>
          <div className="mt-1 flex flex-wrap gap-1.5">
            {turn.played.map((c, i) => (
              <Card key={c.key} card={c} size="xs" onOpen={() => open(turn.played, i, 'played')} />
            ))}
          </div>
        </div>
      )}
      {turn && turn.bought.length > 0 && (
        <p className="mt-1 font-serif text-sm italic text-cream-200/70">Bought: {turn.bought.map((c) => c.name).join(', ')}</p>
      )}

      {you && (
        <div className="mt-2 border-t border-gold-500/20 pt-1.5">
          <div className="label text-cream-200/50">
            Your hand · {you.hand.length}
            {myTurn && usable.length > 0 ? ` · ${usable.length} with an ability to use` : ''}
          </div>
          {you.hand.length === 0 ? (
            <p className="mt-2 text-center font-serif text-sm italic text-cream-200/50">{myTurn ? 'Every ability is used.' : 'Empty.'}</p>
          ) : (
            <HandFan footerH={myTurn ? 30 : 0}>
              {you.hand.map((c, i) => (
                <Card
                  key={c.key}
                  card={c}
                  dim={!myTurn}
                  lifted={myTurn && usable.includes(c.key)}
                  onOpen={() => open(you.hand, i, 'hand')}
                  onMiddle={canUse && usable.includes(c.key) ? () => play(c.key, 'top') : null}
                  footer={footer(c)}
                />
              ))}
            </HandFan>
          )}
          {you.held && you.held.length > 0 && (
            <p className="text-center font-serif text-sm italic text-gold-300">Scooped for your next hand: {you.held.map((c) => c.name).join(', ')}</p>
          )}
        </div>
      )}

      {myTurn && (
        <div className="mt-2 flex flex-wrap items-center justify-center gap-2 border-t border-gold-500/30 pt-2">
          {canStake && (
            <button type="button" className="btn border-gold-300 text-gold-300" disabled={busy} onClick={() => setStaking(true)}>
              Stake a card instead…
            </button>
          )}
          <button type="button" className="btn" disabled={busy || !av.play_all} onClick={() => act('play_all')} title="Every card with a use, each on its top">
            Use every top
          </button>
          {paperLabel && (
            <button type="button" className="btn" disabled={busy || !av.paper} onClick={() => act('paper')}>
              {paperLabel}
            </button>
          )}
          <button type="button" className="btn-solid" disabled={busy || !av.end_turn} onClick={() => act('end_turn')}>
            End turn
          </button>
        </div>
      )}
      {staking && canStake && (
        <StakeModal
          hand={you.hand}
          busy={busy}
          onClose={() => setStaking(false)}
          act={(a, p) => {
            setStaking(false);
            act(a, p);
          }}
        />
      )}
    </section>
  );
}
