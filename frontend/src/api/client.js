import axios from 'axios';

/**
 * Central API client.
 *
 * Production: the built app and the PHP endpoints share an origin, so the
 * base URL is '' (same-origin, e.g. /createGame.php).
 * Dev: Vite proxies /api/* to the live install — see vite.config.js.
 *
 * There is no local PHP runtime, so `npm run dev` always talks to the
 * real server. Remember that when testing anything destructive.
 */
const baseURL = import.meta.env.PROD ? '' : '/api';

export const api = axios.create({ baseURL, timeout: 15000 });

/** Turn an axios failure into an Error with a message worth showing. */
function normalizeError(err) {
  if (err.response) {
    const bodyError = err.response.data && err.response.data.error;
    if (bodyError) return new Error(bodyError);
    return new Error(`Server returned ${err.response.status}`);
  }
  if (err.request) {
    return new Error('Could not reach the server. Check your connection and try again.');
  }
  return new Error(err.message || 'Unknown error');
}

async function post(path, body) {
  try {
    const res = await api.post(path, body);
    return res.data;
  } catch (err) {
    throw normalizeError(err);
  }
}

async function get(path, params) {
  try {
    const res = await api.get(path, { params });
    return res.data;
  } catch (err) {
    throw normalizeError(err);
  }
}

// ---- Seat identity ---------------------------------------------------
// The per-seat player_token IS the credential. No accounts, nothing shared
// with any other site. Kept in localStorage so a refresh keeps your seat.

const SEAT_KEY = 'votinggame.seat';

export function saveSeat(seat) {
  try {
    localStorage.setItem(SEAT_KEY, JSON.stringify(seat));
  } catch {
    /* private browsing: play on, a refresh will lose the seat */
  }
}

export function loadSeat() {
  try {
    const raw = localStorage.getItem(SEAT_KEY);
    return raw ? JSON.parse(raw) : null;
  } catch {
    return null;
  }
}

export function clearSeat() {
  try {
    localStorage.removeItem(SEAT_KEY);
  } catch {
    /* nothing to do */
  }
}

// ---- Endpoints -------------------------------------------------------

export const createGame = (payload) => post('/createGame.php', payload);
export const joinGame = (payload) => post('/joinGame.php', payload);
export const startGame = (player_token) => post('/startGame.php', { player_token });
export const playAction = (player_token, action, params = {}) =>
  post('/playAction.php', { player_token, action, params });
export const concede = (player_token) => playAction(player_token, 'concede');

export const fetchState = (params) => get('/getState.php', params);
export const listOpenGames = (includeActive = false) =>
  get('/listOpenGames.php', includeActive ? { include_active: 1 } : {});
export const fetchHighScores = (params = {}) => get('/highScores.php', params);
export const fetchExport = (player_token) => get('/exportGame.php', { player_token });
export const submitReport = (payload) => post('/submitReport.php', payload);

/**
 * Download one playthrough as a JSON file. The export is the artefact all
 * post-game analysis reads, so the file is written verbatim — no trimming,
 * no reformatting on the way out.
 */
export async function downloadExport(player_token, gameId) {
  const data = await fetchExport(player_token);
  const blob = new Blob([JSON.stringify(data.export, null, 2)], {
    type: 'application/json',
  });
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = `playthrough-${gameId}-${new Date().toISOString().slice(0, 19).replace(/[:T]/g, '')}.json`;
  document.body.appendChild(a);
  a.click();
  document.body.removeChild(a);
  URL.revokeObjectURL(url);
  return data.export;
}
