import { useState } from 'react';
import { startGame, playAction, downloadExport } from '../api/client.js';
import EventLog from '../components/EventLog.jsx';
import Collapsible from '../components/Collapsible.jsx';
import PlaytestReportModal from '../components/PlaytestReportModal.jsx';
import ElectionPanel from '../components/dc/ElectionPanel.jsx';
import ElectionStrip from '../components/dc/ElectionStrip.jsx';
import ExchangeRow from '../components/dc/ExchangeRow.jsx';
import TurnArea from '../components/dc/TurnArea.jsx';
import PromptModal from '../components/dc/PromptModal.jsx';
import PapersPanel from '../components/dc/PapersPanel.jsx';
import FinalScores from '../components/dc/FinalScores.jsx';
import RulesDc from '../components/dc/RulesDc.jsx';
import CardModal from '../components/dc/CardModal.jsx';

/**
 * The game screen for the DC-style game (backend/engine_dc.php): the
 * election in progress and the 1796-1860 strip, the exchange, your turn
 * (pools, played cards, hand, buttons), and at the side every paper and
 * the wire.
 *
 * Presentation only: it renders the state the server sent and offers the
 * actions the server lists in available_actions. GameShell polls and
 * routes here when state.engine is 'dc'.
 */
export default function DcShell({ seat, state, events, error, refresh, onLeave }) {
  const [busy, setBusy] = useState(false);
  const [actionError, setActionError] = useState(null);
  const [rulesOpen, setRulesOpen] = useState(false);
  const [reportOpen, setReportOpen] = useState(false);
  // The open card: a row of cards, which one, and where they came from.
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
  const globe = me && me.paper && me.paper.key === 'globe';

  // What the open card can do right now -- only what the server lists.
  const modalCard = modal ? modal.cards[modal.index] : null;
  let modalActions = null;
  if (modalCard && myTurn && !(state.you && state.you.pending)) {
    if (modal.source === 'hand' && (av.play || []).includes(modalCard.key)) {
      modalActions = (
        <button type="button" className="btn-solid" disabled={busy} onClick={() => { setModal(null); act('play', { card: modalCard.key }); }}>
          Play this story
        </button>
      );
    } else if (modal.source === 'exchange' && (av.buy || []).includes(modalCard.key)) {
      modalActions = (
        <button type="button" className="btn-solid" disabled={busy} onClick={() => { setModal(null); act('buy', { card: modalCard.key }); }}>
          Buy for ◆{modalCard.cost}
        </button>
      );
    } else if (modal.source === 'exchange') {
      modalActions = <span className="font-serif text-sm italic text-ink-700">Not enough influence to buy this yet.</span>;
    }
  }

  return (
    <div className="flex min-h-full flex-col">
      <header className="shrink-0 border-b border-gold-500/40 bg-ink-900/90 backdrop-blur">
        <div className="mx-auto flex max-w-[1500px] flex-wrap items-center gap-x-6 gap-y-1 px-4 py-1.5">
          <h1 className="font-display text-2xl font-bold leading-none text-cream-50">The Fourth Estate</h1>
          <div className="flex flex-1 flex-wrap items-center gap-x-5 gap-y-1 font-mono text-[10px] uppercase tracking-[0.18em] text-cream-200/70">
            {active && state.election && (
              <span>
                Election <span className="text-cream-50">{state.election.index + 1}</span>/17 · {state.election.year}
              </span>
            )}
            {active && <span>Round {state.round}</span>}
            {me && (
              <span>
                Your prestige <span className="text-gold-300">★{me.prestige}</span>
              </span>
            )}
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
              Share the code <span className="font-mono tracking-[0.3em] text-gold-300">{state.join_code}</span> to seat the other papers.
            </p>
            <ul className="mx-auto mt-3 max-w-md text-left">
              {state.players.map((p) => (
                <li key={p.seat} className="font-serif text-cream-100">
                  {p.player_name}
                  {p.is_bot ? ' (rival)' : ''} — <span className="italic text-gold-400">{p.paper ? p.paper.name : 'a paper dealt at the start'}</span>
                </li>
              ))}
            </ul>
            {seat.seat === 0 && (
              <button type="button" onClick={doStart} disabled={busy || state.players.length < 2} className="btn-solid mt-5">
                Start the presses
              </button>
            )}
          </section>
        )}

        {(active || ended) && (
          <Collapsible
            title="1796 – 1860"
            storageKey="dc-strip"
            summary={`${state.history.length} of 17 decided · history rewritten ${state.history.filter((h) => !h.matched_history).length}`}
          >
            <ElectionStrip
              elections={state.elections}
              history={state.history}
              current={active && state.election ? state.election.index : null}
              players={state.players}
            />
          </Collapsible>
        )}

        {ended && <FinalScores state={state} />}

        {/* Three columns on a desktop: the exchange at the left, the table
            (election, the press, your hand) in the middle, the papers and
            the wire at the right. Stacked on a phone. */}
        <div className="grid grid-cols-[minmax(0,1fr)] gap-3 lg:grid-cols-[16.5rem_minmax(0,1fr)_18rem]">
          <div className="min-w-0">
            {active && (
              <ExchangeRow
                exchange={state.exchange}
                editorial={state.editorial}
                mainCount={state.main_count}
                scandalsLeft={state.scandals_left}
                canBuy={av.buy || []}
                onBuy={(card) => act('buy', { card })}
                busy={busy}
                myTurn={myTurn}
                news={state.news}
                showNew={state.election && state.election.index > 0}
                open={open}
              />
            )}
          </div>
          <div className="flex min-w-0 flex-col gap-2">
            {active && (
              <>
                <ElectionPanel
                  election={state.election}
                  pools={state.turn ? state.turn.pools : null}
                  myTurn={myTurn}
                  canElect={av.elect || []}
                  onElect={(side) => act('elect', { side })}
                  busy={busy}
                  globe={globe}
                  lastFa={state.last_fa}
                  open={open}
                />
                <TurnArea state={state} me={me} act={act} busy={busy} open={open} />
              </>
            )}
          </div>

          <aside className="flex min-w-0 flex-col gap-2">
            {(active || ended) && (
              <Collapsible title="The papers" storageKey="dc-papers" summary={state.players.map((p) => `${p.is_you ? 'You' : p.player_name.replace(/^The /, '')} ★${p.prestige}`).join(' · ')}>
                <PapersPanel players={state.players} botLevel={state.rules.bot_level} />
              </Collapsible>
            )}
            {state.you && (active || ended) && (
              <Collapsible title="Your deck" storageKey="dc-deck" summary={`deck ${state.you.deck.length} · discard ${state.you.discard.length}`} defaultOpen={false}>
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

      {myTurn && state.you && state.you.pending && <PromptModal pending={state.you.pending} act={act} busy={busy} />}
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
      {rulesOpen && <RulesDc onClose={() => setRulesOpen(false)} />}
      {reportOpen && <PlaytestReportModal playerToken={seat.player_token} onClose={() => setReportOpen(false)} />}
    </div>
  );
}
