import { THEME } from './Card.jsx';

/**
 * The election in progress, as a card with two candidates on it. Each has a
 * threshold; influence of his theme, Campaign and plain influence all count
 * toward it (and, for the Washington Globe, Political influence toward
 * anyone). The first paper to reach a threshold on its turn elects that candidate
 * and takes the card as his Patron.
 *
 * "Your reach" is an advisory sum of the pools the server reported; the
 * Elect button appears only when the server lists the side as electable.
 */
export default function ElectionPanel({ election, pools, myTurn, canElect = [], onElect, busy, globe, lastFa = null }) {
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
      <div key={key} className={can ? 'flex flex-1 flex-col border border-gold-300 bg-ink-800/80 px-3 py-2 shadow-glow' : 'flex flex-1 flex-col border border-gold-500/30 bg-ink-900/60 px-3 py-2'}>
        <div className="flex items-baseline justify-between gap-2 font-mono text-[9px] uppercase tracking-[0.18em]">
          <span className={t.text}>{c.theme}</span>
          {historical && <span className="text-cream-200/50">history's choice</span>}
        </div>
        <div className="flex items-baseline justify-between gap-2">
          <span className="font-display text-xl font-semibold leading-tight text-cream-50">{c.name}</span>
          <span className="shrink-0 font-mono text-[10px] uppercase tracking-[0.12em] text-cream-200/70">
            needs <span className="text-base text-gold-300">{c.threshold}</span>
          </span>
        </div>
        {myTurn && (
          <div className="mt-1 flex items-center justify-between gap-2">
            <span className={r >= c.threshold ? 'font-mono text-[10px] uppercase tracking-[0.12em] text-gold-300' : 'font-mono text-[10px] uppercase tracking-[0.12em] text-cream-200/50'}>
              your reach {r}
            </span>
            {can && (
              <button type="button" disabled={busy} onClick={() => onElect(key)} className="btn-solid px-3 py-1 text-xs">
                Elect {c.name.split(' ').slice(-1)[0]}
              </button>
            )}
          </div>
        )}
      </div>
    );
  };
  return (
    <section className="panel px-3 py-2 animate-fade">
      <div className="flex flex-wrap items-baseline justify-center gap-x-4 gap-y-0.5 text-center">
        <h2 className="font-display text-2xl font-bold leading-none text-cream-50">The Election of {election.year}</h2>
        <span className="font-mono text-[9px] uppercase tracking-[0.18em] text-cream-200/60">
          Era {election.era} · {election.index + 1} of 17 · worth <span className="text-gold-300">★{election.vp}</span> · Patron card +
          {election.patron_gen} and +{election.patron_themed} of the candidate's theme
        </span>
      </div>
      <div className="mt-1.5 flex flex-col gap-2 sm:flex-row">
        {side('nation')}
        {side('states')}
      </div>
      <div className="mt-1.5 grid gap-1 text-center sm:grid-cols-2">
        <p className="font-serif text-[13px] leading-snug text-cream-100" title="What this card does in your deck once you win it">
          <span className="label mr-1.5 text-gold-400">{election.card_name}</span>
          {election.power_text}
        </p>
        {election.fa_name && (
          <p
            className={
              lastFa && lastFa.year === election.year
                ? 'border-l-2 border-oxblood-500 pl-2 font-serif text-[13px] italic leading-snug text-oxblood-300'
                : 'font-serif text-[13px] italic leading-snug text-cream-200/60'
            }
            title="First appearance: what happened to every paper when this campaign opened"
          >
            <span className="label mr-1.5 text-oxblood-300">First appearance · {election.fa_name}</span>
            {election.fa_text}
          </p>
        )}
      </div>
    </section>
  );
}
