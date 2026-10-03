/**
 * The map: every contest as a tile, biggest first, coloured by the side
 * it has been claimed for (oxblood Trump, federal blue Harris) or left
 * open. The big ten carry a gilt edge (the elections deck); the one up
 * now is ringed. Hover a tile for its votes, prestige, both prices and
 * the 2024 result.
 *
 *   map       the server's 51 { key, abbr, state, ev, vp, deck, side, seat, ... }
 *   bigKey    the big state up now (or null)
 *   players   seats, to name who claimed a state
 */
export default function MapGrid({ map, bigKey, players }) {
  if (!map) return null;
  const nameOf = (seat) => {
    const p = players.find((q) => q.seat === seat);
    return p ? (p.is_you ? 'you' : p.player_name) : '';
  };
  const tiles = [...map].sort((a, b) => b.ev - a.ev || a.abbr.localeCompare(b.abbr));
  const tone = (s) =>
    s.side === 'trump'
      ? 'bg-oxblood-500 text-cream-50 border-oxblood-300/60'
      : s.side === 'harris'
        ? 'bg-federal-500 text-cream-50 border-federal-300/60'
        : 'bg-ink-950/60 text-cream-200/70 border-gold-500/20';
  return (
    <div className="flex flex-wrap gap-1">
      {tiles.map((s) => {
        const big = s.deck === 'elections';
        const up = s.key === bigKey;
        const tip =
          `${s.state}: ${s.ev} electoral votes, prestige ${s.vp}. ` +
          `Trump ${s.trump_cost} / Harris ${s.harris_cost}. ` +
          `2024: ${s.winner_2024 === 'trump' ? 'Trump' : 'Harris'} by ${Math.abs(s.margin).toFixed(1)}.` +
          (s.side ? ` Claimed for ${s.side === 'trump' ? 'Trump' : 'Harris'} by ${nameOf(s.seat)} (${s.how === 'call' ? 'called' : 'bought'}).` : big ? ' In the elections deck.' : ' In the main deck.');
        return (
          <span
            key={s.key}
            title={tip}
            className={`flex flex-col items-center justify-center border px-1 font-mono leading-none ${tone(s)} ${
              big ? 'min-w-[2.6rem] py-1 ring-1 ring-gold-500/60' : 'min-w-[2rem] py-0.5'
            } ${up ? 'ring-2 ring-gold-300 shadow-glow' : ''}`}
          >
            <span className={big ? 'text-[11px] font-bold' : 'text-[9px] font-bold'}>{s.abbr}</span>
            <span className="text-[8px] opacity-80">{s.ev}</span>
          </span>
        );
      })}
    </div>
  );
}
