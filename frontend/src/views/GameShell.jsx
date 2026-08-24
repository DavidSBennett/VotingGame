import { useState } from 'react';
import { usePolledState } from '../hooks/usePolledState.js';
import { startGame, playAction, downloadExport } from '../api/client.js';
import EventLog from '../components/EventLog.jsx';
import PlaytestReportModal from '../components/PlaytestReportModal.jsx';
import IssueTracks from '../components/IssueTracks.jsx';
import RacePanel from '../components/RacePanel.jsx';
import Hand from '../components/Hand.jsx';
import BoardStrip from '../components/BoardStrip.jsx';

/**
 * The game screen.
 *
 * Presentation only: it renders the public state the server sent and offers
 * exactly the actions the server marked legal. It computes nothing about
 * the rules — no alignment maths, no affordability checks beyond echoing
 * the server flags — so it can never disagree with the engine about what is
 * allowed, only about how recently it asked.
 */
export default function GameShell({ seat, onLeave }) {
  const { state, events, error, loading, refresh } = usePolledState({
    playerToken: seat.player_token,
  });
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState(null);
  const [actionError, setActionError] = useState(null);
  const [reportOpen, setReportOpen] = useState(false);

  const act = async (action, params) => {
    setBusy(true);
    setActionError(null);
    try {
      const data = await playAction(seat.player_token, action, params);
      setMessage(data.message);
      await refresh();
    } catch (err) {
      setActionError(err.message);
    } finally {
      setBusy(false);
    }
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

  if (loading && !state) {
    return <div className="p-8 text-slate-400">Loading the table…</div>;
  }
  if (!state) {
    return (
      <div className="p-8">
        <p className="text-red-300">{error || 'That seat is no longer valid.'}</p>
        <button
          type="button"
          onClick={onLeave}
          className="mt-4 rounded border border-slate-600 px-4 py-2 text-slate-200 hover:border-amber-500"
        >
          Back to the lobby
        </button>
      </div>
    );
  }

  const me = state.players.find((p) => p.is_you);
  const yourTurn = state.current_seat === null || state.current_seat === seat.seat;
  const ended = state.status === 'ended';
  const stabilityPct = state.stability_max
    ? (state.stability / state.stability_max) * 100
    : 0;

  return (
    <div className="mx-auto max-w-6xl px-4 py-6">
      <header className="mb-5 flex flex-wrap items-baseline justify-between gap-3">
        <div>
          <h1 className="text-2xl font-semibold text-slate-100">
            {state.race ? `The campaign of ${state.race.year}` : 'The Fourth Estate'}
            <span className="ml-3 font-mono text-sm tracking-widest text-amber-400">
              {state.join_code}
            </span>
          </h1>
          <p className="text-sm text-slate-400">
            {state.status === 'lobby' && 'Waiting for the other papers'}
            {state.status === 'active' &&
              `Space ${state.space} of ${state.total_spaces}`}
            {ended && `Ended — ${state.ended_reason}`}
            {' · '}
            <span className="font-mono text-xs text-slate-600">v{state.state_version}</span>
          </p>
        </div>
        <div className="flex flex-wrap gap-2">
          <button
            type="button"
            onClick={() => setReportOpen(true)}
            className="rounded border border-slate-600 px-3 py-1.5 text-sm text-slate-200 hover:border-amber-500"
          >
            Playtest note
          </button>
          <button
            type="button"
            onClick={() => downloadExport(seat.player_token, state.game_id)}
            className="rounded border border-slate-600 px-3 py-1.5 text-sm text-slate-200 hover:border-amber-500"
          >
            Download playthrough
          </button>
          <button
            type="button"
            onClick={onLeave}
            className="rounded border border-slate-600 px-3 py-1.5 text-sm text-slate-400 hover:border-red-500"
          >
            Leave
          </button>
        </div>
      </header>

      {(actionError || error) && (
        <div className="mb-4 rounded border border-red-800 bg-red-950 px-3 py-2 text-sm text-red-200">
          {actionError || error}
        </div>
      )}
      {message && !actionError && (
        <div className="mb-4 rounded border border-slate-700 bg-slate-800 px-3 py-2 text-sm text-slate-300">
          {message}
        </div>
      )}

      {ended && (
        <section className="mb-5 rounded-lg border border-amber-700 bg-slate-800 p-5">
          <h2 className="text-lg font-semibold text-amber-300">
            {state.ended_reason === 'the_union_breaks'
              ? 'The Union breaks.'
              : state.ended_reason === 'board_completed'
                ? 'It is 1860.'
                : 'The game is over.'}
          </h2>
          <p className="mt-1 text-sm text-slate-400">
            Wealth is counted where it stands.
          </p>
          <ol className="mt-3 space-y-1">
            {[...state.players]
              .sort((a, b) => (b.final_score ?? 0) - (a.final_score ?? 0))
              .map((p, i) => (
                <li key={p.seat} className="flex items-baseline justify-between gap-3">
                  <span className="text-slate-200">
                    <span className="mr-2 font-mono text-slate-500">{i + 1}.</span>
                    {p.player_name}
                    {p.is_you && <span className="ml-2 text-xs text-amber-400">you</span>}
                    <span className="ml-2 text-xs text-slate-500">
                      {p.presidencies} president{p.presidencies === 1 ? '' : 's'}
                    </span>
                  </span>
                  <span className="font-mono text-lg text-slate-100">{p.final_score}</span>
                </li>
              ))}
          </ol>
        </section>
      )}

      <div className="grid gap-5 lg:grid-cols-3">
        <div className="space-y-5 lg:col-span-2">
          <BoardStrip
            space={state.space}
            totalSpaces={state.total_spaces}
            history={state.history}
          />

          {state.status === 'active' && (
            <>
              <IssueTracks
                tracks={state.tracks}
                min={state.config.track_min}
                max={state.config.track_max}
              />
              <RacePanel race={state.race} seats={state.players} mySeat={seat.seat} />
              <Hand
                hand={state.you ? state.you.hand : []}
                race={state.race}
                money={me ? me.money : 0}
                yourTurn={yourTurn}
                busy={busy}
                onPlay={act}
              />
            </>
          )}

          {state.status === 'lobby' && (
            <div className="rounded-lg border border-slate-700 bg-slate-800 p-4">
              <p className="text-sm text-slate-300">
                Share the code{' '}
                <span className="font-mono text-amber-400">{state.join_code}</span> to fill
                the table.
              </p>
              {seat.seat === 0 && (
                <button
                  type="button"
                  onClick={doStart}
                  disabled={busy || state.players.length < 2}
                  className="mt-3 rounded bg-amber-600 px-4 py-2 font-medium text-slate-950 hover:bg-amber-500 disabled:opacity-50"
                >
                  Start the game
                </button>
              )}
            </div>
          )}
        </div>

        <aside className="space-y-5">
          <section className="rounded-lg border border-slate-700 bg-slate-800 p-4">
            <div className="mb-1 flex items-baseline justify-between">
              <h2 className="text-xs uppercase tracking-widest text-slate-400">
                Stability of the Union
              </h2>
              <span className="font-mono text-sm text-slate-300">
                {state.stability}/{state.stability_max}
              </span>
            </div>
            <div className="h-2 overflow-hidden rounded bg-slate-900">
              <div
                className={
                  stabilityPct > 50
                    ? 'h-2 bg-emerald-500'
                    : stabilityPct > 25
                      ? 'h-2 bg-amber-500'
                      : 'h-2 bg-red-500'
                }
                style={{ width: `${Math.max(0, stabilityPct)}%` }}
              />
            </div>
            {stabilityPct <= 25 && (
              <p className="mt-2 text-xs text-red-300">The Union is fraying badly.</p>
            )}
          </section>

          {state.president && (
            <section className="rounded-lg border border-slate-700 bg-slate-800 p-4">
              <h2 className="mb-1 text-xs uppercase tracking-widest text-slate-400">
                In office
              </h2>
              <div className="text-slate-100">{state.president.name}</div>
              <div className="text-xs text-slate-500">
                elected {state.president.year}
                {state.president.controller_seat !== null
                  ? ` · ${
                      state.players.find(
                        (p) => p.seat === state.president.controller_seat
                      )?.player_name || 'a rival'
                    } owns the administration`
                  : ' · no paper owns him'}
              </div>
            </section>
          )}

          <section className="rounded-lg border border-slate-700 bg-slate-800 p-4">
            <h2 className="mb-2 text-xs uppercase tracking-widest text-slate-400">
              The presses
            </h2>
            <ul className="space-y-1">
              {state.players.map((p) => (
                <li
                  key={p.seat}
                  className={
                    state.current_seat === p.seat
                      ? 'rounded border border-amber-600 bg-slate-900 px-2 py-1.5'
                      : 'rounded border border-slate-700 bg-slate-900 px-2 py-1.5'
                  }
                >
                  <div className="flex items-baseline justify-between gap-2">
                    <span className="text-sm text-slate-100">
                      {p.player_name}
                      {p.is_you && <span className="ml-1 text-xs text-amber-400">you</span>}
                      {p.is_bot && <span className="ml-1 text-xs text-slate-600">bot</span>}
                    </span>
                    <span className="font-mono text-sm text-emerald-400">{p.money}</span>
                  </div>
                  <div className="text-xs text-slate-500">
                    {p.controls_president && (
                      <span className="text-amber-400">holds the administration · </span>
                    )}
                    {p.hand_count} cards · {p.presidencies} won
                    {p.conceded && <span className="text-slate-600"> · left</span>}
                  </div>
                </li>
              ))}
            </ul>
          </section>

          <EventLog events={events} />
        </aside>
      </div>

      {reportOpen && (
        <PlaytestReportModal
          playerToken={seat.player_token}
          onClose={() => setReportOpen(false)}
        />
      )}
    </div>
  );
}
