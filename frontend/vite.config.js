import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';

// Dev: the SPA and API share an origin through this proxy, so the Sanctum session cookie is first-party and no CORS is needed.
const API = process.env.VITE_API_TARGET || 'http://127.0.0.1:8000';
export default defineConfig({
  plugins: [react()],
  server: { port: 5173, strictPort: true, proxy: { '/api': { target: API, changeOrigin: false }, '/sanctum': { target: API, changeOrigin: false } } },
  preview: { port: 5173, strictPort: true, proxy: { '/api': { target: API, changeOrigin: false }, '/sanctum': { target: API, changeOrigin: false } } },
  build: { sourcemap: false, chunkSizeWarningLimit: 600 },
  test: { environment: 'jsdom', globals: true, setupFiles: './src/test/setup.js', include: ['src/**/*.test.{js,jsx}'] },
});
