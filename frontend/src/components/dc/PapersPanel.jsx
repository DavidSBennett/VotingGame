/**
 * Every paper at the table: its newspaper and ability, prestige, offices
 * won, cards, media events in play, and who is on turn.
 */
export default function PapersPanel({ players, botLevel }) {
  return (
    <ul className="space-y-2">
      {players.map((p) => (
        <li
          key={p.seat}
          className={
            p.on_turn
              ? 'border-l-2 border-gold-300 bg-ink-800/60 pl-2 pr-1 py-1'
              : p.is_you
                ? 'border-l-2 border-cream-200/40 pl-2 pr-1 py-1'
                : 'border-l-2 border-transparent pl-2 pr-1 py-1'
          }
        >
          <div className="flex items-baseline justify-between gap-2">
            <span className="font-display text-base leading-tight text-cream-50">
              {p.player_name}
              {p.is_you && <span className="ml-1.5 font-mono text-[9px] uppercase tracking-[0.2em] text-gold-300">you</span>}
              {p.on_turn && <span className="ml-1.5 font-mono text-[9px] uppercase tracking-[0.2em] text-gold-300">▶ on turn</span>}
            </span>
            <span className="font-mono text-sm text-gold-300" title="Prestige: the score">
              ★{p.prestige}
            </span>
          </div>
          {p.paper && (
            <div className="font-serif text-[12px] leading-snug text-cream-200/80" title={p.paper.ability}>
              <span className="italic text-gold-400">{p.paper.name}</span> — {p.paper.ability}
            </div>
          )}
          <div className="mt-0.5 flex flex-wrap gap-x-3 font-mono text-[9px] uppercase tracking-[0.12em] text-cream-200/60">
            <span>offices {p.elections}</span>
            <span>hand {p.hand_count}</span>
            <span>deck {p.deck_count}</span>
            <span>discard {p.discard_count}</span>
            <span>bought {p.bought}</span>
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
