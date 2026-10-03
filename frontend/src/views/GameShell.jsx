import { usePolledState } from '../hooks/usePolledState.js';
import { downloadExport } from '../api/client.js';
import DcShell from './DcShell.jsx';
import Shell2024 from './Shell2024.jsx';

/**
 * The seat's screen: polls the public state and hands it to Shell2024, the
 * screen of the 2024 game (backend/engine_2024.php), or to DcShell for a
 * table started under the DC-style game (backend/engine_dc.php).
 *
 * A table started under the older newsroom rules (backend/engine.php)
 * still exists on the server and can be exported, but this site no longer
 * draws its board (VARIANT.md, milestone 6).
 */
export default function GameShell({ seat, onLeave }) {
  const { state, events, error, loading, refresh } = usePolledState({
    playerToken: seat.player_token,
  });

  if (loading && !state) {
    return (
      <div className="flex min-h-full items-center justify-center">
        <p className="font-display text-2xl italic text-cream-200/70">Setting the type…</p>
      </div>
    );
  }
  if (!state) {
    return (
      <div className="flex min-h-full flex-col items-center justify-center gap-4 p-8">
        <p className="font-serif italic text-oxblood-300">{error || 'That seat is no longer valid.'}</p>
        <button type="button" onClick={onLeave} className="btn">
          Back to the lobby
        </button>
      </div>
    );
  }

  if (state.engine === '2024') {
    return <Shell2024 seat={seat} state={state} events={events} error={error} refresh={refresh} onLeave={onLeave} />;
  }

  // The DC game's state says engine 'dc' and carries an `election`
  // (the older game's has `race` instead).
  if (state.engine === 'dc' || 'election' in state) {
    return <DcShell seat={seat} state={state} events={events} error={error} refresh={refresh} onLeave={onLeave} />;
  }

  return (
    <div className="flex min-h-full flex-col items-center justify-center gap-4 p-8 text-center">
      <p className="font-display text-3xl text-cream-50">This table plays the older rules</p>
      <p className="max-w-lg font-serif italic text-cream-200/70">
        It was started under the earlier newsroom game, which this site no longer shows. You can still download its playthrough, or return
        to the lobby and open a new table.
      </p>
      <div className="flex gap-2">
        <button type="button" onClick={() => downloadExport(seat.player_token, state.game_id)} className="btn">
          Export
        </button>
        <button type="button" onClick={onLeave} className="btn">
          Back to the lobby
        </button>
      </div>
    </div>
  );
}
