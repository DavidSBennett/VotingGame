/**
 * The in-game feed: the tail of vg_event_log, oldest first.
 *
 * Same rows the export contains, so what a player saw during the game and
 * what I read afterwards are the same record.
 */
export default function EventLog({ events }) {
  return (
    <div className="rounded border border-slate-700 bg-slate-800 p-4">
      <h2 className="mb-2 text-sm uppercase tracking-wide text-slate-400">Log</h2>
      {(!events || events.length === 0) && (
        <p className="text-sm text-slate-500">Nothing has happened yet.</p>
      )}
      <ul className="max-h-96 space-y-1 overflow-y-auto text-sm">
        {(events || []).map((e) => (
          <li key={e.event_id} className="border-b border-slate-700 pb-1 text-slate-300">
            <span className="mr-2 font-mono text-xs text-slate-500">
              {e.round_number ? `r${e.round_number}` : '—'}
            </span>
            {e.message || e.event_type}
          </li>
        ))}
      </ul>
    </div>
  );
}
