import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { MAX_RETRY_MS, NotifyKitPreferences, attachAll } from '../src/preferences';

function cell(id: string, context: string, category: string, channel: string, checked: boolean, token = '-'): string {
  return `<input type="checkbox" id="${id}" data-nowo-notify-kit-cell data-context="${context}" data-category="${category}" data-channel="${channel}" data-token-id="k${id}"${checked ? ' checked' : ''}><input type="hidden" id="k${id}" value="${token}">`;
}

function gridHtml(prefix = 'p', context = ''): string {
  return `<div data-nowo-notify-kit-preferences data-cells-url="/prefs/cells" data-csrf-token="tok" data-saving-label="Saving" data-saved-label="Saved" data-stale-label="Stale" data-error-label="Error" data-close-label="Close"><form>
    ${context === '' ? cell(`${prefix}w`, '', '*', '*', true) : ''}
    ${cell(`${prefix}c0`, context, 'issues', 'mail', true)}
    ${cell(`${prefix}c1`, context, 'issues', 'push', false)}
    <select data-nowo-notify-kit-scope data-context="${context}" data-category="issues"><option value="all" selected>all</option><option value="involved">involved</option></select>
    <select data-nowo-notify-kit-scope data-context="${context}" data-category="empty"><option value="all">all</option></select>
    ${context === '' ? `${cell(`${prefix}x0`, 'project:42', '*', '*', true)}<a href="/prefs/context?key=project:42" data-nowo-notify-kit-override>Customise</a>` : ''}
    <p data-nowo-notify-kit-status></p><div data-nowo-notify-kit-submit><button type="submit">Save</button></div>
  </form></div>`;
}

function response(body: unknown, init: { status?: number; type?: string; redirected?: boolean; retryAfter?: string; badJson?: boolean } = {}): Response {
  const status = init.status ?? 200;
  const headers = new Headers({ 'Content-Type': init.type ?? (typeof body === 'string' ? 'text/html; charset=UTF-8' : 'application/json') });
  if (init.retryAfter !== undefined) {
    headers.set('Retry-After', init.retryAfter);
  }

  return {
    ok: status >= 200 && status < 300,
    status,
    redirected: init.redirected ?? false,
    headers,
    json: () => (init.badJson ? Promise.reject(new Error('json')) : Promise.resolve(body)),
    text: () => Promise.resolve(String(body)),
  } as unknown as Response;
}

function mount(): { preferences: NotifyKitPreferences; root: HTMLElement } {
  document.body.innerHTML = gridHtml();
  const root = document.querySelector<HTMLElement>('[data-nowo-notify-kit-preferences]')!;

  return { preferences: NotifyKitPreferences.attach(root), root };
}

function input(id: string): HTMLInputElement {
  return document.getElementById(id) as HTMLInputElement;
}

function change(element: HTMLElement): void {
  element.dispatchEvent(new Event('change', { bubbles: true }));
}

function body(fetchMock: ReturnType<typeof vi.fn>, call = 0): { changes: Array<Record<string, unknown>> } {
  return JSON.parse((fetchMock.mock.calls[call]![1] as RequestInit).body as string) as { changes: Array<Record<string, unknown>> };
}

let fetchMock: ReturnType<typeof vi.fn>;

beforeEach(() => {
  fetchMock = vi.fn();
  vi.stubGlobal('fetch', fetchMock);
  HTMLDialogElement.prototype.showModal ??= function showModal(this: HTMLDialogElement): void {
    this.setAttribute('open', '');
  };
  HTMLDialogElement.prototype.close ??= function close(this: HTMLDialogElement): void {
    this.removeAttribute('open');
    this.dispatchEvent(new Event('close'));
  };
});

afterEach(() => {
  vi.unstubAllGlobals();
  vi.useRealTimers();
  document.body.innerHTML = '';
});

describe('preferences autosave', () => {
  it('binds once, hides the submit button and saves a cell with its token', async () => {
    const { preferences, root } = mount();
    expect(NotifyKitPreferences.attach(root)).toBe(preferences);
    expect(root.querySelector<HTMLElement>('[data-nowo-notify-kit-submit]')!.hidden).toBe(true);
    fetchMock.mockResolvedValueOnce(response({ ok: true, cells: [{ context: '', category: 'issues', channel: 'mail', enabled: false, scope: 'all', token: '0a' }] }));
    input('pc0').checked = false;
    change(input('pc0'));
    await preferences.idle();
    const [url, init] = fetchMock.mock.calls[0]!;
    expect(url).toBe('/prefs/cells');
    expect((init as RequestInit).headers).toMatchObject({ 'X-CSRF-TOKEN': 'tok', 'Content-Type': 'application/json' });
    expect(body(fetchMock).changes).toEqual([{ context: '', category: 'issues', channel: 'mail', enabled: false, scope: 'all', expected: '-' }]);
    expect(input('kpc0').value).toBe('0a');
    expect(root.querySelector('[data-nowo-notify-kit-status]')!.textContent).toBe('Saved');
    expect(input('pc0').hasAttribute('aria-busy')).toBe(false);
  });

  it('saves the switches with scope all and a scope change for every cell of the row', async () => {
    const { preferences } = mount();
    fetchMock.mockResolvedValue(response({ ok: true, cells: [] }));
    input('pw').checked = false;
    change(input('pw'));
    const select = document.querySelector<HTMLSelectElement>('select[data-category="issues"]')!;
    select.value = 'involved';
    change(select);
    const empty = document.querySelector<HTMLSelectElement>('select[data-category="empty"]')!;
    change(empty);
    await preferences.idle();
    expect(body(fetchMock, 0).changes).toEqual([{ context: '', category: '*', channel: '*', enabled: false, scope: 'all', expected: '-' }]);
    expect(body(fetchMock, 1).changes.map((c) => [c.channel, c.enabled, c.scope])).toEqual([['mail', true, 'involved'], ['push', false, 'involved']]);
    expect(fetchMock).toHaveBeenCalledTimes(2);
  });

  it('applies the current cells of a stale answer', async () => {
    const { preferences, root } = mount();
    fetchMock.mockResolvedValueOnce(response({ ok: false, error: 'stale', cells: [
      { context: '', category: 'issues', channel: 'mail', enabled: true, scope: 'involved', token: '1i' },
      { context: '', category: 'nope', channel: 'mail', enabled: true, scope: 'all', token: '1a' },
      { context: '', category: '*', channel: '*', enabled: false, scope: 'all', token: '0a' },
    ] }, { status: 409 }));
    input('pc0').checked = false;
    change(input('pc0'));
    await preferences.idle();
    expect(input('pc0').checked).toBe(true);
    expect(input('kpc0').value).toBe('1i');
    expect(document.querySelector<HTMLSelectElement>('select[data-category="issues"]')!.value).toBe('involved');
    expect(input('pw').checked).toBe(false);
    expect(root.querySelector('[data-nowo-notify-kit-status]')!.textContent).toBe('Stale');
  });

  it('retries once after a lock conflict, then reverts on a second failure', async () => {
    vi.useFakeTimers();
    const { preferences, root } = mount();
    fetchMock.mockResolvedValueOnce(response({ ok: false, error: 'conflict' }, { status: 409, retryAfter: '1' }));
    fetchMock.mockResolvedValueOnce(response({ ok: false, error: 'conflict' }, { status: 409, retryAfter: '1' }));
    input('pc1').checked = true;
    change(input('pc1'));
    await vi.advanceTimersByTimeAsync(MAX_RETRY_MS);
    await preferences.idle();
    expect(fetchMock).toHaveBeenCalledTimes(2);
    expect(input('pc1').checked).toBe(false);
    expect(root.querySelector('[data-nowo-notify-kit-status]')!.textContent).toBe('Error');
  });

  it('reverts a scope change that failed and survives a network error and a broken answer', async () => {
    const { preferences } = mount();
    const select = document.querySelector<HTMLSelectElement>('select[data-category="issues"]')!;
    fetchMock.mockResolvedValueOnce(response({}, { status: 422 }));
    select.value = 'involved';
    change(select);
    await preferences.idle();
    expect(select.value).toBe('all');
    fetchMock.mockRejectedValueOnce(new Error('offline'));
    input('pc0').checked = false;
    change(input('pc0'));
    await preferences.idle();
    expect(input('pc0').checked).toBe(true);
    fetchMock.mockResolvedValueOnce(response(null, { status: 500, badJson: true }));
    change(input('pc0'));
    await preferences.idle();
    expect(fetchMock).toHaveBeenCalledTimes(3);
  });

  it('does nothing without an endpoint and ignores other inputs', async () => {
    const { preferences, root } = mount();
    delete root.dataset.cellsUrl;
    change(input('pc0'));
    change(input('kpc0'));
    await preferences.idle();
    expect(fetchMock).not.toHaveBeenCalled();
    preferences.destroy();
    change(input('pc0'));
    expect(fetchMock).not.toHaveBeenCalled();
  });

  it('works without a status region, a token field or a scope select', async () => {
    document.body.innerHTML = `<div data-nowo-notify-kit-preferences data-cells-url="/c"><input type="checkbox" id="z" data-nowo-notify-kit-cell data-category="a" data-channel="mail"></div>`;
    const [preferences] = attachAll();
    fetchMock.mockResolvedValueOnce(response({ ok: true }));
    change(input('z'));
    await preferences!.idle();
    expect(body(fetchMock).changes).toEqual([{ context: '', category: 'a', channel: 'mail', enabled: false, scope: 'all', expected: '-' }]);
    preferences!.apply([{ context: '', category: 'a', channel: 'mail', enabled: true, scope: 'all', token: '1a' }]);
    expect(input('z').checked).toBe(true);
  });
});

describe('context dialog', () => {
  const fragment = `<p>outside</p><div data-nowo-notify-kit-fragment data-title="Notifications for Apollo">${gridHtml('d', 'project:42')}</div>`;

  it('loads the context grid on demand into a modal dialog and removes it on close', async () => {
    const { preferences } = mount();
    fetchMock.mockResolvedValueOnce(response(fragment));
    const link = document.querySelector<HTMLAnchorElement>('[data-nowo-notify-kit-override]')!;
    link.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true, button: 0 }));
    await vi.waitFor(() => expect(document.querySelector('dialog')).not.toBeNull());
    const dialog = document.querySelector('dialog')!;
    expect(String(fetchMock.mock.calls[0]![0])).toContain('fragment=1');
    expect(dialog.getAttribute('closedby')).toBe('any');
    expect(dialog.querySelector('h1')!.textContent).toBe('Notifications for Apollo');
    expect(document.getElementById(dialog.getAttribute('aria-labelledby')!)).not.toBeNull();
    expect(dialog.textContent).not.toContain('outside');
    expect(dialog.hasAttribute('open')).toBe(true);

    fetchMock.mockResolvedValueOnce(response({ ok: true, cells: [] }));
    input('dc0').checked = false;
    change(input('dc0'));
    await vi.waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(2));
    expect(body(fetchMock, 1).changes[0]).toMatchObject({ context: 'project:42', category: 'issues', channel: 'mail' });

    dialog.querySelector<HTMLButtonElement>('.nowo-notify-kit-dialog__close')!.click();
    expect(document.querySelector('dialog')).toBeNull();
    preferences.destroy();
  });

  it('closes a click on the backdrop where closedby is unsupported, and replaces an open dialog', async () => {
    const { preferences } = mount();
    fetchMock.mockResolvedValue(response(fragment));
    await preferences.openOverrides('/prefs/context?key=project:42');
    await preferences.openOverrides('/prefs/context?key=project:42');
    expect(document.querySelectorAll('dialog')).toHaveLength(1);
    const dialog = document.querySelector('dialog')!;
    vi.spyOn(dialog, 'getBoundingClientRect').mockReturnValue({ top: 10, bottom: 100, left: 10, right: 100 } as DOMRect);
    dialog.querySelector('h1')!.dispatchEvent(new MouseEvent('click', { bubbles: true, clientX: 500, clientY: 500 }));
    dialog.dispatchEvent(new MouseEvent('click', { bubbles: true, clientX: 50, clientY: 50 }));
    expect(document.querySelector('dialog')).not.toBeNull();
    dialog.dispatchEvent(new MouseEvent('click', { bubbles: true, clientX: 500, clientY: 500 }));
    expect(document.querySelector('dialog')).toBeNull();
    await preferences.openOverrides('/prefs/context?key=project:42');
    preferences.destroy();
    expect(document.querySelector('dialog')).toBeNull();
  });

  it('follows the link when the fragment cannot be loaded, and leaves modified clicks alone', async () => {
    const { preferences } = mount();
    const assign = vi.fn();
    vi.stubGlobal('location', { href: 'http://localhost/prefs', assign });
    for (const answer of [response('x', { status: 500 }), response('<p>no grid</p>'), response({ a: 1 }), response('x', { redirected: true })]) {
      fetchMock.mockResolvedValueOnce(answer);
      await preferences.openOverrides('/prefs/context?key=project:42');
    }
    expect(assign).toHaveBeenCalledTimes(4);
    const link = document.querySelector<HTMLAnchorElement>('[data-nowo-notify-kit-override]')!;
    const event = new MouseEvent('click', { bubbles: true, cancelable: true, button: 0, ctrlKey: true });
    link.dispatchEvent(event);
    expect(event.defaultPrevented).toBe(false);
    document.querySelector('p')!.dispatchEvent(new MouseEvent('click', { bubbles: true }));
    expect(fetchMock).toHaveBeenCalledTimes(4);
  });
});

describe('preferences IIFE entry', () => {
  it('logs the script-loaded line, binds the grids and exposes the class', async () => {
    vi.resetModules();
    const log = vi.spyOn(console, 'log').mockImplementation(() => undefined);
    document.body.innerHTML = gridHtml();
    await import('../src/preferences-iife');
    expect(window.NowoNotifyKitPreferences).toBeDefined();
    expect(log.mock.calls[0]![0]).toMatch(/^📦 \[notify-kit\/preferences\] script loaded/);
  });

  it('waits for DOMContentLoaded while the document is loading', async () => {
    vi.resetModules();
    delete (globalThis as Record<string, unknown>).__nowoNotifyKitLoaded;
    vi.spyOn(console, 'log').mockImplementation(() => undefined);
    const state = vi.spyOn(document, 'readyState', 'get').mockReturnValue('loading');
    const add = vi.spyOn(document, 'addEventListener');
    await import('../src/preferences-iife');
    expect(add).toHaveBeenCalledWith('DOMContentLoaded', expect.any(Function), { once: true });
    state.mockRestore();
    document.dispatchEvent(new Event('DOMContentLoaded'));
  });
});
