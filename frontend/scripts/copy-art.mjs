// Copies the 2024 cards' photographs (docs/print/art/*.jpg, the ones on the
// print-and-play cards; picked in docs/art-2024.csv) into public/art24/, where
// the game screen reads them (src/components/e24/Card24.jsx). Run by npm
// before `dev` and `build`; public/art24/ is not committed.
import { cpSync, existsSync, mkdirSync, readdirSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const from = join(here, '..', '..', 'docs', 'print', 'art');
const to = join(here, '..', 'public', 'art24');

if (!existsSync(from)) {
  console.error(`copy-art: ${from} is missing; the cards will show no photographs`);
  process.exit(1);
}
mkdirSync(to, { recursive: true });
let n = 0;
for (const f of readdirSync(from)) {
  if (!f.endsWith('.jpg')) continue;
  cpSync(join(from, f), join(to, f));
  n += 1;
}
console.log(`copy-art: ${n} photographs -> public/art24`);
