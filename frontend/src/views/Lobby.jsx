import { useEffect, useState } from 'react';
import { createGame, joinGame, listOpenGames } from '../api/client.js';
import HighScores from '../components/HighScores.jsx';

/**
 * Lobby: name yourself, then open a table or take a seat at one.
 *
 * A table has a 4-character join code so a player at the same table can
 * join from their own phone without being sent a link.
 */
export default function Lobby({ onSeated }) {
  const [playerName, setPlayerName] = useState(
    () => localStorage.getItem('votinggame.name') || ''
  );
  const [joinCode, setJoinCode] = useState('');
  const [games, setGames] = useState([]);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState(null);

  const refreshGames = async () => {
    try {
      const data = await listOpenGames(true);
      setGames(data.games || []);
    } catch (err) {
      setError(err.message);
    }
  };

  useEffect(() => {
    refreshGames();
    const timer = setInterval(refreshGames, 5000);
    return () => clearInterval(timer);
  }, []);

  const rememberName = (name) => {
    setPlayerName(name);
    try {
      localStorage.setItem('votinggame.name', name);
    } catch {
      /* private browsing */
    }
  };

  const guard = () => {
    if (!playerName.trim()) {
      setError('Enter a name first.');
      return false;
    }
    return true;
  };

  const doCreate = async () => {
    if (!guard()) return;
    setBusy(true);
    setError(null);
    try {
      const data = await createGame({ player_name: playerName.trim() });
      onSeated({
        game_id: data.game_id,
        join_code: data.join_code,
        player_token: data.player_token,
        seat: data.seat,
        player_name: playerName.trim(),
      });
    } catch (err) {
      setError(err.message);
    } finally {
      setBusy(false);
    }
  };

  const doJoin = async (code) => {
    if (!guard()) return;
    setBusy(true);
    setError(null);
    try {
      const data = await joinGame({
        player_name: playerName.trim(),
        join_code: code.trim().toUpperCase(),
      });
      onSeated({
        game_id: data.game_id,
        join_code: data.join_code,
        player_token: data.player_token,
        seat: data.seat,
        player_name: playerName.trim(),
      });
    } catch (err) {
      setError(err.message);
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="mx-auto max-w-4xl px-4 py-8">
      <header className="mb-8">
        <h1 className="text-3xl font-semibold tracking-tight text-slate-100">VotingGame</h1>
        <p className="mt-1 text-sm text-slate-400">
          Scaffold build — the rules engine is a stub until the design lands.
        </p>
      </header>

      {error && (
        <div className="mb-4 rounded border border-red-800 bg-red-950 px-3 py-2 text-sm text-red-200">
          {error}
        </div>
      )}

      <section className="mb-8 rounded-lg border border-slate-700 bg-slate-800 p-5 shadow-panel">
        <label className="block text-xs uppercase tracking-wide text-slate-400" htmlFor="name">
          Your name
        </label>
        <input
          id="name"
          value={playerName}
          onChange={(e) => rememberName(e.target.value)}
          maxLength={40}
          placeholder="Name at the table"
          className="mt-1 w-full rounded border border-slate-600 bg-slate-900 px-3 py-2 text-slate-100 outline-none focus:border-amber-500"
        />

        <div className="mt-4 flex flex-wrap items-center gap-3">
          <button
            type="button"
            onClick={doCreate}
            disabled={busy}
            className="rounded bg-amber-600 px-4 py-2 font-medium text-slate-950 hover:bg-amber-500 disabled:opacity-50"
          >
            Open a table
          </button>

          <span className="text-slate-500">or</span>

          <input
            value={joinCode}
            onChange={(e) => setJoinCode(e.target.value.toUpperCase())}
            maxLength={8}
            placeholder="CODE"
            className="w-28 rounded border border-slate-600 bg-slate-900 px-3 py-2 font-mono uppercase tracking-widest text-slate-100 outline-none focus:border-amber-500"
          />
          <button
            type="button"
            onClick={() => doJoin(joinCode)}
            disabled={busy || !joinCode.trim()}
            className="rounded border border-slate-600 px-4 py-2 font-medium text-slate-200 hover:border-amber-500 disabled:opacity-50"
          >
            Join
          </button>
        </div>
      </section>

      <section className="mb-8">
        <h2 className="mb-3 text-lg font-semibold text-slate-100">Tables</h2>
        {games.length === 0 ? (
          <p className="text-sm text-slate-500">No tables yet. Open one.</p>
        ) : (
          <ul className="space-y-2">
            {games.map((g) => (
              <li
                key={g.game_id}
                className="flex items-center justify-between rounded border border-slate-700 bg-slate-800 px-4 py-3"
              >
                <div>
                  <span className="font-mono text-lg tracking-widest text-amber-400">
                    {g.join_code}
                  </span>
                  <span className="ml-3 text-sm text-slate-300">
                    {g.seated}/{g.max_players} seated
                  </span>
                  <span className="ml-3 text-xs uppercase tracking-wide text-slate-500">
                    {g.status}
                    {g.status === 'active' ? ` · round ${g.round_number}` : ''}
                  </span>
                  {g.players.length > 0 && (
                    <div className="mt-1 text-xs text-slate-400">{g.players.join(', ')}</div>
                  )}
                </div>
                <button
                  type="button"
                  onClick={() => doJoin(g.join_code)}
                  disabled={busy || !g.joinable}
                  className="rounded border border-slate-600 px-3 py-1.5 text-sm text-slate-200 hover:border-amber-500 disabled:opacity-40"
                >
                  {g.joinable ? 'Take a seat' : 'In progress'}
                </button>
              </li>
            ))}
          </ul>
        )}
      </section>

      <HighScores />
    </div>
  );
}
