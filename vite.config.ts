import { createHash } from 'node:crypto';
import { readFileSync, readdirSync, statSync, writeFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { defineConfig } from 'vite';

/**
 * Deterministic source hash of the TypeScript sources and pnpm-lock.yaml: the change detector of buildTimeLabel().
 */
function sourceBuildId(dir: string): string {
  const root = new URL(`${dir}/`, import.meta.url);
  const hash = createHash('sha256');
  const files = (readdirSync(root, { recursive: true }) as string[])
    .filter((file) => !file.includes('.test.') && statSync(new URL(file, root)).isFile())
    .sort();
  for (const file of files) {
    hash.update(file).update(readFileSync(new URL(file, root)));
  }
  hash.update(readFileSync(new URL('pnpm-lock.yaml', import.meta.url)));

  return hash.digest('hex').slice(0, 12);
}

/**
 * Readable, reproducible build time of the "script loaded" console line (REQ-ASSETS-005), e.g. "2026-11-23 14:05 UTC".
 * The committed `build-info.json` ({sourceHash, builtAt}) keeps it stable: unchanged sources reuse the stored
 * `builtAt` (a rebuild never rewrites the committed assets); changed sources store the new hash with the current UTC
 * minute. Commit `build-info.json` with the rebuilt assets.
 */
function buildTimeLabel(dir: string): string {
  const sourceHash = sourceBuildId(dir);
  const file = new URL('build-info.json', import.meta.url);
  let info: { sourceHash?: string; builtAt?: string } = {};
  try {
    info = JSON.parse(readFileSync(file, 'utf8')) as typeof info;
  } catch {
    // Missing or unreadable: written below.
  }
  if (info.sourceHash !== sourceHash || typeof info.builtAt !== 'string') {
    info = { sourceHash, builtAt: `${new Date().toISOString().slice(0, 16)}Z` };
    writeFileSync(file, `${JSON.stringify(info, null, 2)}\n`);
    console.warn(`build-info.json updated (${info.builtAt}): commit it together with the rebuilt assets.`);
  }

  return (info.builtAt as string).replace('T', ' ').replace('Z', ' UTC');
}

/** The entry scripts: Vite builds one IIFE per run (`vite build --mode <entry>`). */
const ENTRIES = {
  bell: { entry: 'src/Resources/assets/src/iife.ts', file: 'nowo-notify-kit-bell.js', name: 'NowoNotifyKit' },
  preferences: { entry: 'src/Resources/assets/src/preferences-iife.ts', file: 'nowo-notify-kit-preferences.js', name: 'NowoNotifyKitPreferences' },
} as const;

/**
 * Library IIFEs for `assets:install` (REQ-ASSETS-004) under `src/Resources/public/js/`, served through the
 * `nowo_notify_kit` asset package: `vite build` (production mode) builds the bell, `vite build --mode preferences` the
 * preferences script (`pnpm run build` runs both).
 */
export default defineConfig(({ mode }) => {
  const target = mode === 'preferences' ? ENTRIES.preferences : ENTRIES.bell;

  return {
    define: mode === 'preferences' ? { __NOTIFY_KIT_BUILD_TIME__: JSON.stringify(buildTimeLabel('src/Resources/assets/src')) } : {},
    build: {
      outDir: 'src/Resources/public/js',
      emptyOutDir: false,
      lib: {
        entry: resolve(__dirname, target.entry),
        name: target.name,
        formats: ['iife'],
        fileName: () => target.file,
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
  };
});
