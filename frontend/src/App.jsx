import { useState } from 'react';
import Lobby from './views/Lobby.jsx';
import GameShell from './views/GameShell.jsx';
import { loadSeat, saveSeat, clearSeat } from './api/client.js';

/**
 * Top-level switch: either you hold a seat, or you are in the lobby.
 *
 * Deliberately no router. The whole app is two screens and the seat lives
 * in localStorage, so URL routing would add an .htaccess rewrite rule (and
 * a class of 404-on-refresh bugs) for nothing.
 */
export default function App() {
  const [seat, setSeat] = useState(() => loadSeat());

  const takeSeat = (s) => {
    saveSeat(s);
    setSeat(s);
  };

  const leaveSeat = () => {
    clearSeat();
    setSeat(null);
  };

  return (
    <div className="min-h-full bg-slate-900 text-slate-200">
      {seat ? (
        <GameShell seat={seat} onLeave={leaveSeat} />
      ) : (
        <Lobby onSeated={takeSeat} />
      )}
    </div>
  );
}
