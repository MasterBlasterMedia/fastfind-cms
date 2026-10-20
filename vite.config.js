import { defineConfig } from 'vite';
import vue from '@vitejs/plugin-vue';
import { writeFileSync, existsSync, unlinkSync } from 'node:fs';
import { resolve } from 'node:path';

const hotFile = resolve('public/hot');
export default defineConfig({
  plugins: [vue(), {
    name: 'laravel-hot-file',
    configureServer(server) {
      server.httpServer?.once('listening', () => writeFileSync(hotFile, 'http://127.0.0.1:5173'));
      server.httpServer?.once('close', () => { if (existsSync(hotFile)) unlinkSync(hotFile); });
    },
    buildStart() { if (process.env.NODE_ENV === 'production' && existsSync(hotFile)) unlinkSync(hotFile); },
  }],
  publicDir: false,
  base: '/build/',
  build: {
    manifest: 'manifest.json',
    outDir: 'public/build',
    emptyOutDir: true,
    rollupOptions: { input: ['resources/js/app.js','resources/scss/app.scss'] },
  },
  server: { host:'127.0.0.1', port:5173, strictPort:true },
});
