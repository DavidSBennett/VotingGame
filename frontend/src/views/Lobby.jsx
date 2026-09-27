import { useEffect, useState } from 'react';
import { createGame, joinGame, listOpenGames } from '../api/client.js';
import HighScores from '../components/HighScores.jsx';
import Rules from '../components/Rules.jsx';

/**
 * The lobby, laid out as a title page: kicker, masthead, an italic line of
 * premise, then the business of the day -- open a table, take a seat, read
 * the rules, see the circulation board.
 *
 * A table has a 4-character join code so a player at the same table can
 * join from their own phone without being sent a link.
 */
export default function Lobby({ onSeated }) {
  const [playerName, setPlayerName] = useState(() => {
    try {
      return localStorage.getItem('votinggame.name') || '';
    } catch {
      return '';
    }
  });
  const [joinCode, setJoinCode] = useState('');
  const [rivals, setRivals] = useState(1);
  const [games, setGames] = useState([]);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState(null);
  const [showRules, setShowRules] = useState(false);

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
      setError('Sign your name first — every paper needs an editor.');
      return false;
    }
    return true;
  };

  const seated = (data) =>
    onSeated({
      game_id: data.game_id,
      join_code: data.join_code,
      player_token: data.player_token,
      seat: data.seat,
      player_name: playerName.trim(),
    });

  const doCreate = async () => {
    if (!guard()) return;
    setBusy(true);
    setError(null);
    try {
      seated(await createGame({ player_name: playerName.trim(), max_players: 1, bots: rivals }));
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
      seated(await joinGame({ player_name: playerName.trim(), join_code: code.trim().toUpperCase() }));
    } catch (err) {
      setError(err.message);
    } finally {
      setBusy(false);
    }
  };

  const openTables = games.filter((g) => g.joinable);

  return (
    <div className="mx-auto max-w-5xl px-4 pb-16 pt-10">
      {/* Title page */}
      <header className="text-center animate-fade">
        <div className="font-mono text-[10px] uppercase tracking-[0.4em] text-gold-500">
          A card game of the partisan press · 1796–1860
        </div>
        <h1 className="mt-3 font-display text-6xl font-bold leading-none text-cream-50 sm:text-7xl">The Fourth Estate</h1>
        <p className="mx-auto mt-4 max-w-2xl font-display text-xl italic text-gold-300">
          Print the stories, make the presidents, and sell the papers. Fourteen elections; the richest press wins.
        </p>
        <div className="mx-auto mt-6 flex max-w-xs items-center gap-3">
          <span className="h-px flex-1 bg-gold-500/50" />
          <span className="text-xs text-gold-500">◆</span>
          <span className="h-px flex-1 bg-gold-500/50" />
        </div>
      </header>

      {error && (
        <div className="mx-auto mt-6 max-w-xl border-l-2 border-oxblood-500 bg-oxblood-900/50 px-4 py-2 font-serif italic text-cream-100">
          {error}
        </div>
      )}

      <div className="mt-10 grid gap-6 md:grid-cols-2">
        {/* Open a table */}
        <section className="panel p-6 animate-rise">
          <div className="label">Solo · against rival papers</div>
          <h2 className="mt-1 font-display text-3xl font-semibold text-cream-50">Open a table</h2>

          <label className="mt-5 block" htmlFor="name">
            <span className="label text-cream-200/60">The editor</span>
            <input
              id="name"
              value={playerName}
              onChange={(e) => rememberName(e.target.value)}
              maxLength={40}
              placeholder="Your name, as it will appear on the masthead"
              className="mt-1 w-full border-b border-gold-500/50 bg-transparent px-0 py-2 font-display text-2xl text-cream-50 outline-none placeholder:font-serif placeholder:text-base placeholder:italic placeholder:text-cream-200/30 focus:border-gold-300"
            />
          </label>

          <div className="mt-5">
            <span className="label text-cream-200/60">Rival papers</span>
            <div className="mt-2 inline-flex border border-gold-500/50">
              {[1, 2, 3, 4].map((n) => (
                <button
                  key={n}
                  type="button"
                  onClick={() => setRivals(n)}
                  className={
                    n === rivals
                      ? 'h-9 w-11 bg-cream-100 font-display text-lg font-semibold text-ink-950'
                      : 'h-9 w-11 font-display text-lg text-cream-200/70 transition hover:text-gold-300'
                  }
                >
                  {n}
                </button>
              ))}
            </div>
            <p className="mt-2 font-serif text-sm italic text-cream-200/50">
              {rivals === 1 ? 'Head to head — the balanced setting.' : `A crowded field of ${rivals + 1} papers.`}
            </p>
          </div>

          <button type="button" onClick={doCreate} disabled={busy} className="btn-solid mt-6 w-full">
            Start the presses
          </button>
        </section>

        {/* Join a table */}
        <section className="panel flex flex-col p-6 animate-rise">
          <div className="label">With friends</div>
          <h2 className="mt-1 font-display text-3xl font-semibold text-cream-50">Take a seat</h2>

          <div className="mt-5 flex items-end gap-3">
            <label className="flex-1" htmlFor="code">
              <span className="label text-cream-200/60">Table code</span>
              <input
                id="code"
                value={joinCode}
                onChange={(e) => setJoinCode(e.target.value.toUpperCase())}
                maxLength={8}
                placeholder="CODE"
                className="mt-1 w-full border-b border-gold-500/50 bg-transparent px-0 py-2 font-mono text-2xl uppercase tracking-[0.4em] text-cream-50 outline-none placeholder:text-cream-200/20 focus:border-gold-300"
              />
            </label>
            <button type="button" onClick={() => doJoin(joinCode)} disabled={busy || !joinCode.trim()} className="btn">
              Join
            </button>
          </div>

          <div className="mt-6 flex-1">
            <span className="label text-cream-200/60">Open tables</span>
            {openTables.length === 0 ? (
              <p className="mt-2 font-serif text-sm italic text-cream-200/40">No table is waiting for players.</p>
            ) : (
              <ul className="mt-2 divide-y divide-gold-500/15">
                {openTables.map((g) => (
                  <li key={g.game_id} className="flex items-center justify-between py-2">
                    <div>
                      <span className="font-mono tracking-[0.3em] text-gold-300">{g.join_code}</span>
                      <span className="ml-3 font-serif text-sm text-cream-200/70">
                        {g.seated}/{g.max_players} · {g.players.join(', ')}
                      </span>
                    </div>
                    <button type="button" onClick={() => doJoin(g.join_code)} disabled={busy} className="btn">
                      Sit
                    </button>
                  </li>
                ))}
              </ul>
            )}
          </div>

          <button type="button" onClick={() => setShowRules((v) => !v)} className="btn mt-4 self-start">
            {showRules ? 'Hide the rules' : 'How to play'}
          </button>
        </section>
      </div>

      {showRules && (
        <div className="mt-6 animate-rise">
          <Rules inline />
        </div>
      )}

      <div className="mt-12">
        <HighScores />
      </div>
    </div>
  );
}
