import { useState } from 'react';

/**
 * Your hand, and the three ways to play a card.
 *
 * This is the component the scaffold was missing, and its absence is why
 * the only button that ever worked was Concede: every real action needs a
 * CARD, and Sway needs a candidate too, so a button that posts no
 * parameters can only ever be rejected.
 *
 * Legality comes entirely from the server (can_sway, can_transition,
 * earliest_space). Nothing here re-derives a rule — if the flags and the
 * engine ever disagree, the action gets rejected and we see the real
 * message rather than a UI that quietly hides the discrepancy.
 */
export default function Hand({ hand, race, money, yourTurn, busy, onPlay }) {
  const [selected, setSelected] = useState(null);

  if (!hand || hand.length === 0) {
    return (
      <section className="rounded-lg border border-slate-700 bg-slate-800 p-4">
        <p className="text-sm text-slate-500">No cards in hand.</p>
      </section>
    );
  }

  const card = hand.find((c) => c.key === selected) || null;

  const play = (action, params) => {
    setSelected(null);
    onPlay(action, params);
  };

  return (
    <section className="rounded-lg border border-slate-700 bg-slate-800 p-4">
      <div className="mb-3 flex items-baseline justify-between">
        <h2 className="text-xs uppercase tracking-widest text-slate-400">Your hand</h2>
        <span className="text-xs text-slate-500">
          {yourTurn ? 'Play one card' : 'Waiting for the other papers'}
        </span>
      </div>

      <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
        {hand.map((c) => {
          const isSelected = c.key === selected;
          return (
            <button
              key={c.key}
              type="button"
              onClick={() => setSelected(isSelected ? null : c.key)}
              className={
                isSelected
                  ? 'rounded border border-amber-500 bg-slate-900 p-3 text-left'
                  : 'rounded border border-slate-700 bg-slate-900 p-3 text-left hover:border-slate-500'
              }
            >
              <div className="flex items-baseline justify-between gap-2">
                <span className="text-sm font-medium text-slate-100">{c.name}</span>
                <span className="font-mono text-xs text-slate-500">{c.year}</span>
              </div>

              {c.is_key && (
                <div className="mt-1 inline-block rounded bg-amber-900 px-1.5 py-0.5 text-xs text-amber-300">
                  key card
                </div>
              )}

              <div className="mt-2 flex flex-wrap gap-x-3 gap-y-1 font-mono text-xs">
                <span className="text-emerald-400">money +{c.finance}</span>
                {!c.is_key && (
                  <span className="text-slate-400">
                    sway {c.sway_cost} for {c.sway_cp}
                  </span>
                )}
                {c.stability !== 0 && (
                  <span className={c.stability < 0 ? 'text-red-300' : 'text-sky-300'}>
                    stability {c.stability > 0 ? `+${c.stability}` : c.stability}
                  </span>
                )}
              </div>

              {Object.keys(c.deltas || {}).length > 0 && (
                <div className="mt-1 font-mono text-xs text-slate-400">
                  {Object.entries(c.deltas)
                    .map(([axis, d]) => `${axis} ${d > 0 ? `+${d}` : d}`)
                    .join('  ')}
                </div>
              )}

              <p className="mt-2 text-xs italic leading-snug text-slate-500">{c.flavor}</p>
            </button>
          );
        })}
      </div>

      {card && (
        <div className="mt-4 rounded border border-amber-700 bg-slate-900 p-3">
          <div className="mb-2 text-sm text-slate-200">
            Play <span className="font-medium">{card.name}</span>
          </div>

          {!yourTurn && (
            <p className="mb-2 text-xs text-amber-400">It is not your turn yet.</p>
          )}

          <div className="flex flex-wrap gap-2">
            <button
              type="button"
              disabled={busy || !yourTurn}
              onClick={() => play('finance', { card: card.key })}
              className="rounded bg-emerald-700 px-3 py-1.5 text-sm font-medium text-white hover:bg-emerald-600 disabled:opacity-40"
            >
              Run it for money (+{card.finance})
            </button>

            {card.is_key ? (
              <button
                type="button"
                disabled={busy || !yourTurn || !card.can_transition}
                onClick={() => play('transition', { card: card.key })}
                className="rounded bg-amber-600 px-3 py-1.5 text-sm font-medium text-slate-950 hover:bg-amber-500 disabled:opacity-40"
                title={
                  card.can_transition
                    ? 'Change what the country argues about'
                    : 'The country is not arguing about this yet'
                }
              >
                Change the argument (+{card.finance})
              </button>
            ) : (
              race &&
              race.candidates.map((cand) => (
                <button
                  key={cand.key}
                  type="button"
                  disabled={busy || !yourTurn || !card.can_sway}
                  onClick={() =>
                    play('sway', { card: card.key, candidate: cand.key })
                  }
                  className="rounded border border-slate-600 px-3 py-1.5 text-sm text-slate-200 hover:border-amber-500 disabled:opacity-40"
                  title={
                    card.can_sway
                      ? `Costs ${card.sway_cost}, gives ${card.sway_cp} control`
                      : 'Superseded, or you cannot afford it'
                  }
                >
                  Sway for {cand.name} (−{card.sway_cost})
                </button>
              ))
            )}

            <button
              type="button"
              onClick={() => setSelected(null)}
              className="rounded px-3 py-1.5 text-sm text-slate-400 hover:text-slate-200"
            >
              Cancel
            </button>
          </div>

          {!card.is_key && !card.can_sway && (
            <p className="mt-2 text-xs text-slate-500">
              {card.sway_cost > money
                ? `You hold ${money}; swaying this costs ${card.sway_cost}.`
                : 'The country has stopped arguing about this. You can still run it for money.'}
            </p>
          )}
          {card.is_key && !card.can_transition && card.earliest_space && (
            <p className="mt-2 text-xs text-slate-500">
              Cannot change the argument before space {card.earliest_space}.
            </p>
          )}
        </div>
      )}
    </section>
  );
}
