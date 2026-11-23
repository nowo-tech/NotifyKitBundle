/**
 * Console logger of the NotifyKitBundle scripts (REQ-ASSETS-005, reference: IconSelectorBundle `logger.ts`).
 *
 * Every shipped entry script calls `scriptLoaded()` once at startup:
 *
 *     📦 [notify-kit/preferences] script loaded, build: 2026-11-23 14:05 UTC
 *
 * The build value is the readable, reproducible `builtAt` of `build-info.json`, injected by Vite `define`. A script
 * loaded twice (Turbo, a fragment, two copies) logs once. Nothing is printed when the page asked for a quiet console:
 * `<html data-nowo-console="quiet">` or `globalThis.NOWO_CONSOLE = 'quiet'`. Every other output (`debug`, `info`,
 * `warn`, `error`) stays behind `setDebug(true)`; no user data, URL or configuration value is ever logged by
 * `scriptLoaded()`.
 */

export type BundleLoggerOptions = {
  /** Shown after "build:" by scriptLoaded() (the build-info.json `builtAt`). */
  buildTime?: string;
};

export type BundleLogger = {
  /** Logs the "script loaded" line once per page (quiet switch honoured). */
  scriptLoaded: () => void;
  /** Turns the level methods on or off (off by default). */
  setDebug: (enabled: boolean) => void;
  debug: (...args: unknown[]) => void;
  warn: (...args: unknown[]) => void;
  error: (...args: unknown[]) => void;
};

const LOADED_KEY = '__nowoNotifyKitLoaded';

/** True when the page asked for a silent console. */
export function isQuiet(): boolean {
  try {
    if ((globalThis as { NOWO_CONSOLE?: unknown }).NOWO_CONSOLE === 'quiet') {
      return true;
    }

    return typeof document !== 'undefined' && document.documentElement.dataset.nowoConsole === 'quiet';
  } catch {
    return false;
  }
}

/** Remembers, for the page, which script names already logged their line; true the first time for $name. */
function firstLoad(name: string): boolean {
  const store = globalThis as unknown as Record<string, Set<string> | undefined>;
  const loaded = store[LOADED_KEY] ?? new Set<string>();
  store[LOADED_KEY] = loaded;
  if (loaded.has(name)) {
    return false;
  }
  loaded.add(name);

  return true;
}

/**
 * Creates the logger of one script ($name, e.g. `notify-kit/preferences`).
 */
export function createBundleLogger(name: string, options: BundleLoggerOptions = {}): BundleLogger {
  const prefix = `[${name}]`;
  let debug = false;

  return {
    scriptLoaded(): void {
      if (isQuiet() || !firstLoad(name)) {
        return;
      }
      const build = options.buildTime ? `, build: ${options.buildTime}` : '';
      console.log(`📦 ${prefix} script loaded${build}`);
    },
    setDebug(enabled: boolean): void {
      debug = enabled;
    },
    debug(...args: unknown[]): void {
      if (debug) {
        console.debug(`🔍 ${prefix}`, ...args);
      }
    },
    warn(...args: unknown[]): void {
      if (debug) {
        console.warn(`⚠️ ${prefix}`, ...args);
      }
    },
    error(...args: unknown[]): void {
      if (debug) {
        console.error(`❌ ${prefix}`, ...args);
      }
    },
  };
}
