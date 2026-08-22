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
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/70 p-4">
      <div className="w-full max-w-lg rounded-lg border border-slate-700 bg-slate-900 p-6 shadow-panel">
        <h2 className="text-lg font-semibold text-slate-100">Playtest note</h2>

        {done ? (
          <>
            <p className="mt-3 text-sm text-slate-300">
              Filed with a snapshot of the current position. Thank you.
            </p>
            <button
              type="button"
              onClick={onClose}
              className="mt-4 rounded bg-amber-600 px-4 py-2 font-medium text-slate-950 hover:bg-amber-500"
            >
              Close
            </button>
          </>
        ) : (
          <>
            <p className="mt-1 text-sm text-slate-400">
              What worked, what dragged, what you did not understand.
            </p>

            <div className="mt-4 flex gap-2">
              {[1, 2, 3, 4, 5].map((n) => (
                <button
                  key={n}
                  type="button"
                  onClick={() => setRating(n === rating ? 0 : n)}
                  className={
                    n <= rating
                      ? 'h-10 w-10 rounded border border-amber-500 bg-amber-600 font-mono text-slate-950'
                      : 'h-10 w-10 rounded border border-slate-600 bg-slate-800 font-mono text-slate-300 hover:border-amber-500'
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
              className="mt-4 w-full rounded border border-slate-600 bg-slate-950 px-3 py-2 text-slate-100 outline-none focus:border-amber-500"
            />

            {error && <p className="mt-2 text-sm text-red-300">{error}</p>}

            <div className="mt-4 flex justify-end gap-2">
              <button
                type="button"
                onClick={onClose}
                className="rounded border border-slate-600 px-4 py-2 text-slate-300 hover:border-slate-400"
              >
                Cancel
              </button>
              <button
                type="button"
                onClick={send}
                disabled={busy || (!notes.trim() && rating === 0)}
                className="rounded bg-amber-600 px-4 py-2 font-medium text-slate-950 hover:bg-amber-500 disabled:opacity-50"
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
