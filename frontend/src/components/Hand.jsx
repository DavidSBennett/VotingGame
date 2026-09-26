import { useEffect, useMemo, useState } from 'react';

/**
 * The commitment board: your hand, and for each card what you will do with
 * it this round — keep it, cash it, or print it for one candidate. Nobody
 * sees your choices until every paper has committed.
 *
 * Values shown (cash value with any Patron bonus, push) come from the
 * server; the only arithmetic here is adding up your own choices so you can
 * see what you are about to do.
 */
const CHOICES = ['keep', 'cash', 'states', 'nation'];

export default function Hand({ hand, race, commit, busy, onCommit, payout }) {
  const [choice, setChoice] = useState({});
  const [reserve, setReserve] = useState(null);
  const [editing, setEditing] = useState(false);

  // A new round (or a fresh commitment on file) resets the board.
  const handKey = (hand || []).map((c) => c.key).join(',');
  useEffect(() => {
    const next = {};
    if (commit) {
      commit.plays.forEach((pl) => {
        next[pl.card] = pl.action === 'cash' ? 'cash' : pl.side;
      });
    }
    setChoice(next);
    setReserve(commit ? commit.reserve : null);
    setEditing(false);
  }, [handKey, race && race.space, commit && JSON.stringify(commit)]);

  const committedKeys = (hand || []).filter((c) => (choice[c.key] || 'keep') !== 'keep').map((c) => c.key);

  const summary = useMemo(() => {
    let cash = 0;
    let push = 0;
    const stake = { nation: 0, states: 0 };
    (hand || []).forEach((c) => {
      const ch = choice[c.key] || 'keep';
      if (ch === 'cash') cash += c.cash_value;
      if (ch === 'nation' || ch === 'states') {
        stake[ch] += c.value;
        push += c.push;
      }
    });
    return { cash, push, stake };
  }, [hand, choice]);

  if (!hand || hand.length === 0) {
    return (
      <section className="rounded-lg border border-slate-700 bg-slate-800 p-4">
        <p className="text-sm text-slate-500">No cards in hand.</p>
        {!commit && (
          <button
            type="button"
            disabled={busy}
            onClick={() => onCommit({ plays: [] })}
            className="mt-3 rounded bg-amber-600 px-4 py-2 text-sm font-medium text-slate-950 hover:bg-amber-500 disabled:opacity-40"
          >
            Pass this round
          </button>
        )}
      </section>
    );
  }

  const locked = commit && !editing;
  const effectiveReserve = committedKeys.includes(reserve) ? reserve : null;

  const setFor = (key, ch) => {
    if (locked) return;
    setChoice((prev) => ({ ...prev, [key]: ch }));
  };

  const submit = () => {
    const plays = committedKeys.map((key) => {
      const ch = choice[key];
      return ch === 'cash' ? { card: key, action: 'cash' } : { card: key, action: 'print', side: ch };
    });
    onCommit({ plays, reserve: effectiveReserve || undefined });
  };

  const label = (ch) =>
    ch === 'keep' ? 'Keep' : ch === 'cash' ? 'Cash' : race ? `Print · ${race[ch].name.split(' ').slice(-1)[0]}` : ch;
  const pushLabel = (push) => (push > 0 ? `Nation +${push}` : push < 0 ? `States +${-push}` : 'no push');

  return (
    <section className="rounded-lg border border-slate-700 bg-slate-800 p-4">
      <div className="mb-3 flex flex-wrap items-baseline justify-between gap-2">
        <h2 className="text-xs uppercase tracking-widest text-slate-400">Your commitment</h2>
        <span className="text-xs text-slate-500">
          {locked ? 'Committed — sealed until everyone is in' : 'Choose what each card does, in secret'}
        </span>
      </div>

      <div className="grid gap-2 sm:grid-cols-2">
        {hand.map((c) => {
          const ch = choice[c.key] || 'keep';
          const active = ch !== 'keep';
          return (
            <div
              key={c.key}
              className={
                active
                  ? 'rounded border border-amber-500 bg-slate-900 p-3'
                  : 'rounded border border-slate-700 bg-slate-900 p-3'
              }
            >
              <div className="flex items-baseline justify-between gap-2">
                <span className="text-sm font-medium text-slate-100">{c.name}</span>
                <span className="font-mono text-xs text-slate-500">{c.year}</span>
              </div>
              <div className="mt-1 flex flex-wrap gap-x-3 font-mono text-xs">
                {c.kind === 'profit' && <span className="text-emerald-300">profit</span>}
                <span className="text-emerald-400">
                  value {c.value}
                  {c.cash_value !== c.value && ` (cash ${c.cash_value})`}
                </span>
                <span className={c.push > 0 ? 'text-sky-300' : c.push < 0 ? 'text-rose-300' : 'text-slate-500'}>
                  {pushLabel(c.push)}
                </span>
              </div>
              <p className="mt-1 text-xs italic leading-snug text-slate-500">{c.flavor}</p>

              <div className="mt-2 flex flex-wrap gap-1">
                {CHOICES.map((opt) => (
                  <button
                    key={opt}
                    type="button"
                    disabled={locked}
                    onClick={() => setFor(c.key, opt)}
                    className={
                      opt === ch
                        ? 'rounded border border-amber-500 bg-amber-600 px-2 py-0.5 text-xs font-medium text-slate-950 disabled:opacity-70'
                        : 'rounded border border-slate-600 px-2 py-0.5 text-xs text-slate-300 hover:border-amber-500 disabled:opacity-40'
                    }
                  >
                    {label(opt)}
                  </button>
                ))}
              </div>
              {active && (
                <label className="mt-2 flex items-center gap-2 text-xs text-slate-400">
                  <input
                    type="radio"
                    name="reserve"
                    disabled={locked}
                    checked={effectiveReserve === c.key}
                    onChange={() => setReserve(c.key)}
                  />
                  Reserve this one (back to hand unless you become Patron)
                </label>
              )}
            </div>
          );
        })}
      </div>

      <div className="mt-4 rounded border border-slate-700 bg-slate-900 p-3 text-sm text-slate-300">
        <div className="flex flex-wrap gap-x-4 gap-y-1">
          <span>
            Committing <span className="font-mono text-slate-100">{committedKeys.length}</span>, keeping{' '}
            <span className="font-mono text-slate-100">{hand.length - committedKeys.length}</span>
          </span>
          <span className="text-emerald-400">cash +{summary.cash}</span>
          {race && summary.stake.states > 0 && (
            <span className="text-rose-300">
              {race.states.name}: stake {summary.stake.states} (pays {Math.floor(summary.stake.states * payout)})
            </span>
          )}
          {race && summary.stake.nation > 0 && (
            <span className="text-sky-300">
              {race.nation.name}: stake {summary.stake.nation} (pays {Math.floor(summary.stake.nation * payout)})
            </span>
          )}
          <span className="text-slate-400">
            your push {summary.push > 0 ? `Nation +${summary.push}` : summary.push < 0 ? `States +${-summary.push}` : '0'}
          </span>
        </div>
        {!effectiveReserve && committedKeys.length > 0 && !locked && (
          <p className="mt-1 text-xs text-slate-500">No reserve marked: your most valuable committed card will be kept.</p>
        )}

        <div className="mt-3 flex flex-wrap gap-2">
          {locked ? (
            <button
              type="button"
              onClick={() => setEditing(true)}
              className="rounded border border-slate-600 px-4 py-2 text-sm text-slate-200 hover:border-amber-500"
            >
              Change my commitment
            </button>
          ) : (
            <button
              type="button"
              disabled={busy || committedKeys.length === 0}
              onClick={submit}
              className="rounded bg-amber-600 px-4 py-2 text-sm font-medium text-slate-950 hover:bg-amber-500 disabled:opacity-40"
            >
              Commit {committedKeys.length} {committedKeys.length === 1 ? 'card' : 'cards'}
            </button>
          )}
        </div>
      </div>
    </section>
  );
}
