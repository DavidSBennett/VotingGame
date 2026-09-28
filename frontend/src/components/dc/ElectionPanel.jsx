import { THEME } from './Card.jsx';

/**
 * The election in progress, as a card with two men on it. Each man has a
 * threshold; influence of his theme, Campaign and plain influence all count
 * toward it (and, for the Washington Globe, Political influence toward
 * anyone). The first paper to reach a threshold on its turn elects that man
 * and takes the card as his Patron.
 *
 * "Your reach" is an advisory sum of the pools the server reported; the
 * Elect button appears only when the server lists the side as electable.
 */
export default function ElectionPanel({ election, pools, myTurn, canElect = [], onElect, busy, globe }) {
  if (!election) return null;
  const reach = (theme) => {
    if (!pools) return null;
    let r = (pools[theme] || 0) + (pools.campaign || 0) + (pools.gen || 0);
    if (globe && theme !== 'Political') r += pools.Political || 0;
    return r;
  };
  const side = (key) => {
    const c = election[key];
    const t = THEME[c.theme] || THEME.Political;
    const historical = election.historical_winner === key;
    const r = myTurn ? reach(c.theme) : null;
    const can = canElect.includes(key);
    return (
      <div key={key} className={can ? 'flex flex-1 flex-col border border-gold-300 bg-ink-800/80 p-3 shadow-glow' : 'flex flex-1 flex-col border border-gold-500/30 bg-ink-900/60 p-3'}>
        <div className="flex items-baseline justify-between gap-2">
          <span className={`font-mono text-[9px] uppercase tracking-[0.2em] ${t.text}`}>{c.theme}</span>
          {historical && <span className="font-mono text-[9px] uppercase tracking-[0.15em] text-cream-200/50">history's choice</span>}
        </div>
        <div className="mt-0.5 font-display text-2xl font-semibold leading-tight text-cream-50">{c.name}</div>
        <div className="mt-1 flex items-baseline justify-between font-mono text-[11px] uppercase tracking-[0.12em]">
          <span className="text-cream-200/70">
            needs <span className="text-lg text-gold-300">{c.threshold}</span>
          </span>
          {r !== null && (
            <span className={r >= c.threshold ? 'text-gold-300' : 'text-cream-200/50'}>
              your reach {r}
            </span>
          )}
        </div>
        {myTurn && (
          <button type="button" disabled={!can || busy} onClick={() => onElect(key)} className="btn-solid mt-2 py-1.5 text-xs">
            {can ? `Elect ${c.name.split(' ').slice(-1)[0]}` : 'Not enough influence'}
          </button>
        )}
      </div>
    );
  };
  return (
    <section className="panel p-3 animate-fade">
      <div className="flex flex-wrap items-baseline justify-between gap-2">
        <h2 className="font-display text-3xl font-bold leading-none text-cream-50">The Election of {election.year}</h2>
        <span className="font-mono text-[10px] uppercase tracking-[0.2em] text-cream-200/60">
          Era {election.era} · {election.index + 1} of 17 · worth <span className="text-gold-300">★{election.vp}</span> · Patron card +
          {election.patron_gen} and +{election.patron_themed} of his theme
        </span>
      </div>
      <div className="mt-2 flex flex-col gap-2 sm:flex-row">
        {side('nation')}
        {side('states')}
      </div>
    </section>
  );
}
