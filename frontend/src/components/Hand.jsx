import { useState } from 'react';

/**
 * Your hand, and the two ways to play a card.
 *
 * Every number shown here — cash value with any Patron bonus, where the
 * track lands, who would then lead — comes from the server. Nothing here
 * re-derives a rule, so the UI can be stale but never wrong.
 */
export default function Hand({ hand, race, yourTurn, busy, onPlay, onPreview }) {
  const [selected, setSelected] = useState(null);

  if (!hand || hand.length === 0) {
    return (
      <section className="rounded-lg border border-slate-700 bg-slate-800 p-4">
        <p className="text-sm text-slate-500">No cards in hand.</p>
      </section>
    );
  }

  const card = hand.find((c) => c.key === selected) || null;

  const select = (key) => {
    const next = key === selected ? null : key;
    setSelected(next);
    const c = hand.find((h) => h.key === next);
    onPreview(c ? c.track_after : null);
  };

  const play = (action, params) => {
    setSelected(null);
    onPreview(null);
    onPlay(action, params);
  };

  const pushLabel = (push) =>
    push > 0 ? `Nation +${push}` : push < 0 ? `States +${-push}` : 'no push';

  return (
    <section className="rounded-lg border border-slate-700 bg-slate-800 p-4">
      <div className="mb-3 flex items-baseline justify-between">
        <h2 className="text-xs uppercase tracking-widest text-slate-400">Your hand</h2>
        <span className="text-xs text-slate-500">
          {yourTurn ? 'Pick a card' : 'Waiting for the other papers'}
        </span>
      </div>

      <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
        {hand.map((c) => (
          <button
            key={c.key}
            type="button"
            onClick={() => select(c.key)}
            className={
              c.key === selected
                ? 'rounded border border-amber-500 bg-slate-900 p-3 text-left'
                : 'rounded border border-slate-700 bg-slate-900 p-3 text-left hover:border-slate-500'
            }
          >
            <div className="flex items-baseline justify-between gap-2">
              <span className="text-sm font-medium text-slate-100">{c.name}</span>
              <span className="font-mono text-xs text-slate-500">{c.year}</span>
            </div>
            <div className="mt-2 flex flex-wrap gap-x-3 font-mono text-xs">
              <span className="text-emerald-400">value {c.value}</span>
              <span
                className={
                  c.push > 0 ? 'text-sky-300' : c.push < 0 ? 'text-rose-300' : 'text-slate-500'
                }
              >
                {pushLabel(c.push)}
              </span>
            </div>
            <p className="mt-2 text-xs italic leading-snug text-slate-500">{c.flavor}</p>
          </button>
        ))}
      </div>

      {card && (
        <div className="mt-4 rounded border border-amber-700 bg-slate-900 p-3">
          <div className="mb-1 text-sm text-slate-200">
            <span className="font-medium">{card.name}</span>
          </div>
          <p className="mb-3 text-xs text-slate-400">
            Printing it moves the track to{' '}
            <span className="font-mono text-slate-200">
              {card.track_after > 0 ? `Nation +${card.track_after}` : card.track_after < 0 ? `States +${-card.track_after}` : '0'}
            </span>
            {race && (
              <>
                , where <span className="text-slate-200">{race[card.leads_after].name}</span> would lead
              </>
            )}
            . Whoever prints it, it pushes the same way.
          </p>

          {!yourTurn && <p className="mb-2 text-xs text-amber-400">It is not your turn yet.</p>}

          <div className="flex flex-wrap gap-2">
            <button
              type="button"
              disabled={busy || !yourTurn}
              onClick={() => play('cash', { card: card.key })}
              className="rounded bg-emerald-700 px-3 py-1.5 text-sm font-medium text-white hover:bg-emerald-600 disabled:opacity-40"
            >
              Cash it (+{card.cash_value})
            </button>
            {race &&
              ['states', 'nation'].map((side) => (
                <button
                  key={side}
                  type="button"
                  disabled={busy || !yourTurn}
                  onClick={() => play('print', { card: card.key, side })}
                  className="rounded border border-slate-600 px-3 py-1.5 text-sm text-slate-200 hover:border-amber-500 disabled:opacity-40"
                >
                  Print it, stake {card.value} on {race[side].name}
                </button>
              ))}
            <button
              type="button"
              onClick={() => select(card.key)}
              className="rounded px-3 py-1.5 text-sm text-slate-400 hover:text-slate-200"
            >
              Cancel
            </button>
          </div>
        </div>
      )}
    </section>
  );
}
