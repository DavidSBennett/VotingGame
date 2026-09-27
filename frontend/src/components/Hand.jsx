import { useEffect, useMemo, useState } from 'react';

/**
 * The commitment board: your hand, and for each card what you will do with
 * it this round. Nobody sees your choices until every paper has committed.
 *
 *   Keep      stays in hand
 *   Profit    money (doubled if you are the Patron) -- the only way to score
 *   Positive  its positive push, as influence on the candidate you name
 *   Negative  its negative push the same way, and it costs the Union
 *
 * Numbers come from the server; the only arithmetic here is adding up your
 * own choices so you can see what you are about to do.
 */
const MODES = ['keep', 'profit', 'positive', 'negative'];

const pushText = (push) => (push > 0 ? `Nation +${push}` : push < 0 ? `States +${-push}` : '—');
const favoured = (push) => (push > 0 ? 'nation' : 'states');

export default function Hand({ hand, race, commit, busy, onCommit, rules, stability, seats = [] }) {
  const [mode, setMode] = useState({});
  const [side, setSide] = useState({});
  const [reserve, setReserve] = useState(null);
  const [editing, setEditing] = useState(false);

  const handKey = (hand || []).map((c) => c.key).join(',');
  useEffect(() => {
    const m = {};
    const sd = {};
    if (commit) {
      commit.plays.forEach((pl) => {
        m[pl.card] = pl.action;
        if (pl.side) sd[pl.card] = pl.side;
      });
    }
    setMode(m);
    setSide(sd);
    setReserve(commit ? commit.reserve : null);
    setEditing(false);
  }, [handKey, race && race.space, commit && JSON.stringify(commit)]);

  const modeOf = (key) => mode[key] || 'keep';
  const sideOf = (c) => side[c.key] || favoured(modeOf(c.key) === 'negative' ? c.negative : c.positive);
  const committed = (hand || []).filter((c) => modeOf(c.key) !== 'keep');
  const covered = committed.filter((c) => modeOf(c.key) === 'positive' || modeOf(c.key) === 'negative');
  const negatives = committed.filter((c) => modeOf(c.key) === 'negative').length;

  const summary = useMemo(() => {
    let money = 0;
    let push = 0;
    let cost = 0;
    const influence = { nation: 0, states: 0 };
    committed.forEach((c) => {
      const m = modeOf(c.key);
      if (m === 'profit') money += c.profit_value;
      if (m === 'positive' || m === 'negative') {
        const p = m === 'positive' ? c.positive : c.negative;
        push += p;
        influence[sideOf(c)] += Math.abs(p);
        if (m === 'negative') cost += c.stability;
      }
    });
    return { money, push, cost, influence };
  }, [hand, mode, side]);

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
  const effectiveReserve = covered.some((c) => c.key === reserve) ? reserve : null;
  const maxNeg = rules.max_negative;

  const available = (c, m) => {
    if (m === 'keep' || m === 'profit') return true;
    if (c[m] === 0) return false;
    if (m === 'negative' && modeOf(c.key) !== 'negative' && negatives >= maxNeg) return false;
    return true;
  };

  const pick = (key, m) => {
    if (locked) return;
    setMode((prev) => ({ ...prev, [key]: m }));
    setSide((prev) => {
      const next = { ...prev };
      delete next[key];               // re-default to the side the push favours
      return next;
    });
  };

  const submit = () => {
    const plays = committed.map((c) => {
      const m = modeOf(c.key);
      return m === 'profit' ? { card: c.key, action: 'profit' } : { card: c.key, action: m, side: sideOf(c) };
    });
    onCommit({ plays, reserve: effectiveReserve || undefined });
  };

  const surname = (s) => (race ? race[s].name.split(' ').slice(-1)[0] : s);
  const danger = summary.cost > 0 && stability - summary.cost <= 3;
  // Exposure after this commitment, against the most any rival has now.
  const me = seats.find((p) => p.is_you);
  const myExposure = (me ? me.exposure : 0) + negatives;
  const rivalTop = Math.max(0, ...seats.filter((p) => !p.is_you).map((p) => p.exposure));
  const wouldLead = negatives > 0 && myExposure >= rivalTop;

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
          const m = modeOf(c.key);
          const isCover = m === 'positive' || m === 'negative';
          return (
            <div
              key={c.key}
              className={
                m !== 'keep'
                  ? 'rounded border border-amber-500 bg-slate-900 p-3'
                  : 'rounded border border-slate-700 bg-slate-900 p-3'
              }
            >
              <div className="flex items-baseline justify-between gap-2">
                <span className="text-sm font-medium text-slate-100">{c.name}</span>
                <span className="font-mono text-xs text-slate-500">{c.year}</span>
              </div>
              <div className="mt-1 grid grid-cols-3 gap-1 font-mono text-xs">
                <span className="text-emerald-400">
                  profit {c.profit}
                  {c.profit_value !== c.profit && ` (×${c.profit_value / Math.max(1, c.profit)} = ${c.profit_value})`}
                </span>
                <span className={c.positive > 0 ? 'text-sky-300' : c.positive < 0 ? 'text-rose-300' : 'text-slate-600'}>
                  + {pushText(c.positive)}
                </span>
                <span className={c.negative > 0 ? 'text-sky-300' : c.negative < 0 ? 'text-rose-300' : 'text-slate-600'}>
                  − {pushText(c.negative)}
                  {c.stability > 0 && <span className="text-red-400"> · union −{c.stability}</span>}
                </span>
              </div>
              <p className="mt-1 text-xs italic leading-snug text-slate-500">{c.flavor}</p>

              <div className="mt-2 flex flex-wrap gap-1">
                {MODES.map((opt) => (
                  <button
                    key={opt}
                    type="button"
                    disabled={locked || !available(c, opt)}
                    onClick={() => pick(c.key, opt)}
                    className={
                      opt === m
                        ? 'rounded border border-amber-500 bg-amber-600 px-2 py-0.5 text-xs font-medium capitalize text-slate-950 disabled:opacity-70'
                        : 'rounded border border-slate-600 px-2 py-0.5 text-xs capitalize text-slate-300 hover:border-amber-500 disabled:opacity-30'
                    }
                  >
                    {opt}
                  </button>
                ))}
              </div>

              {isCover && race && (
                <div className="mt-2 flex flex-wrap items-center gap-1 text-xs">
                  <span className="text-slate-500">influence for</span>
                  {['states', 'nation'].map((sd) => (
                    <button
                      key={sd}
                      type="button"
                      disabled={locked}
                      onClick={() => setSide((prev) => ({ ...prev, [c.key]: sd }))}
                      className={
                        sideOf(c) === sd
                          ? 'rounded border border-amber-500 bg-slate-700 px-2 py-0.5 text-slate-100'
                          : 'rounded border border-slate-600 px-2 py-0.5 text-slate-400 hover:border-amber-500'
                      }
                    >
                      {race[sd].name}
                    </button>
                  ))}
                </div>
              )}
              {isCover && (
                <label className="mt-2 flex items-center gap-2 text-xs text-slate-400">
                  <input
                    type="radio"
                    name="reserve"
                    disabled={locked}
                    checked={effectiveReserve === c.key}
                    onChange={() => setReserve(c.key)}
                  />
                  Reserve (back to hand unless you become Patron)
                </label>
              )}
            </div>
          );
        })}
      </div>

      <div className="mt-4 rounded border border-slate-700 bg-slate-900 p-3 text-sm text-slate-300">
        <div className="flex flex-wrap gap-x-4 gap-y-1">
          <span>
            Committing <span className="font-mono text-slate-100">{committed.length}</span>, keeping{' '}
            <span className="font-mono text-slate-100">{hand.length - committed.length}</span>
          </span>
          <span className="text-emerald-400">profit +{summary.money}</span>
          <span className="text-slate-400">push {pushText(summary.push)}</span>
          {summary.influence.states > 0 && (
            <span className="text-rose-300">
              influence for {surname('states')} {summary.influence.states}
            </span>
          )}
          {summary.influence.nation > 0 && (
            <span className="text-sky-300">
              influence for {surname('nation')} {summary.influence.nation}
            </span>
          )}
          {summary.cost > 0 && <span className={danger ? 'text-red-400' : 'text-amber-400'}>union −{summary.cost}</span>}
          {negatives > 0 && (
            <span className={wouldLead ? 'text-red-400' : 'text-slate-400'}>exposure → {myExposure}</span>
          )}
        </div>
        {wouldLead && (
          <p className="mt-1 text-xs text-red-400">
            This makes you {myExposure > rivalTop ? 'the most exposed paper' : 'joint most exposed'}. If the Union
            breaks, you lose {rules.exposure_penalty}.
          </p>
        )}
        {danger && (
          <p className="mt-1 text-xs text-red-400">
            Stability is {stability}. If the table spends it all, the Union breaks and the game ends.
          </p>
        )}
        {covered.length > 0 && !effectiveReserve && !locked && (
          <p className="mt-1 text-xs text-slate-500">No reserve marked: your most profitable coverage card will be kept.</p>
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
              disabled={busy || committed.length === 0}
              onClick={submit}
              className="rounded bg-amber-600 px-4 py-2 text-sm font-medium text-slate-950 hover:bg-amber-500 disabled:opacity-40"
            >
              Commit {committed.length} {committed.length === 1 ? 'card' : 'cards'}
            </button>
          )}
        </div>
      </div>
    </section>
  );
}
