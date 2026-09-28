import Card from './Card.jsx';

const POOLS = [
  ['gen', 'Plain', 'text-cream-50', 'spends on anything'],
  ['Political', 'Political', 'text-federal-300', 'Political stories, Political candidates'],
  ['Economic', 'Economic', 'text-gold-300', 'Economic stories, Economic candidates'],
  ['Social', 'Social', 'text-emerald-300', 'Social stories, Social candidates'],
  ['campaign', 'Campaign', 'text-gold-400', 'elections only'],
];

/** The influence pools of the turn in progress. */
export function Pools({ pools }) {
  if (!pools) return null;
  return (
    <div className="flex flex-wrap gap-x-4 gap-y-1">
      {POOLS.map(([k, label, cls, tip]) => (
        <span key={k} className="font-mono text-[10px] uppercase tracking-[0.15em] text-cream-200/60" title={tip}>
          {label} <span className={`text-lg ${cls}`}>{pools[k] || 0}</span>
        </span>
      ))}
    </div>
  );
}

/**
 * Your turn: the pools, what you have played, your hand (click a card to
 * play it), and the turn's buttons. On a rival's turn: what they have
 * played so far.
 */
export default function TurnArea({ state, me, act, busy }) {
  const turn = state.turn;
  const you = state.you;
  const av = state.available_actions || {};
  const myTurn = turn && you && turn.seat === you.seat;
  const onTurn = state.players.find((p) => p.on_turn);
  const paper = me && me.paper;
  const paperLabel = paper && paper.key === 'sun' ? 'The Sun: discard a Scandal, draw 3' : paper && paper.key === 'herald' ? 'The Herald: scoop (2)' : null;

  return (
    <section className={myTurn ? 'panel border-gold-300 p-3 shadow-glow' : 'panel p-3'}>
      <div className="mb-2 flex flex-wrap items-baseline justify-between gap-2">
        <div className="section-title plain">{myTurn ? 'Your turn' : onTurn ? `${onTurn.player_name} is on turn` : 'Waiting'}</div>
        {turn && <Pools pools={turn.pools} />}
      </div>

      {turn && turn.played.length > 0 && (
        <div className="mb-2">
          <div className="label mb-1 text-cream-200/50">Played this turn</div>
          <div className="flex gap-1.5 overflow-x-auto pb-1">
            {turn.played.map((c) => (
              <Card key={c.key} card={c} size="sm" />
            ))}
          </div>
        </div>
      )}
      {turn && turn.bought.length > 0 && (
        <p className="mb-2 font-serif text-sm italic text-cream-200/70">Bought: {turn.bought.map((c) => c.name).join(', ')}</p>
      )}

      {you && (
        <div>
          <div className="label mb-1 text-cream-200/50">
            Your hand · {you.hand.length}
            {myTurn && you.hand.length > 0 && ' · click a card to play it'}
          </div>
          {you.hand.length === 0 ? (
            <p className="font-serif text-sm italic text-cream-200/50">{myTurn ? 'Every card is played.' : 'Empty.'}</p>
          ) : (
            <div className="flex gap-2 overflow-x-auto pb-1 pt-1">
              {you.hand.map((c) => (
                <Card
                  key={c.key}
                  card={c}
                  dim={!myTurn}
                  onClick={myTurn && !busy && !you.pending ? () => act('play', { card: c.key }) : undefined}
                />
              ))}
            </div>
          )}
          {you.held && you.held.length > 0 && (
            <p className="mt-1 font-serif text-sm italic text-gold-300">Scooped for your next hand: {you.held.map((c) => c.name).join(', ')}</p>
          )}
        </div>
      )}

      {myTurn && (
        <div className="mt-3 flex flex-wrap items-center gap-2 border-t border-gold-500/30 pt-2">
          <button type="button" className="btn" disabled={busy || !av.play_all} onClick={() => act('play_all')}>
            Play all
          </button>
          {paperLabel && (
            <button type="button" className="btn" disabled={busy || !av.paper} onClick={() => act('paper')}>
              {paperLabel}
            </button>
          )}
          <span className="flex-1" />
          <button type="button" className="btn-solid" disabled={busy || !av.end_turn} onClick={() => act('end_turn')}>
            End turn
          </button>
        </div>
      )}
    </section>
  );
}
