import { useState } from 'react';
import Card from '../dc/Card.jsx';
import HandFan from '../dc/HandFan.jsx';
import StakeModal from './StakeModal.jsx';

const POOLS = [
  ['gen', 'Neutral', 'text-cream-50', 'spends on anything'],
  ['rep', 'Republican', 'text-oxblood-300', 'Trump: calling or buying a state for him, or a Republican story'],
  ['dem', 'Democratic', 'text-federal-300', 'Harris: calling or buying a state for her, or a Democratic story'],
  ['campaign', 'Campaign', 'text-gold-400', 'calling a big state only'],
];

/** The currencies of the turn in progress. */
export function Pools24({ pools }) {
  if (!pools) return null;
  return (
    <div className="flex flex-wrap justify-center gap-x-4 gap-y-1">
      {POOLS.map(([k, label, cls, tip]) => (
        <span key={k} className="font-mono text-[10px] uppercase tracking-[0.15em] text-cream-200/60" title={tip}>
          {label} <span className={`text-lg ${cls}`}>{pools[k] || 0}</span>
        </span>
      ))}
    </div>
  );
}

/**
 * Your turn: the currencies, the play area (drag a card from your hand
 * into it, or middle-click the card), your hand, and the buttons -- Stake
 * (in place of the turn, before anything is played), Play all, your
 * outlet's ability, End turn. On a rival's turn it shows what they played.
 */
export default function Turn24({ state, me, act, busy, open }) {
  const [over, setOver] = useState(false);
  const [staking, setStaking] = useState(false);
  const turn = state.turn;
  const you = state.you;
  const av = state.available_actions || {};
  const myTurn = turn && you && turn.seat === you.seat;
  const canPlay = myTurn && !busy && !(you && you.pending);
  const playable = av.play || [];
  const canStake = myTurn && (av.stake || []).length > 0;
  const onTurn = state.players.find((p) => p.on_turn);
  const paper = me && me.paper;
  const paperLabel =
    paper && paper.key === 'sun' ? 'The Sun: discard a Scandal, draw 3' : paper && paper.key === 'herald' ? 'The Herald: scoop (2)' : null;

  const zone = {
    onDragOver: (e) => {
      if (!canPlay) return;
      e.preventDefault();
      e.dataTransfer.dropEffect = 'move';
      setOver(true);
    },
    onDragLeave: () => setOver(false),
    onDrop: (e) => {
      e.preventDefault();
      setOver(false);
      const key = e.dataTransfer.getData('text/plain');
      if (canPlay && playable.includes(key)) act('play', { card: key });
    },
  };
  const zoneCls = over
    ? 'mt-2 flex min-h-[9.5rem] flex-col items-center justify-center border-2 border-dashed border-gold-300 bg-ink-800/80 p-2 shadow-glow transition'
    : myTurn
      ? 'mt-2 flex min-h-[9.5rem] flex-col items-center justify-center border-2 border-dashed border-gold-500/50 bg-ink-950/40 p-2 transition'
      : 'mt-2 flex min-h-[9.5rem] flex-col items-center justify-center border border-gold-500/20 bg-ink-950/30 p-2';

  return (
    <section className={myTurn ? 'panel border-gold-300 px-3 py-2 shadow-glow' : 'panel px-3 py-2'}>
      <div className="text-center">
        <div className="section-title">{myTurn ? 'Your turn' : onTurn ? `${onTurn.player_name} is on turn` : 'Waiting'}</div>
        {turn && (
          <div className="mt-1">
            <Pools24 pools={turn.pools} />
          </div>
        )}
      </div>

      <div {...zone} className={zoneCls}>
        {turn && turn.played.length > 0 ? (
          <div className="flex flex-wrap justify-center gap-1.5">
            {turn.played.map((c, i) => (
              <Card key={c.key} card={c} size="xs" onOpen={() => open(turn.played, i, 'played')} />
            ))}
          </div>
        ) : null}
        <p className="mt-1 font-mono text-[9px] uppercase tracking-[0.2em] text-cream-200/50">
          {myTurn
            ? over
              ? 'Let go to play it'
              : canStake
                ? 'The press · drag a card here to play it · or stake one instead'
                : 'The press · drag a card here to play it'
            : turn && turn.played.length === 0
              ? 'Nothing played yet'
              : 'Played this turn'}
        </p>
      </div>
      {turn && turn.bought.length > 0 && (
        <p className="mt-1 text-center font-serif text-sm italic text-cream-200/70">Bought: {turn.bought.map((c) => c.name).join(', ')}</p>
      )}

      {you && (
        <div className="mt-2">
          <div className="label text-center text-cream-200/50">
            Your hand · {you.hand.length}
            {you.hand.length > 0 && (myTurn ? ' · drag or middle-click to the press · click to open' : ' · click to open')}
          </div>
          {you.hand.length === 0 ? (
            <p className="mt-2 text-center font-serif text-sm italic text-cream-200/50">{myTurn ? 'Every card is played.' : 'Empty.'}</p>
          ) : (
            <HandFan>
              {you.hand.map((c, i) => (
                <Card
                  key={c.key}
                  card={c}
                  dim={!myTurn}
                  onOpen={() => open(you.hand, i, 'hand')}
                  dragKey={canPlay && playable.includes(c.key) ? c.key : null}
                  onMiddle={canPlay && playable.includes(c.key) ? () => act('play', { card: c.key }) : null}
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
        <div className="mt-1 flex flex-wrap items-center justify-center gap-2 border-t border-gold-500/30 pt-2">
          {canStake && (
            <button type="button" className="btn border-gold-300 text-gold-300" disabled={busy} onClick={() => setStaking(true)}>
              Stake a card instead…
            </button>
          )}
          <button type="button" className="btn" disabled={busy || !av.play_all} onClick={() => act('play_all')}>
            Play all
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
