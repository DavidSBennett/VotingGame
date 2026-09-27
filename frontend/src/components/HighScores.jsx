import { useEffect, useState } from 'react';
import { fetchHighScores } from '../api/client.js';

/**
 * The circulation board: the richest papers on record. Reads vg_scores,
 * which survives an admin purge of finished games.
 */
export default function HighScores() {
  const [scores, setScores] = useState([]);
  const [error, setError] = useState(null);

  useEffect(() => {
    // v1 scores came from a different game; they stay in the table but not on the board.
    fetchHighScores({ limit: 15, variant: 'v2' })
      .then((data) => setScores(data.scores || []))
      .catch((err) => setError(err.message));
  }, []);

  return (
    <section>
      <div className="flex items-baseline justify-between border-b border-gold-500/40 pb-2">
        <h2 className="font-display text-2xl font-semibold text-gold-300">The circulation board</h2>
        <span className="label">Richest papers on record</span>
      </div>
      {error && <p className="mt-3 font-serif text-sm italic text-oxblood-300">{error}</p>}
      {!error && scores.length === 0 && (
        <p className="mt-3 font-serif text-sm italic text-cream-200/50">No finished games yet.</p>
      )}
      {scores.length > 0 && (
        <table className="mt-2 w-full font-mono text-[12px] text-cream-200">
          <thead>
            <tr className="text-left text-[10px] uppercase tracking-[0.2em] text-gold-500">
              <th className="py-2 font-medium">#</th>
              <th className="py-2 font-medium">Paper</th>
              <th className="py-2 text-right font-medium">Money</th>
              <th className="hidden py-2 text-right font-medium sm:table-cell">Papers</th>
              <th className="hidden py-2 text-right font-medium sm:table-cell">Elections</th>
            </tr>
          </thead>
          <tbody>
            {scores.map((s) => (
              <tr key={s.score_id} className="border-t border-gold-500/15">
                <td className="py-1.5 text-cream-200/60">{s.rank}</td>
                <td className="py-1.5 font-serif text-[14px] text-cream-50">
                  {s.player_name}
                  {s.won && <span className="ml-2 font-mono text-[9px] uppercase tracking-[0.2em] text-gold-300">won</span>}
                </td>
                <td className="py-1.5 text-right text-gold-300">${s.score}</td>
                <td className="hidden py-1.5 text-right sm:table-cell">{s.players_count}</td>
                <td className="hidden py-1.5 text-right sm:table-cell">{s.rounds ?? '—'}</td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
    </section>
  );
}
