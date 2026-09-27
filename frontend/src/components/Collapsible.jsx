import { useState } from 'react';

/**
 * A panel with a caret: ▾ open, ▸ closed. Folded, it keeps a one-line
 * `summary` so the information never fully leaves the screen. The choice is
 * remembered per browser under `storageKey`.
 *
 * `fill` lets the panel take the remaining height of a flex column (the
 * wire), scrolling its body inside itself.
 */
function readOpen(storageKey, fallback) {
  try {
    const v = localStorage.getItem(`fourthestate.fold.${storageKey}`);
    return v === null ? fallback : v === '1';
  } catch {
    return fallback;
  }
}

export default function Collapsible({ title, storageKey, summary = null, defaultOpen = true, fill = false, children }) {
  const [open, setOpen] = useState(() => readOpen(storageKey, defaultOpen));

  const toggle = () => {
    setOpen((o) => {
      try {
        localStorage.setItem(`fourthestate.fold.${storageKey}`, o ? '0' : '1');
      } catch {
        /* private browsing: the fold simply is not remembered */
      }
      return !o;
    });
  };

  return (
    <section
      className={
        fill && open
          ? 'panel flex shrink-0 flex-col px-3 py-1.5 lg:min-h-0 lg:flex-1 lg:shrink'
          : 'panel shrink-0 px-3 py-1.5'
      }
    >
      <button
        type="button"
        onClick={toggle}
        aria-expanded={open}
        className="group flex w-full shrink-0 items-center gap-2 text-left"
      >
        <span className="w-3 font-mono text-[10px] text-gold-500 transition group-hover:text-gold-300">
          {open ? '▾' : '▸'}
        </span>
        <span className="font-display text-xs font-semibold uppercase tracking-[0.3em] text-gold-300">{title}</span>
        {!open && summary && (
          <span className="ml-auto min-w-0 truncate font-mono text-[10px] uppercase tracking-[0.15em] text-cream-200/60">
            {summary}
          </span>
        )}
      </button>
      {open && <div className={fill ? 'mt-1.5 flex flex-col lg:min-h-0 lg:flex-1' : 'mt-1.5'}>{children}</div>}
    </section>
  );
}
