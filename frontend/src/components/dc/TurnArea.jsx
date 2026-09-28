import Card from './Card.jsx';
import HandFan from './HandFan.jsx';

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
 * Your turn: the pools, what has been played, your hand as a centered fan
 * (click a card to open it; Play is in the card), and the turn's buttons.
 * On a rival's turn: what they have played so far.
 *
 *   open(cards, index, source)   opens the CardModal on a row of cards
 */
export default function TurnArea({ state, me, act, busy, open }) {
  const turn = state.turn;
  const you = state.you;
  const av = state.available_actions || {};
  const myTurn = turn && you && turn.seat === you.seat;
  const onTurn = state.players.find((p) => p.on_turn);
  const paper = me && me.paper;
  const paperLabel =
    paper && paper.key === 'sun' ? 'The Sun: discard a Scandal, draw 3' : paper && paper.key === 'herald' ? 'The Herald: scoop (2)' : null;

  return (
    <section className={myTurn ? 'panel border-gold-300 px-3 py-2 shadow-glow' : 'panel px-3 py-2'}>
      <div className="text-center">
        <div className="section-title">{myTurn ? 'Your turn' : onTurn ? `${onTurn.player_name} is on turn` : 'Waiting'}</div>
        {turn && (
          <div className="mt-1">
            <Pools pools={turn.pools} />
          </div>
        )}
      </div>

      {turn && turn.played.length > 0 && (
        <div className="mt-2">
          <div className="label mb-1 text-center text-cream-200/50">Played this turn</div>
          <div className="flex flex-wrap justify-center gap-1.5">
            {turn.played.map((c, i) => (
              <Card key={c.key} card={c} size="xs" onOpen={() => open(turn.played, i, 'played')} />
            ))}
          </div>
        </div>
      )}
      {turn && turn.bought.length > 0 && (
        <p className="mt-2 text-center font-serif text-sm italic text-cream-200/70">Bought: {turn.bought.map((c) => c.name).join(', ')}</p>
      )}

      {you && (
        <div className="mt-2">
          <div className="label text-center text-cream-200/50">
            Your hand · {you.hand.length}
            {you.hand.length > 0 && ' · click a card to open it'}
          </div>
          {you.hand.length === 0 ? (
            <p className="mt-2 text-center font-serif text-sm italic text-cream-200/50">{myTurn ? 'Every card is played.' : 'Empty.'}</p>
          ) : (
            <HandFan>
              {you.hand.map((c, i) => (
                <Card key={c.key} card={c} dim={!myTurn} onOpen={() => open(you.hand, i, 'hand')} />
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
    </section>
  );
}
