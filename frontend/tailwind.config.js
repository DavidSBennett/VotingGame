/** @type {import('tailwindcss').Config} */
//
// THE FOURTH ESTATE — visual language borrowed from The Historians
// (thehistorians.org): deep teal board, cream parchment, gold hairlines,
// oxblood for danger; Cormorant Garamond display, Spectral body, JetBrains
// Mono for small tracked labels. Square corners throughout.
//
// The two sides get their own inks: States in oxblood, Nation in a muted
// federal blue, so a glance at any card, zone or track says whose it is.
//
// RULE: literal class strings only in JSX — never `bg-${color}-500`.
// Tailwind scans source text, so an interpolated class name is simply
// absent from the built CSS and the element renders unstyled.
export default {
  content: ['./index.html', './src/**/*.{js,jsx}'],
  theme: {
    extend: {
      colors: {
        ink: {
          950: '#0c1f22',
          900: '#143138',
          800: '#1b3f47',
          700: '#27525b',
        },
        gold: {
          700: '#665015',    // from The Historians: card borders
          600: '#8e6f24',
          500: '#b8923a',
          400: '#c9a652',
          300: '#d8b968',
        },
        cream: {
          50: '#fdf8ea',
          100: '#f4ead0',
          200: '#e6d4a8',
          300: '#cdb888',
          400: '#b8a36a',
        },
        oxblood: {
          900: '#4a1212',
          700: '#6b1e1e',
          500: '#9b3a2e',
          300: '#d98a78',
        },
        federal: {
          900: '#18293b',
          700: '#2c4a68',
          500: '#4f7398',
          300: '#9dbad6',
        },
        wood: {
          950: '#1d120a',
          900: '#2a1a0f',
          800: '#3a2415',
          700: '#4e311c',
        },
      },
      fontFamily: {
        display: ['"Cormorant Garamond"', 'Georgia', 'serif'],
        serif: ['Spectral', 'Georgia', 'serif'],
        mono: ['"JetBrains Mono"', 'ui-monospace', 'monospace'],
      },
      boxShadow: {
        card: '0 1px 2px rgba(0,0,0,0.45), 0 6px 14px rgba(0,0,0,0.35)',
        lift: '0 4px 8px rgba(0,0,0,0.45), 0 18px 32px rgba(0,0,0,0.45)',
        glow: '0 0 0 1px rgba(216,185,104,0.55), 0 0 24px rgba(216,185,104,0.25)',
        // The Historians' cards: paper with weight on the teal ground.
        'card-hover': '0 8px 16px rgba(0,0,0,0.55), 0 2px 4px rgba(0,0,0,0.7)',
        'card-lift': '0 16px 32px rgba(0,0,0,0.6), 0 4px 8px rgba(0,0,0,0.7)',
      },
      transitionTimingFunction: {
        desk: 'cubic-bezier(0.22, 1, 0.36, 1)',
      },
      keyframes: {
        rise: {
          '0%': { opacity: '0', transform: 'translateY(8px)' },
          '100%': { opacity: '1', transform: 'translateY(0)' },
        },
        fade: {
          '0%': { opacity: '0' },
          '100%': { opacity: '1' },
        },
      },
      animation: {
        rise: 'rise 0.35s ease-out both',
        fade: 'fade 0.25s ease-out both',
      },
    },
  },
  plugins: [],
};
