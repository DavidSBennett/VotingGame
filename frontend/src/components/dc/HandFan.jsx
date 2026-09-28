/**
 * The hand as a centered, fanned spread -- ported from The Historians'
 * BoardHandFan (Historians_(Board_Game)/board/frontend/src/components/
 * BoardHandFan.jsx), without its tag-flag strip.
 *
 * Cards sit side by side when there is room and fold into an overlapping fan
 * as the hand grows, rather than wrapping onto a second row; the spread is
 * centered in the space it has. The leftmost card is on top; the card under
 * the cursor (or focus) rises above its neighbours so it reads whole.
 */
import { Children, useLayoutEffect, useRef, useState } from 'react';

const CARD_W = 112;         // the sm Card (w-28) -- keep in step with it
const MAX_PITCH = CARD_W + 10;
const MIN_PITCH = 40;
const LIFT = 14;
export const ROW_H = 176 + LIFT + 8;   // the card's h-44 plus room for the lift

export default function HandFan({ children }) {
  const items = Children.toArray(children);
  const n = items.length;
  const ref = useRef(null);
  const [width, setWidth] = useState(0);
  const [active, setActive] = useState(-1);

  useLayoutEffect(() => {
    const el = ref.current;
    if (!el) return undefined;
    const measure = () => setWidth(el.offsetWidth);
    measure();
    if (typeof ResizeObserver === 'undefined') return undefined;
    const ro = new ResizeObserver(measure);
    ro.observe(el);
    return () => ro.disconnect();
  }, []);

  let pitch = MAX_PITCH;
  if (n > 1 && width > 0) {
    const fit = (width - CARD_W) / (n - 1);
    pitch = Math.max(MIN_PITCH, Math.min(MAX_PITCH, fit));
  }
  const used = n > 0 ? (n - 1) * pitch + CARD_W : 0;
  const offset = Math.max(0, (width - used) / 2);

  return (
    <div ref={ref} style={{ position: 'relative', height: ROW_H, width: '100%' }}>
      {items.map((child, i) => {
        const isActive = i === active;
        return (
          <div
            key={child.key ?? i}
            onMouseEnter={() => setActive(i)}
            onMouseLeave={() => setActive((cur) => (cur === i ? -1 : cur))}
            onFocus={() => setActive(i)}
            onBlur={() => setActive((cur) => (cur === i ? -1 : cur))}
            style={{
              position: 'absolute',
              left: Math.round(offset + i * pitch),
              top: LIFT,
              zIndex: isActive ? n + 100 : n - i,
              transform: isActive ? `translateY(-${LIFT}px)` : 'none',
              transition: 'transform 140ms cubic-bezier(0, 0, 0.2, 1)',
            }}
          >
            {child}
          </div>
        );
      })}
    </div>
  );
}
