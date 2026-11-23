import { afterEach, describe, expect, it, vi } from 'vitest';
import { createBundleLogger, isQuiet } from '../src/logger';

afterEach(() => {
  delete (globalThis as { NOWO_CONSOLE?: unknown }).NOWO_CONSOLE;
  delete document.documentElement.dataset.nowoConsole;
  delete (globalThis as Record<string, unknown>).__nowoNotifyKitLoaded;
});

describe('bundle logger (REQ-ASSETS-005)', () => {
  it('logs the script-loaded line once per script, with the build', () => {
    const log = vi.spyOn(console, 'log').mockImplementation(() => undefined);
    createBundleLogger('notify-kit/test', { buildTime: '2026-11-23 14:05 UTC' }).scriptLoaded();
    createBundleLogger('notify-kit/test', { buildTime: '2026-11-23 14:05 UTC' }).scriptLoaded();
    createBundleLogger('notify-kit/other').scriptLoaded();
    expect(log.mock.calls).toEqual([['📦 [notify-kit/test] script loaded, build: 2026-11-23 14:05 UTC'], ['📦 [notify-kit/other] script loaded']]);
  });

  it('stays quiet with <html data-nowo-console="quiet">', () => {
    const log = vi.spyOn(console, 'log').mockImplementation(() => undefined);
    document.documentElement.dataset.nowoConsole = 'quiet';
    expect(isQuiet()).toBe(true);
    createBundleLogger('notify-kit/quiet').scriptLoaded();
    expect(log).not.toHaveBeenCalled();
  });

  it('stays quiet with globalThis.NOWO_CONSOLE = "quiet"', () => {
    const log = vi.spyOn(console, 'log').mockImplementation(() => undefined);
    (globalThis as { NOWO_CONSOLE?: unknown }).NOWO_CONSOLE = 'quiet';
    createBundleLogger('notify-kit/quiet2').scriptLoaded();
    expect(log).not.toHaveBeenCalled();
  });

  it('treats an unreadable document as not quiet', () => {
    const spy = vi.spyOn(document, 'documentElement', 'get').mockImplementation(() => {
      throw new Error('no document');
    });
    expect(isQuiet()).toBe(false);
    spy.mockRestore();
  });

  it('keeps every other level behind the debug switch', () => {
    const debug = vi.spyOn(console, 'debug').mockImplementation(() => undefined);
    const warn = vi.spyOn(console, 'warn').mockImplementation(() => undefined);
    const error = vi.spyOn(console, 'error').mockImplementation(() => undefined);
    const logger = createBundleLogger('notify-kit/levels');
    logger.debug('a');
    logger.warn('b');
    logger.error('c');
    expect([debug, warn, error].map((spy) => spy.mock.calls.length)).toEqual([0, 0, 0]);
    logger.setDebug(true);
    logger.debug('a');
    logger.warn('b');
    logger.error('c');
    expect([debug, warn, error].map((spy) => spy.mock.calls.length)).toEqual([1, 1, 1]);
  });
});
