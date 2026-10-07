/**
 * The race to 270: Trump's claimed electoral votes from the left in
 * oxblood, Harris's from the right in federal blue, the unclaimed votes
 * between, and a gilt line at 270 for each. Only claimed states count.
 *
 * `race` is the server's { trump, harris, win_at, final, winner_side }.
 */
export default function RaceBar({ race }) {
  if (!race) return null;
  const total = 538;
  const t = (100 * race.trump) / total;
  const h = (100 * race.harris) / total;
  const mark = (100 * race.win_at) / total;
  const name = (s) => (s === 'trump' ? 'Trump' : 'Harris');
  return (
    <section className="panel px-3 py-2">
      <div className="flex items-baseline justify-between gap-3">
        <span className="font-display text-2xl font-bold leading-none text-oxblood-300">
          Trump <span className="font-mono text-xl">{race.trump}</span>
        </span>
        <span className="text-center font-mono text-[9px] uppercase tracking-[0.2em] text-cream-200/60">
          {race.final
            ? `${name(race.winner_side)} has ${race.win_at}: the round plays out, then the stakes are revealed`
            : `${total - race.trump - race.harris} electoral votes unclaimed · ${race.win_at} to win`}
          {race.house && (race.house.trump || race.house.harris) ? (
            <span className="block normal-case tracking-normal" title="House seats bought with their states: they decide a 269-269 tie">
              House: Trump {race.house.trump} · Harris {race.house.harris} seats
            </span>
          ) : null}
        </span>
        <span className="font-display text-2xl font-bold leading-none text-federal-300">
          <span className="font-mono text-xl">{race.harris}</span> Harris
        </span>
      </div>
      <div className="relative mt-1.5 h-4 w-full overflow-hidden border border-gold-500/40 bg-ink-950/60">
        <div className="absolute inset-y-0 left-0 bg-oxblood-500 transition-all duration-500" style={{ width: `${t}%` }} />
        <div className="absolute inset-y-0 right-0 bg-federal-500 transition-all duration-500" style={{ width: `${h}%` }} />
        <div className="absolute inset-y-0 w-px bg-gold-300" style={{ left: `${mark}%` }} title="270 for Trump" />
        <div className="absolute inset-y-0 w-px bg-gold-300" style={{ right: `${mark}%` }} title="270 for Harris" />
      </div>
    </section>
  );
}
