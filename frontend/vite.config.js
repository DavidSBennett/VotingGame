import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';

// In production the built app and the PHP endpoints are served from the
// same origin (the subdomain docroot), so the API base is just '/'.
// In dev, Vite proxies /api/* to the live install so `npm run dev` talks
// to the real backend without a local PHP runtime — there isn't one.
// 2024 BRANCH: dev talks to the 2024 install, never the real game or the variant.
const LIVE_ORIGIN = 'https://2024.davidsbennett.com';

export default defineConfig({
  plugins: [react()],
  base: '/',
  server: {
    port: 5173,
    proxy: {
      '/api': {
        target: LIVE_ORIGIN,
        changeOrigin: true,
        secure: true,
        rewrite: (path) => path.replace(/^\/api/, ''),
      },
    },
  },
  build: {
    outDir: 'dist',
    sourcemap: false,
  },
});
