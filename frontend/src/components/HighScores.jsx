import { useEffect, useState } from 'react';
import { fetchHighScores } from '../api/client.js';

/**
 * Lobby high-score board. Reads vg_scores, which is written once per seat
 * at game end and survives an admin purge of finished games.
 */
export default function HighScores() {
  const [scores, setScores] = useState([]);
  const [error, setError] = useState(null);

  useEffect(() => {
    fetchHighScores({ limit: 15 })
      .then((data) => setScores(data.scores || []))
      .catch((err) => setError(err.message));
  }, []);

  return (
    <section>
      <h2 className="mb-3 text-lg font-semibold text-slate-100">High scores</h2>
      {error && <p className="text-sm text-red-300">{error}</p>}
      {!error && scores.length === 0 && (
        <p className="text-sm text-slate-500">No finished games yet.</p>
      )}
      {scores.length > 0 && (
        <table className="w-full text-sm">
          <thead>
            <tr className="text-left text-xs uppercase tracking-wide text-slate-500">
              <th className="py-1">#</th>
              <th className="py-1">Player</th>
              <th className="py-1">Score</th>
              <th className="py-1">Players</th>
              <th className="py-1">Variant</th>
            </tr>
          </thead>
          <tbody>
            {scores.map((s) => (
              <tr key={s.score_id} className="border-t border-slate-800">
                <td className="py-1 font-mono text-slate-500">{s.rank}</td>
                <td className="py-1 text-slate-200">
                  {s.player_name}
                  {s.won && <span className="ml-2 text-xs text-emerald-400">won</span>}
                </td>
                <td className="py-1 font-mono text-slate-200">{s.score}</td>
                <td className="py-1 text-slate-400">{s.players_count}</td>
                <td className="py-1 font-mono text-xs text-slate-500">{s.variant}</td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
    </section>
  );
}
