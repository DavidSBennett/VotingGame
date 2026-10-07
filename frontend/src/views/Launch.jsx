/**
 * The launch page: choose which election to play. Each choice opens that
 * game's lobby (views/Lobby.jsx): the 2024 game (backend/engine_2024.php)
 * or the 1796-1860 game (backend/engine_dc.php).
 */
const ELECTIONS = [
  {
    engine: '2024',
    years: '2024',
    title: 'Trump v. Harris',
    line: 'Fifty-one contests, two candidates, one finish line at 270.',
    detail: 'Claim the states, stake your bets on a candidate, and decide when the race ends. Only what you staked on the winner scores.',
    tag: 'In development',
  },
  {
    engine: 'dc',
    years: '1796 – 1860',
    title: 'Adams to Lincoln',
    line: 'Seventeen elections of the early republic, one after another.',
    detail: 'Buy the news, run the stories, make the presidents. The most honoured paper when 1860 is decided wins.',
    tag: 'The early republic',
  },
];

export default function Launch({ onChoose }) {
  return (
    <div className="mx-auto max-w-5xl px-4 pb-16 pt-10">
      <header className="text-center animate-fade">
        <div className="font-mono text-[10px] uppercase tracking-[0.4em] text-gold-500">A card game of the press and the presidency</div>
        <h1 className="mt-3 font-display text-6xl font-bold leading-none text-cream-50 sm:text-7xl">The Fourth Estate</h1>
        <p className="mx-auto mt-4 max-w-2xl font-display text-xl italic text-gold-300">Choose an election to cover.</p>
        <div className="mx-auto mt-6 flex max-w-xs items-center gap-3">
          <span className="h-px flex-1 bg-gold-500/50" />
          <span className="text-xs text-gold-500">◆</span>
          <span className="h-px flex-1 bg-gold-500/50" />
        </div>
      </header>

      <div className="mt-10 grid gap-6 md:grid-cols-2">
        {ELECTIONS.map((e) => (
          <button
            key={e.engine}
            type="button"
            onClick={() => onChoose(e.engine)}
            className="panel group flex flex-col p-6 text-left transition animate-rise hover:border-gold-300"
          >
            <div className="label">{e.tag}</div>
            <div className="mt-2 font-display text-5xl font-bold leading-none text-cream-50">{e.years}</div>
            <div className="mt-2 font-display text-2xl italic text-gold-300">{e.title}</div>
            <p className="mt-4 font-serif text-base text-cream-100">{e.line}</p>
            <p className="mt-2 flex-1 font-serif text-sm italic text-cream-200/60">{e.detail}</p>
            <span className="btn-solid mt-6 text-center group-hover:bg-cream-50">Play {e.years}</span>
          </button>
        ))}
      </div>
    </div>
  );
}
