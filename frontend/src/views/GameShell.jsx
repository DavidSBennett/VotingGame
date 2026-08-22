import { useState } from 'react';
import { usePolledState } from '../hooks/usePolledState.js';
import { startGame, playAction, downloadExport } from '../api/client.js';
import EventLog from '../components/EventLog.jsx';
import PlaytestReportModal from '../components/PlaytestReportModal.jsx';

/**
 * The game screen. Deliberately thin: it renders whatever public state the
 * server sent and offers exactly the actions the server listed in
 * available_actions. It computes nothing about the rules.
 *
 * The board area is a placeholder — it prints the raw state JSON — until
 * there are rules to draw.
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
  const isHost = seat.seat === 0;
  const yourTurn = state.current_seat === null || state.current_seat === seat.seat;
  const actions = state.available_actions || [];

  return (
    <div className="mx-auto max-w-5xl px-4 py-6">
      <header className="mb-6 flex flex-wrap items-baseline justify-between gap-3">
        <div>
          <h1 className="text-2xl font-semibold text-slate-100">
            Table <span className="font-mono tracking-widest text-amber-400">{state.join_code}</span>
          </h1>
          <p className="text-sm text-slate-400">
            {state.status === 'lobby' && 'Waiting for players'}
            {state.status === 'active' &&
              `Round ${state.round_number}${state.total_rounds ? ` of ${state.total_rounds}` : ''} · ${state.phase}`}
            {state.status === 'ended' && `Ended — ${state.ended_reason}`}
            {' · '}
            <span className="font-mono text-xs text-slate-500">v{state.state_version}</span>
          </p>
        </div>
        <div className="flex gap-2">
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
      {message && (
        <div className="mb-4 rounded border border-slate-700 bg-slate-800 px-3 py-2 text-sm text-slate-300">
          {message}
        </div>
      )}

      <div className="grid gap-6 md:grid-cols-3">
        <section className="md:col-span-2">
          <h2 className="mb-2 text-sm uppercase tracking-wide text-slate-400">Seats</h2>
          <ul className="mb-6 space-y-1">
            {state.players.map((p) => (
              <li
                key={p.seat}
                className={
                  state.current_seat === p.seat
                    ? 'rounded border border-amber-600 bg-slate-800 px-3 py-2'
                    : 'rounded border border-slate-700 bg-slate-800 px-3 py-2'
                }
              >
                <span className="font-medium text-slate-100">{p.player_name}</span>
                {p.is_you && <span className="ml-2 text-xs text-amber-400">you</span>}
                {p.conceded && <span className="ml-2 text-xs text-slate-500">left</span>}
                {state.winner_seat === p.seat && (
                  <span className="ml-2 text-xs text-emerald-400">winner</span>
                )}
                <span className="float-right font-mono text-slate-300">
                  {p.final_score !== null && p.final_score !== undefined ? p.final_score : p.score}
                </span>
              </li>
            ))}
          </ul>

          {state.status === 'lobby' && (
            <div className="rounded border border-slate-700 bg-slate-800 p-4">
              <p className="text-sm text-slate-300">
                Share the code <span className="font-mono text-amber-400">{state.join_code}</span> to
                fill the table.
              </p>
              {isHost && (
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

          {state.status === 'active' && (
            <div className="rounded border border-slate-700 bg-slate-800 p-4">
              <h2 className="mb-2 text-sm uppercase tracking-wide text-slate-400">Your move</h2>
              <p className="mb-3 text-sm text-slate-400">
                {yourTurn ? 'It is your turn.' : 'Waiting for another player.'}
              </p>
              <div className="flex flex-wrap gap-2">
                {actions.map((a) => (
                  <button
                    key={a}
                    type="button"
                    onClick={() => act(a, {})}
                    disabled={busy}
                    className="rounded border border-slate-600 px-3 py-1.5 text-sm text-slate-200 hover:border-amber-500 disabled:opacity-50"
                  >
                    {a}
                  </button>
                ))}
                {actions.length === 0 && (
                  <span className="text-sm text-slate-500">Nothing to do right now.</span>
                )}
              </div>
            </div>
          )}

          {/* Placeholder board. Replace once there is something to draw. */}
          <details className="mt-6 rounded border border-slate-700 bg-slate-800 p-4">
            <summary className="cursor-pointer text-sm text-slate-400">Raw state (scaffold)</summary>
            <pre className="mt-3 overflow-x-auto text-xs text-slate-400">
              {JSON.stringify({ board: state.board, you: state.you, me }, null, 2)}
            </pre>
          </details>
        </section>

        <aside>
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
