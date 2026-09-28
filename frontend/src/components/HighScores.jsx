import { useEffect, useState } from 'react';
import { fetchHighScores } from '../api/client.js';

/**
 * The circulation board: the most honoured papers on record, by prestige.
 * Reads vg_scores (which survives an admin purge of finished games), only
 * the DC-style game's rows: the older game scored money, not prestige.
 * `papers` (from the lobby list) turns a newspaper key into its name.
 */
export default function HighScores({ papers = [] }) {
  const [scores, setScores] = useState([]);
  const [error, setError] = useState(null);

  useEffect(() => {
    fetchHighScores({ limit: 15, variant: 'variant', engine: 'dc' })
      .then((data) => setScores(data.scores || []))
      .catch((err) => setError(err.message));
  }, []);

  const paperName = (key) => (papers.find((p) => p.key === key) || {}).name || key || '—';

  return (
    <section>
      <div className="flex items-baseline justify-between border-b border-gold-500/40 pb-2">
        <h2 className="font-display text-2xl font-semibold text-gold-300">The circulation board</h2>
        <span className="label">Most honoured papers on record</span>
      </div>
      {error && <p className="mt-3 font-serif text-sm italic text-oxblood-300">{error}</p>}
      {!error && scores.length === 0 && <p className="mt-3 font-serif text-sm italic text-cream-200/50">No finished games yet.</p>}
      {scores.length > 0 && (
        <table className="mt-2 w-full font-mono text-[12px] text-cream-200">
          <thead>
            <tr className="text-left text-[10px] uppercase tracking-[0.2em] text-gold-500">
              <th className="py-2 font-medium">#</th>
              <th className="py-2 font-medium">Editor</th>
              <th className="hidden py-2 font-medium md:table-cell">Newspaper</th>
              <th className="py-2 text-right font-medium">Prestige</th>
              <th className="hidden py-2 text-right font-medium sm:table-cell">Offices</th>
              <th className="hidden py-2 text-right font-medium sm:table-cell">Papers</th>
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
                <td className="hidden py-1.5 font-serif text-[13px] italic text-gold-400 md:table-cell">{paperName(s.paper)}</td>
                <td className="py-1.5 text-right text-gold-300">★{s.score}</td>
                <td className="hidden py-1.5 text-right sm:table-cell">{s.elections_won ?? '—'}</td>
                <td className="hidden py-1.5 text-right sm:table-cell">{s.players_count}</td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
    </section>
  );
}
