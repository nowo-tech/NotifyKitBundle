import { resolve } from 'node:path';
import { defineConfig } from 'vite';

/**
 * Library IIFE for `assets:install` (REQ-ASSETS-004). Output: `src/Resources/public/js/nowo-notify-kit-bell.js`,
 * served through the `nowo_notify_kit` asset package (`asset('js/nowo-notify-kit-bell.js', 'nowo_notify_kit')`).
 */
export default defineConfig({
  build: {
    outDir: 'src/Resources/public/js',
    emptyOutDir: false,
    lib: {
      entry: resolve(__dirname, 'src/Resources/assets/src/iife.ts'),
      name: 'NowoNotifyKit',
      formats: ['iife'],
      fileName: () => 'nowo-notify-kit-bell.js',
    },
    rollupOptions: {
      output: {
        inlineDynamicImports: true,
      },
    },
    minify: true,
    sourcemap: false,
    target: 'es2022',
  },
});
