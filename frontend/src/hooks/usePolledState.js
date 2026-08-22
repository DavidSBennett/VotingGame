import { useCallback, useEffect, useRef, useState } from 'react';
import { fetchState } from '../api/client.js';

/**
 * Poll full public state every 1.5s.
 *
 * Shared hosting means no websockets, so this is the realtime layer. Two
 * things keep it cheap:
 *   - `since` sends the version we already hold; when nothing has changed
 *     the server replies { changed: false } without building any state.
 *   - polling pauses while the tab is hidden, and fires immediately on
 *     the way back so a returning player is never looking at stale board.
 *
 * The caller can also refresh() by hand right after acting, instead of
 * waiting out the interval.
 */
const POLL_MS = 1500;

export function usePolledState({ playerToken, gameId, enabled = true }) {
  const [state, setState] = useState(null);
  const [events, setEvents] = useState([]);
  const [error, setError] = useState(null);
  const [loading, setLoading] = useState(true);

  // Held in a ref, not state: the poll loop reads the latest version
  // without re-subscribing the interval on every tick.
  const versionRef = useRef(-1);
  const inFlight = useRef(false);

  const poll = useCallback(async () => {
    if (!enabled) return;
    if (!playerToken && !gameId) return;
    if (inFlight.current) return;      // never stack requests on a slow link
    inFlight.current = true;
    try {
      const params = playerToken ? { player_token: playerToken } : { game_id: gameId };
      if (versionRef.current >= 0) params.since = versionRef.current;
      const data = await fetchState(params);
      if (data.changed === false) {
        setError(null);
        return;
      }
      versionRef.current = data.state.state_version;
      setState(data.state);
      setEvents(data.events || []);
      setError(null);
    } catch (err) {
      setError(err.message);
    } finally {
      inFlight.current = false;
      setLoading(false);
    }
  }, [playerToken, gameId, enabled]);

  /** Force a fetch on the next tick, bypassing the version short-circuit. */
  const refresh = useCallback(() => {
    versionRef.current = -1;
    return poll();
  }, [poll]);

  useEffect(() => {
    if (!enabled) return undefined;
    let timer = null;

    const tick = () => {
      if (document.visibilityState === 'visible') poll();
    };

    poll();
    timer = setInterval(tick, POLL_MS);

    const onVisible = () => {
      if (document.visibilityState === 'visible') poll();
    };
    document.addEventListener('visibilitychange', onVisible);

    return () => {
      clearInterval(timer);
      document.removeEventListener('visibilitychange', onVisible);
    };
  }, [poll, enabled]);

  return { state, events, error, loading, refresh };
}
