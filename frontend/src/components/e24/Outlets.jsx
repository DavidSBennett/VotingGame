/**
 * Every outlet at the table: its newspaper and ability, how many cards it
 * has staked (never which, or on whom), the votes it has claimed, its
 * cards, media events in play, and who is on turn.
 */
export default function Outlets({ players, botLevel }) {
  return (
    <ul className="space-y-2">
      {players.map((p) => (
        <li
          key={p.seat}
          className={
            p.on_turn
              ? 'border-l-2 border-gold-300 bg-ink-800/60 py-1 pl-2 pr-1'
              : p.is_you
                ? 'border-l-2 border-cream-200/40 py-1 pl-2 pr-1'
                : 'border-l-2 border-transparent py-1 pl-2 pr-1'
          }
        >
          <div className="flex items-baseline justify-between gap-2">
            <span className="font-display text-base leading-tight text-cream-50">
              {p.player_name}
              {p.is_you && <span className="ml-1.5 font-mono text-[9px] uppercase tracking-[0.2em] text-gold-300">you</span>}
              {p.on_turn && <span className="ml-1.5 font-mono text-[9px] uppercase tracking-[0.2em] text-gold-300">▶ on turn</span>}
            </span>
            <span className="font-mono text-sm text-gold-300" title="Cards staked, face down">
              {p.stakes} staked
            </span>
          </div>
          {p.paper && (
            <div className="font-serif text-[12px] leading-snug text-cream-200/80" title={p.paper.ability}>
              <span className="italic text-gold-400">{p.paper.name}</span> — {p.paper.ability}
            </div>
          )}
          <div className="mt-0.5 flex flex-wrap gap-x-3 font-mono text-[9px] uppercase tracking-[0.12em] text-cream-200/60">
            <span title="Electoral votes this outlet has claimed, for either side">claimed {p.ev_claimed} EV</span>
            <span>big {p.called}</span>
            <span>states {p.states_bought}</span>
            <span>hand {p.hand_count}</span>
            <span>deck {p.deck_count}</span>
            <span>discard {p.discard_count}</span>
            {p.scandals_taken > 0 && <span className="text-oxblood-300">Scandals {p.scandals_taken}</span>}
            {p.is_bot && <span className="text-cream-200/30">rival · {botLevel}</span>}
            {p.conceded && <span className="text-cream-200/30">left</span>}
          </div>
          {p.locations.length > 0 && (
            <div className="mt-0.5 flex flex-wrap gap-1">
              {p.locations.map((c) => (
                <span key={c.key} className="bg-wood-700 px-1 font-mono text-[8px] uppercase tracking-[0.1em] text-cream-100" title={c.card_text}>
                  {c.name}
                </span>
              ))}
            </div>
          )}
        </li>
      ))}
    </ul>
  );
}

/** Your stake pile: only you see it until the end. */
export function StakePile({ staked, race }) {
  if (!staked || staked.length === 0) {
    return <p className="font-serif text-[12px] italic text-cream-200/60">Nothing staked yet. Only staked cards score.</p>;
  }
  const sum = (side) => staked.filter((s) => s.side === side).reduce((n, s) => n + s.card.vp, 0);
  return (
    <div>
      <p className="font-mono text-[9px] uppercase tracking-[0.15em] text-cream-200/60">
        On Trump <span className="text-oxblood-300">${sum('trump')}</span> · on Harris <span className="text-federal-300">${sum('harris')}</span>
        {race && race.final ? ` · ${race.winner_side === 'trump' ? 'Trump' : 'Harris'} has won` : ''}
      </p>
      <ul className="mt-1 space-y-0.5">
        {staked.map((s, i) => (
          <li key={i} className="flex items-baseline justify-between gap-2 font-serif text-[12px] text-cream-100">
            <span>{s.card.name}</span>
            <span className={s.side === 'trump' ? 'font-mono text-[10px] text-oxblood-300' : 'font-mono text-[10px] text-federal-300'}>
              ${s.card.vp} on {s.side === 'trump' ? 'Trump' : 'Harris'}
            </span>
          </li>
        ))}
      </ul>
    </div>
  );
}
