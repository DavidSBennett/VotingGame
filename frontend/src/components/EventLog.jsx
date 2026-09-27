/**
 * The wire: the tail of vg_event_log, newest first, like dispatches pinned
 * to the newsroom wall. The same rows the export contains.
 */
export default function EventLog({ events, fill = false, bare = false }) {
  const rows = [...(events || [])].reverse();
  if (bare) {
    // Inside a Collapsible that supplies the frame and the title.
    return rows.length === 0 ? (
      <p className="text-center font-serif text-sm italic text-cream-200/50">Nothing has happened yet.</p>
    ) : (
      <ul className="max-h-80 space-y-2 overflow-y-auto pr-1 lg:max-h-none lg:min-h-0 lg:flex-1">
        {rows.map((e) => (
          <li key={e.event_id} className="border-b border-gold-500/15 pb-2">
            <span className="mr-2 font-mono text-[9px] uppercase tracking-[0.15em] text-gold-500">
              {e.round_number ? `R${e.round_number}` : '—'}
            </span>
            <span className="font-serif text-[13px] leading-snug text-cream-100/90">{e.message || e.event_type}</span>
          </li>
        ))}
      </ul>
    );
  }
  return (
    <section className={fill ? 'panel flex flex-col px-3 py-2 lg:min-h-0 lg:flex-1' : 'panel p-4'}>
      <div className="section-title mb-2 shrink-0">The wire</div>
      {rows.length === 0 ? (
        <p className="text-center font-serif text-sm italic text-cream-200/50">Nothing has happened yet.</p>
      ) : (
        <ul className={fill ? 'max-h-80 space-y-2 overflow-y-auto pr-1 lg:max-h-none lg:min-h-0 lg:flex-1' : 'max-h-80 space-y-2 overflow-y-auto pr-1'}>
          {rows.map((e) => (
            <li key={e.event_id} className="border-b border-gold-500/15 pb-2">
              <span className="mr-2 font-mono text-[9px] uppercase tracking-[0.15em] text-gold-500">
                {e.round_number ? `R${e.round_number}` : '—'}
              </span>
              <span className="font-serif text-[13px] leading-snug text-cream-100/90">{e.message || e.event_type}</span>
            </li>
          ))}
        </ul>
      )}
    </section>
  );
}
