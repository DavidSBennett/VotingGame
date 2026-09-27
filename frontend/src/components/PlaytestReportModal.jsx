import { useState } from 'react';
import { submitReport } from '../api/client.js';

/**
 * File a playtest note without leaving the table.
 *
 * The server attaches a snapshot of the position at filing time, which is
 * the whole point: "the endgame dragged" is unreadable six games later
 * without the board that produced it.
 */
export default function PlaytestReportModal({ playerToken, onClose }) {
  const [rating, setRating] = useState(0);
  const [notes, setNotes] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState(null);
  const [done, setDone] = useState(false);

  const send = async () => {
    setBusy(true);
    setError(null);
    try {
      await submitReport({
        player_token: playerToken,
        rating: rating > 0 ? rating : undefined,
        notes,
      });
      setDone(true);
    } catch (err) {
      setError(err.message);
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-ink-950/80 p-4 backdrop-blur-sm animate-fade" onClick={onClose}>
      <div
        className="parchment w-full max-w-lg border border-gold-500 p-6 shadow-lift animate-rise"
        onClick={(e) => e.stopPropagation()}
      >
        <div className="font-mono text-[10px] uppercase tracking-[0.3em] text-ink-950/60">Letters to the editor</div>
        <h2 className="font-display text-3xl font-bold text-ink-950">Playtest note</h2>

        {done ? (
          <>
            <p className="mt-3 font-serif text-ink-950/80">Filed with a snapshot of the current position. Thank you.</p>
            <button type="button" onClick={onClose} className="mt-5 bg-ink-950 px-5 py-2 font-display text-sm font-semibold uppercase tracking-[0.2em] text-cream-100">
              Close
            </button>
          </>
        ) : (
          <>
            <p className="mt-1 font-serif italic text-ink-950/70">What worked, what dragged, what you did not understand.</p>

            <div className="mt-4 flex gap-2">
              {[1, 2, 3, 4, 5].map((n) => (
                <button
                  key={n}
                  type="button"
                  onClick={() => setRating(n === rating ? 0 : n)}
                  className={
                    n <= rating
                      ? 'h-10 w-10 border border-ink-950 bg-ink-950 font-display text-lg text-gold-300'
                      : 'h-10 w-10 border border-ink-950/30 font-display text-lg text-ink-950/60 hover:border-ink-950'
                  }
                >
                  {n}
                </button>
              ))}
            </div>

            <textarea
              value={notes}
              onChange={(e) => setNotes(e.target.value)}
              rows={6}
              placeholder="Notes"
              className="mt-4 w-full border border-ink-950/30 bg-cream-50/70 px-3 py-2 font-serif text-ink-950 outline-none placeholder:text-ink-950/40 focus:border-ink-950"
            />

            {error && <p className="mt-2 font-serif text-sm text-oxblood-700">{error}</p>}

            <div className="mt-4 flex justify-end gap-3">
              <button type="button" onClick={onClose} className="px-3 py-2 font-mono text-[10px] uppercase tracking-[0.2em] text-ink-950/60 hover:text-ink-950">
                Cancel
              </button>
              <button
                type="button"
                onClick={send}
                disabled={busy || (!notes.trim() && rating === 0)}
                className="bg-ink-950 px-5 py-2 font-display text-sm font-semibold uppercase tracking-[0.2em] text-cream-100 disabled:opacity-40"
              >
                File it
              </button>
            </div>
          </>
        )}
      </div>
    </div>
  );
}
