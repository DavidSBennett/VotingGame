import { useEffect, useRef, useState } from 'react';
import { usePolledState } from '../hooks/usePolledState.js';
import { startGame, playAction, downloadExport } from '../api/client.js';
import EventLog from '../components/EventLog.jsx';
import PlaytestReportModal from '../components/PlaytestReportModal.jsx';
import Reveal from '../components/Reveal.jsx';
import Rules from '../components/Rules.jsx';
import News from '../components/News.jsx';
import CommitBoard from '../components/CommitBoard.jsx';
import BoardStrip from '../components/BoardStrip.jsx';

/**
 * The game screen: a masthead, a status line, the timeline of elections,
 * the current race above the table, and the presses and the wire at the
 * side. Each election's results arrive as a broadsheet over the table.
 *
 * Presentation only: it renders the public state the server sent and offers
 * exactly the actions the server allows. It computes nothing about the
 * rules, so it can never disagree with the engine about what is allowed,
 * only about how recently it asked.
 */
export default function GameShell({ seat, onLeave }) {
  const { state, events, error, loading, refresh } = usePolledState({
    playerToken: seat.player_token,
  });
  const [busy, setBusy] = useState(false);
  const [actionError, setActionError] = useState(null);
  const [reportOpen, setReportOpen] = useState(false);
  const [rulesOpen, setRulesOpen] = useState(false);
  const [revealOpen, setRevealOpen] = useState(false);

  // Open the returns sheet whenever a new election has been decided -- but
  // not for the one already on the board when the page loads.
  const seenReveal = useRef(undefined);
  const revealKey = state && state.last_reveal ? `${state.game_id}:${state.last_reveal.space}` : null;
  useEffect(() => {
    if (!state) return;
    if (seenReveal.current === undefined) {
      seenReveal.current = revealKey;
      return;
    }
    if (revealKey && revealKey !== seenReveal.current) {
      seenReveal.current = revealKey;
      setRevealOpen(true);
    }
  }, [revealKey, state]);

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

  // Leaving an active game concedes it: otherwise the game sits "in
  // progress" forever and the seat token is gone from this browser.
  const leave = async () => {
    const stillPlaying = state && state.status === 'active' && !state.players.find((p) => p.is_you)?.conceded;
    if (stillPlaying) {
      if (!window.confirm('Leaving concedes this game. Leave anyway?')) return;
      try {
        await playAction(seat.player_token, 'concede');
      } catch {
        /* leave regardless: the seat is being dropped either way */
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

  const ended = state.status === 'ended';
  const active = state.status === 'active';
  const me = state.players.find((p) => p.is_you);
  const seatName = (n) => state.players.find((p) => p.seat === n)?.player_name || 'a rival';
  const stabilityPct = state.stability_max ? (100 * state.stability) / state.stability_max : 0;
  const waiting = state.players.filter((p) => !p.conceded && !p.committed);

  return (
    <div className="min-h-full">
      {/* Masthead */}
      <header className="border-b border-gold-500/40 bg-ink-900/90 backdrop-blur">
        <div className="mx-auto flex max-w-7xl flex-wrap items-center justify-between gap-3 px-4 py-3">
          <div className="flex items-baseline gap-3">
            <h1 className="font-display text-3xl font-bold text-cream-50">The Fourth Estate</h1>
            <span className="hidden font-serif text-sm italic text-gold-300 sm:inline">
              {active && state.race ? `The campaign of ${state.race.year}` : ended ? 'The final count' : 'The table is filling'}
            </span>
          </div>
          <nav className="flex flex-wrap gap-2">
            <button type="button" onClick={() => setRulesOpen(true)} className="btn">
              How to play
            </button>
            {state.last_reveal && (
              <button type="button" onClick={() => setRevealOpen(true)} className="btn">
                Last returns
              </button>
            )}
            <button type="button" onClick={() => setReportOpen(true)} className="btn">
              Playtest note
            </button>
            <button type="button" onClick={() => downloadExport(seat.player_token, state.game_id)} className="btn">
              Download
            </button>
            <button type="button" onClick={leave} className="btn">
              {ended ? 'Lobby' : 'Leave'}
            </button>
          </nav>
        </div>

        {/* Status line */}
        <div className="border-t border-gold-500/20">
          <div className="mx-auto flex max-w-7xl flex-wrap items-center gap-x-6 gap-y-1 px-4 py-2 font-mono text-[10px] uppercase tracking-[0.2em] text-cream-200/70">
            <span>
              Election <span className="text-cream-50">{Math.min(state.space, state.total_spaces)}</span>/{state.total_spaces}
            </span>
            <span className="flex items-center gap-2">
              Union
              <span className="relative inline-block h-1.5 w-24 bg-ink-950">
                <span
                  className={
                    stabilityPct > 50
                      ? 'absolute inset-y-0 left-0 bg-gold-400 transition-all duration-700'
                      : stabilityPct > 25
                        ? 'absolute inset-y-0 left-0 bg-gold-500 transition-all duration-700'
                        : 'absolute inset-y-0 left-0 bg-oxblood-500 transition-all duration-700'
                  }
                  style={{ width: `${stabilityPct}%` }}
                />
              </span>
              <span className={stabilityPct > 25 ? 'text-cream-50' : 'text-oxblood-300'}>
                {state.stability}/{state.stability_max}
              </span>
            </span>
            {me && (
              <span>
                Your purse <span className="text-gold-300">${me.money}</span>
              </span>
            )}
            {state.president && (
              <span>
                In office <span className="text-cream-50">{state.president.name.split(' ').slice(-1)[0]}</span>
                {state.president.patron_seat !== null && (
                  <>
                    {' '}· Patron <span className="text-gold-300">{seatName(state.president.patron_seat)}</span>
                  </>
                )}
              </span>
            )}
            <span className="ml-auto font-mono text-[9px] text-cream-200/30">{state.join_code} · #{state.state_version}</span>
          </div>
        </div>
      </header>

      <main className="mx-auto max-w-7xl px-4 py-6">
        <div className="panel mb-6 px-4 pb-3 pt-4">
          <BoardStrip space={state.space} totalSpaces={state.total_spaces} history={state.history} years={state.years} />
        </div>

        {actionError && (
          <div className="mb-4 border-l-2 border-oxblood-500 bg-oxblood-900/50 px-4 py-2 font-serif italic text-cream-100">
            {actionError}
          </div>
        )}
        {error && !actionError && (
          <div className="mb-4 border-l-2 border-gold-500 bg-ink-900 px-4 py-2 font-serif text-sm italic text-cream-200/80">
            {error}
          </div>
        )}

        <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_18rem]">
          <div className="space-y-5">
            {active && state.race && (
              <>
                <section className="text-center animate-fade">
                  <div className="label">
                    Election {state.race.space} of {state.total_spaces}
                  </div>
                  <h2 className="mt-1 font-display text-4xl font-bold text-cream-50 sm:text-5xl">
                    The Campaign of {state.race.year}
                  </h2>
                  {state.race.note && (
                    <p className="mx-auto mt-2 max-w-2xl font-serif text-[15px] italic text-cream-200/80">{state.race.note}</p>
                  )}
                  <div className="mx-auto mt-3 flex max-w-3xl flex-wrap justify-center gap-2">
                    {state.players.map((p) => (
                      <span
                        key={p.seat}
                        className={
                          p.conceded
                            ? 'border border-cream-200/10 px-2 py-0.5 font-mono text-[9px] uppercase tracking-[0.18em] text-cream-200/30'
                            : p.committed
                              ? 'border border-gold-500/60 bg-gold-500/10 px-2 py-0.5 font-mono text-[9px] uppercase tracking-[0.18em] text-gold-300'
                              : 'border border-cream-200/20 px-2 py-0.5 font-mono text-[9px] uppercase tracking-[0.18em] text-cream-200/60'
                        }
                      >
                        {p.is_you ? 'You' : p.player_name} · {p.conceded ? 'left' : p.committed ? 'sealed' : 'deciding'}
                      </span>
                    ))}
                  </div>
                </section>

                <News news={state.news} space={state.space} />

                <CommitBoard
                  hand={state.you ? state.you.hand : []}
                  race={state.race}
                  commit={state.you ? state.you.commit : null}
                  busy={busy}
                  onCommit={(params) => act('commit', params)}
                  rules={state.rules}
                  stability={state.stability}
                  seats={state.players}
                />

                {waiting.length > 0 && waiting.every((p) => !p.is_you) && (
                  <p className="text-center font-serif italic text-cream-200/60">
                    Waiting for {waiting.map((p) => p.player_name).join(', ')}…
                  </p>
                )}
              </>
            )}

            {state.status === 'lobby' && (
              <section className="panel p-8 text-center">
                <div className="label">The table is filling</div>
                <p className="mt-3 font-serif text-lg text-cream-100">
                  Share the code <span className="font-mono tracking-[0.3em] text-gold-300">{state.join_code}</span> to
                  seat the other papers.
                </p>
                {seat.seat === 0 && (
                  <button
                    type="button"
                    onClick={doStart}
                    disabled={busy || state.players.length < 2}
                    className="btn-solid mt-5"
                  >
                    Start the presses
                  </button>
                )}
              </section>
            )}

            {ended && (
              <section className="parchment border border-gold-500 p-8 text-center shadow-lift animate-rise">
                <div className="font-mono text-[10px] uppercase tracking-[0.35em] text-ink-950/60">The final count</div>
                <h2
                  className={
                    state.ended_reason === 'the_union_breaks'
                      ? 'mt-2 font-display text-5xl font-bold text-oxblood-700'
                      : 'mt-2 font-display text-5xl font-bold text-ink-950'
                  }
                >
                  {state.ended_reason === 'board_completed'
                    ? 'It is 1860'
                    : state.ended_reason === 'the_union_breaks'
                      ? 'The Union Breaks'
                      : 'The Presses Stop'}
                </h2>
                <p className="mt-2 font-serif italic text-ink-950/70">{state.ended_text || 'The game is over.'}</p>
                <div className="mx-auto mt-3 h-px w-1/2 bg-ink-950/30" />
                <ol className="mx-auto mt-4 max-w-md space-y-2 text-left">
                  {[...state.players]
                    .sort((a, b) => (b.final_score ?? 0) - (a.final_score ?? 0))
                    .map((p, i) => (
                      <li key={p.seat} className="flex items-baseline justify-between border-b border-ink-950/15 pb-1">
                        <span className="font-display text-xl text-ink-950">
                          <span className="mr-3 font-mono text-sm text-ink-950/50">{i + 1}.</span>
                          {p.player_name}
                          {p.is_you && <span className="ml-2 font-mono text-[9px] uppercase tracking-[0.2em] text-gold-500">you</span>}
                          {state.winner_seat === p.seat && (
                            <span className="ml-2 font-mono text-[9px] uppercase tracking-[0.2em] text-gold-500">★ richest</span>
                          )}
                        </span>
                        <span className="font-mono text-lg text-wood-700">
                          ${p.final_score}
                          {p.exposure_penalty > 0 && <span className="ml-1 text-xs text-oxblood-700">(−{p.exposure_penalty})</span>}
                        </span>
                      </li>
                    ))}
                </ol>
                <p className="mt-4 font-mono text-[10px] uppercase tracking-[0.2em] text-ink-950/50">
                  Patron {state.players.map((p) => `${p.is_you ? 'you' : p.player_name} ${p.patronages}×`).join(' · ')}
                </p>
              </section>
            )}
          </div>

          {/* The side: the presses, and the wire */}
          <aside className="space-y-5">
            <section className="panel p-4">
              <div className="section-title mb-3">The presses</div>
              <ul className="space-y-3">
                {state.players.map((p) => (
                  <li
                    key={p.seat}
                    className={p.is_you ? 'border-l-2 border-gold-300 pl-3' : 'border-l-2 border-transparent pl-3'}
                  >
                    <div className="flex items-baseline justify-between gap-2">
                      <span className="font-display text-lg leading-tight text-cream-50">
                        {p.player_name}
                        {p.is_you && <span className="ml-1.5 font-mono text-[9px] uppercase tracking-[0.2em] text-gold-300">you</span>}
                      </span>
                      <span className="font-mono text-sm text-gold-300">${p.money}</span>
                    </div>
                    <div className="mt-0.5 flex flex-wrap gap-x-3 font-mono text-[9px] uppercase tracking-[0.15em] text-cream-200/60">
                      {p.is_patron && <span className="text-gold-300">★ Patron</span>}
                      <span>{p.hand_count} clippings</span>
                      <span>Patron {p.patronages}×</span>
                      <span
                        className={p.exposure > 0 && p.exposure_rank === 1 ? 'text-oxblood-300' : ''}
                        title="Exposure: clippings run as hostile coverage. If the Union breaks, the most exposed paper pays."
                      >
                        exposure {p.exposure}
                        {p.exposure > 0 && p.exposure_rank === 1 ? ' · most' : ''}
                      </span>
                      {p.is_bot && <span className="text-cream-200/30">rival</span>}
                      {p.conceded && <span className="text-cream-200/30">left</span>}
                    </div>
                  </li>
                ))}
              </ul>
            </section>

            <EventLog events={events} />
          </aside>
        </div>
      </main>

      {revealOpen && state.last_reveal && (
        <Reveal reveal={state.last_reveal} seats={state.players} track={state.track} onClose={() => setRevealOpen(false)} />
      )}
      {rulesOpen && <Rules rules={state.rules} onClose={() => setRulesOpen(false)} />}
      {reportOpen && <PlaytestReportModal playerToken={seat.player_token} onClose={() => setReportOpen(false)} />}
    </div>
  );
}
