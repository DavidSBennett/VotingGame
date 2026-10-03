import { useState } from 'react';
import { startGame, playAction, downloadExport } from '../api/client.js';
import EventLog from '../components/EventLog.jsx';
import Collapsible from '../components/Collapsible.jsx';
import PlaytestReportModal from '../components/PlaytestReportModal.jsx';
import PromptModal from '../components/dc/PromptModal.jsx';
import CardModal from '../components/dc/CardModal.jsx';
import RaceBar from '../components/e24/RaceBar.jsx';
import MapGrid from '../components/e24/MapGrid.jsx';
import BigState from '../components/e24/BigState.jsx';
import Exchange24 from '../components/e24/Exchange24.jsx';
import Turn24 from '../components/e24/Turn24.jsx';
import Outlets, { StakePile } from '../components/e24/Outlets.jsx';
import FinalScores24 from '../components/e24/FinalScores24.jsx';
import Rules24 from '../components/e24/Rules24.jsx';

/**
 * The game screen for the 2024 game (backend/engine_2024.php): the race to
 * 270 and the map, the exchange, the big state up, your turn (currencies,
 * the press, your hand, Stake), and at the side every outlet, your stake
 * pile and the wire.
 *
 * Presentation only: it renders the state the server sent and offers the
 * actions the server lists in available_actions. GameShell routes here when
 * state.engine is '2024'.
 */
export default function Shell2024({ seat, state, events, error, refresh, onLeave }) {
  const [busy, setBusy] = useState(false);
  const [actionError, setActionError] = useState(null);
  const [rulesOpen, setRulesOpen] = useState(false);
  const [reportOpen, setReportOpen] = useState(false);
  const [modal, setModal] = useState(null);
  const open = (cards, index, source) => setModal({ cards, index, source });

  const act = async (action, params) => {
    setBusy(true);
    setActionError(null);
    try {
      await playAction(seat.player_token, action, params);
      await refresh();
    } catch (err) {
      setActionError(err.message);
    } finally {
      setBusy(false);
    }
  };

  const leave = async () => {
    const still = state.status === 'active' && !state.players.find((p) => p.is_you)?.conceded;
    if (still) {
      if (!window.confirm('Leaving concedes this game. Leave anyway?')) return;
      try {
        await playAction(seat.player_token, 'concede');
      } catch {
        /* leave regardless */
      }
    }
    onLeave();
  };

  const doStart = async () => {
    setBusy(true);
    setActionError(null);
    try {
      await startGame(seat.player_token);
      await refresh();
    } catch (err) {
      setActionError(err.message);
    } finally {
      setBusy(false);
    }
  };

  const active = state.status === 'active';
  const ended = state.status === 'ended';
  const me = state.players.find((p) => p.is_you);
  const av = state.available_actions || {};
  const myTurn = active && state.turn && state.you && state.turn.seat === state.you.seat;
  const pending = state.you && state.you.pending;
  const globe = me && me.paper && me.paper.key === 'globe';
  const buy = (card, side) => act('buy', side ? { card, side } : { card });

  // What the open card can do right now -- only what the server lists.
  const modalCard = modal ? modal.cards[modal.index] : null;
  let modalActions = null;
  if (modalCard && myTurn && !pending) {
    if (modal.source === 'hand' && (av.play || []).includes(modalCard.key)) {
      modalActions = (
        <button type="button" className="btn-solid" disabled={busy} onClick={() => { setModal(null); act('play', { card: modalCard.key }); }}>
          Use this card's ability
        </button>
      );
    } else if (modal.source === 'exchange' && modalCard.type === 'State') {
      const sides = ['trump', 'harris'].filter((s) => (av.buy || []).includes(`${modalCard.key}:${s}`));
      modalActions = sides.length ? (
        <div className="flex gap-2">
          {sides.map((s) => (
            <button key={s} type="button" className="btn-solid" disabled={busy} onClick={() => { setModal(null); buy(modalCard.key, s); }}>
              Buy for {s === 'trump' ? 'Trump' : 'Harris'} ◆{modalCard.sides[s].cost}
            </button>
          ))}
        </div>
      ) : (
        <span className="font-serif text-sm italic text-ink-700">Not enough currency to buy this state yet.</span>
      );
    } else if (modal.source === 'exchange' && (av.buy || []).includes(modalCard.key)) {
      modalActions = (
        <button type="button" className="btn-solid" disabled={busy} onClick={() => { setModal(null); buy(modalCard.key); }}>
          Buy for ◆{modalCard.cost}
        </button>
      );
    } else if (modal.source === 'exchange') {
      modalActions = <span className="font-serif text-sm italic text-ink-700">Not enough currency to buy this yet.</span>;
    } else if (modal.source === 'big' && modalCard.side && (av.call || []).includes(modalCard.side)) {
      modalActions = (
        <button type="button" className="btn-solid" disabled={busy} onClick={() => { setModal(null); act('call', { side: modalCard.side }); }}>
          Call it for {modalCard.side === 'trump' ? 'Trump' : 'Harris'}
        </button>
      );
    }
  }

  return (
    <div className="flex min-h-full flex-col">
      <header className="shrink-0 border-b border-gold-500/40 bg-ink-900/90 backdrop-blur">
        <div className="mx-auto flex max-w-[1500px] flex-wrap items-center gap-x-6 gap-y-1 px-4 py-1.5">
          <h1 className="font-display text-2xl font-bold leading-none text-cream-50">The Fourth Estate</h1>
          <div className="flex flex-1 flex-wrap items-center gap-x-5 gap-y-1 font-mono text-[10px] uppercase tracking-[0.18em] text-cream-200/70">
            {(active || ended) && state.race && (
              <span>
                <span className="text-oxblood-300">Trump {state.race.trump}</span> · <span className="text-federal-300">Harris {state.race.harris}</span> · 270 to win
              </span>
            )}
            {active && <span>Round {state.round}</span>}
            {active && state.big && <span>Big state {state.big.index + 1}/{state.big_total}</span>}
            {state.you && <span>You staked <span className="text-gold-300">{state.you.staked.length}</span></span>}
            {me && me.paper && <span className="hidden text-gold-400 md:inline">{me.paper.name}</span>}
          </div>
          <nav className="flex flex-wrap gap-1.5">
            <button type="button" onClick={() => setRulesOpen(true)} className="btn px-2 py-1">
              Rules
            </button>
            <button type="button" onClick={() => setReportOpen(true)} className="btn px-2 py-1">
              Note
            </button>
            <button type="button" onClick={() => downloadExport(seat.player_token, state.game_id)} className="btn px-2 py-1">
              Export
            </button>
            <button type="button" onClick={leave} className="btn px-2 py-1">
              {ended ? 'Lobby' : 'Leave'}
            </button>
          </nav>
        </div>
      </header>

      <main className="mx-auto flex w-full max-w-[1500px] flex-1 flex-col gap-2 px-4 py-2">
        {(actionError || error) && (
          <div className="border-l-2 border-oxblood-500 bg-oxblood-900/50 px-4 py-1.5 font-serif italic text-cream-100">{actionError || error}</div>
        )}

        {state.status === 'lobby' && (
          <section className="panel p-8 text-center">
            <div className="label">The table is filling</div>
            <p className="mt-3 font-serif text-lg text-cream-100">
              Share the code <span className="font-mono tracking-[0.3em] text-gold-300">{state.join_code}</span> to seat the other outlets.
            </p>
            <ul className="mx-auto mt-3 max-w-md text-left">
              {state.players.map((p) => (
                <li key={p.seat} className="font-serif text-cream-100">
                  {p.player_name}
                  {p.is_bot ? ' (rival)' : ''} — <span className="italic text-gold-400">{p.paper ? p.paper.name : 'an outlet dealt at the start'}</span>
                </li>
              ))}
            </ul>
            {seat.seat === 0 && (
              <button type="button" onClick={doStart} disabled={busy || state.players.length < 2} className="btn-solid mt-5">
                Open the newsroom
              </button>
            )}
          </section>
        )}

        {ended && <FinalScores24 state={state} />}

        {(active || ended) && (
          <>
            <RaceBar race={state.race} />
            <Collapsible
              title="The map"
              storageKey="e24-map"
              summary={`${state.map.filter((s) => s.side).length} of 51 claimed · ${state.big_called.length} of ${state.big_total} big states called`}
            >
              <MapGrid map={state.map} bigKey={active && state.big ? state.big.key : null} players={state.players} />
            </Collapsible>
          </>
        )}

        {/* Three columns on a desktop: the exchange at the left, the table (the
            big state, the press, your hand) in the middle, the outlets, your
            stakes and the wire at the right. Stacked on a phone. */}
        <div className="grid grid-cols-[minmax(0,1fr)] gap-3 lg:grid-cols-[16.5rem_minmax(0,1fr)_18rem]">
          <div className="min-w-0">
            {active && (
              <Exchange24
                exchange={state.exchange}
                editorial={state.editorial}
                mainCount={state.main_count}
                scandalsLeft={state.scandals_left}
                canBuy={av.buy || []}
                onBuy={buy}
                busy={busy}
                myTurn={myTurn}
                open={open}
              />
            )}
          </div>
          <div className="flex min-w-0 flex-col gap-2">
            {active && (
              <>
                <BigState
                  big={state.big}
                  bigTotal={state.big_total}
                  pools={state.turn ? state.turn.pools : null}
                  myTurn={myTurn}
                  canCall={av.call || []}
                  onCall={(side) => act('call', { side })}
                  busy={busy}
                  globe={globe}
                  open={open}
                />
                <Turn24 state={state} me={me} act={act} busy={busy} open={open} />
              </>
            )}
          </div>

          <aside className="flex min-w-0 flex-col gap-2">
            {(active || ended) && (
              <Collapsible title="The outlets" storageKey="e24-outlets" summary={state.players.map((p) => `${p.is_you ? 'You' : p.player_name} ${p.stakes} staked`).join(' · ')}>
                <Outlets players={state.players} botLevel={state.rules.bot_level} />
              </Collapsible>
            )}
            {state.you && (active || ended) && (
              <Collapsible title="Your stakes" storageKey="e24-stakes" summary={`${state.you.staked.length} staked, face down`}>
                <StakePile staked={state.you.staked} race={state.race} />
              </Collapsible>
            )}
            {state.you && (active || ended) && (
              <Collapsible title="Your deck" storageKey="e24-deck" summary={`deck ${state.you.deck.length} · discard ${state.you.discard.length}`} defaultOpen={false}>
                <p className="font-serif text-[12px] text-cream-200/80">
                  <span className="label mr-2">Deck</span>
                  {state.you.deck.map((c) => c.name).join(' · ') || '—'}
                </p>
                <p className="mt-1 font-serif text-[12px] text-cream-200/80">
                  <span className="label mr-2">Discard</span>
                  {state.you.discard.map((c) => c.name).join(' · ') || '—'}
                </p>
              </Collapsible>
            )}
            <Collapsible title="The wire" storageKey="wire" summary={events && events.length ? events[events.length - 1].message : null}>
              <EventLog events={events} bare />
            </Collapsible>
          </aside>
        </div>
      </main>

      {myTurn && pending && <PromptModal pending={pending} act={act} busy={busy} />}
      {modalCard && (
        <CardModal
          card={modalCard}
          onClose={() => setModal(null)}
          actions={modalActions}
          onPrev={modal.index > 0 ? () => setModal({ ...modal, index: modal.index - 1 }) : null}
          onNext={modal.index < modal.cards.length - 1 ? () => setModal({ ...modal, index: modal.index + 1 }) : null}
          position={{ current: modal.index + 1, total: modal.cards.length }}
        />
      )}
      {rulesOpen && <Rules24 onClose={() => setRulesOpen(false)} />}
      {reportOpen && <PlaytestReportModal playerToken={seat.player_token} onClose={() => setReportOpen(false)} />}
    </div>
  );
}
