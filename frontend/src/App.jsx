import { useState } from 'react';
import Launch from './views/Launch.jsx';
import Lobby from './views/Lobby.jsx';
import GameShell from './views/GameShell.jsx';
import { loadSeat, saveSeat, clearSeat } from './api/client.js';

/**
 * Top-level switch: either you hold a seat, or you choose an election on
 * the launch page and then sit in that election's lobby.
 *
 * Deliberately no router. The app is three screens and the seat lives in
 * localStorage, so URL routing would add an .htaccess rewrite rule (and a
 * class of 404-on-refresh bugs) for nothing. A seat already knows its game
 * (GameShell picks the screen from the state's engine).
 */
export default function App() {
  const [seat, setSeat] = useState(() => loadSeat());
  const [election, setElection] = useState(null); // '2024' or 'dc' (1796-1860)

  const takeSeat = (s) => {
    saveSeat(s);
    setSeat(s);
  };

  const leaveSeat = () => {
    clearSeat();
    setSeat(null);
  };

  return (
    <div className="min-h-full text-cream-100">
      {seat ? (
        <GameShell seat={seat} onLeave={leaveSeat} />
      ) : election ? (
        <Lobby key={election} engine={election} onSeated={takeSeat} onBack={() => setElection(null)} />
      ) : (
        <Launch onChoose={setElection} />
      )}
    </div>
  );
}
