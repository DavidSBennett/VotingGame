/** @type {import('tailwindcss').Config} */
//
// PLACEHOLDER THEME. Neutral slate + amber until the game has a theme to
// dress it in. Replace the palette wholesale once the design settles.
//
// RULE: literal class strings only in JSX — never `bg-${color}-500`.
// Tailwind scans source text, so an interpolated class name is simply
// absent from the built CSS and the element renders unstyled.
export default {
  content: ['./index.html', './src/**/*.{js,jsx}'],
  theme: {
    extend: {
      fontFamily: {
        sans: ['"IBM Plex Sans"', 'system-ui', 'sans-serif'],
        mono: ['"JetBrains Mono"', 'ui-monospace', 'monospace'],
      },
      boxShadow: {
        panel: '0 2px 8px rgba(0,0,0,0.35), 0 1px 2px rgba(0,0,0,0.5)',
      },
    },
  },
  plugins: [],
};
