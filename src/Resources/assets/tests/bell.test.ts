import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { MIN_POLL_SECONDS, NotifyKitBell, OPEN_WAIT_MS, UPDATED_EVENT, attachAll } from '../src/bell';

const ID_A = '019a0000-0000-7000-8000-00000000000a';
const ID_B = '019a0000-0000-7000-8000-00000000000b';
const PLACEHOLDER = '00000000-0000-7000-8000-000000000000';

function bellHtml(attrs: Record<string, string> = {}, unread = 2): string {
  const data: Record<string, string> = {
    'data-menu-url': '/n/menu',
    'data-count-url': '/n/count',
    'data-read-url': `/n/${PLACEHOLDER}/read`,
    'data-unread-url': `/n/${PLACEHOLDER}/unread`,
    'data-read-all-url': '/n/read-all',
    'data-id-placeholder': PLACEHOLDER,
    'data-csrf-token': 'tok',
    'data-realtime': 'off',
    'data-poll-seconds': '30',
    'data-label': 'Notifications',
    'data-unread-label': '__count__ unread',
    'data-loading-label': 'Loading',
    'data-error-label': 'Could not load',
    ...attrs,
  };
  const attributes = Object.entries(data).map(([key, value]) => `${key}="${value}"`).join(' ');

  return `<div class="wrap"><details data-nowo-notify-kit-bell ${attributes}><summary><span data-nowo-notify-kit-name>x</span><span data-nowo-notify-kit-badge ${unread === 0 ? 'hidden' : ''}>${unread}</span></summary><div data-nowo-notify-kit-panel><a href="/inbox">Inbox</a></div></details><span data-nowo-notify-kit-live></span></div><button id="outside">x</button>`;
}

function menuHtml(unread = 2): string {
  return `<div data-nowo-notify-kit-menu data-unread="${unread}"><button data-action="read-all">all</button><ul>
    <li data-id="${ID_A}" data-unread="1" class="is-unread"><a href="/target/a" data-action="open"><span class="t">A</span></a><button data-action="toggle" data-label-read="Mark read" data-label-unread="Mark unread"></button></li>
    <li data-id="${ID_B}" data-unread="0"><a href="/target/b" data-action="open">B</a><button data-action="toggle" data-label-read="Mark read" data-label-unread="Mark unread"></button></li>
  </ul></div>`;
}

function response(body: unknown, init: { status?: number; etag?: string; text?: boolean } = {}): Response {
  const status = init.status ?? 200;
  const headers = new Headers(init.etag ? { ETag: init.etag } : {});

  return {
    ok: status >= 200 && status < 300,
    status,
    headers,
    json: () => Promise.resolve(body),
    text: () => Promise.resolve(String(body)),
  } as unknown as Response;
}

function mount(attrs: Record<string, string> = {}, unread = 2): { bell: NotifyKitBell; root: HTMLDetailsElement } {
  document.body.innerHTML = bellHtml(attrs, unread);
  const root = document.querySelector<HTMLDetailsElement>('[data-nowo-notify-kit-bell]')!;

  return { bell: NotifyKitBell.attach(root), root };
}

const flush = async (): Promise<void> => {
  for (let i = 0; i < 10; i++) {
    await Promise.resolve();
  }
};

describe('NotifyKitBell', () => {
  let fetchMock: ReturnType<typeof vi.fn>;
  let bells: NotifyKitBell[] = [];

  beforeEach(() => {
    fetchMock = vi.fn();
    vi.stubGlobal('fetch', fetchMock);
    bells = [];
  });

  afterEach(() => {
    bells.forEach((bell) => bell.destroy());
    vi.useRealTimers();
    vi.unstubAllGlobals();
    document.body.innerHTML = '';
  });

  const track = (bell: NotifyKitBell): NotifyKitBell => {
    bells.push(bell);

    return bell;
  };

  it('binds once and reads the server count', () => {
    const { bell, root } = mount();
    track(bell);
    expect(NotifyKitBell.attach(root)).toBe(bell);
    expect(bell.count).toBe(2);
    expect(attachAll()).toEqual([bell]);
  });

  it('shows the count on the badge, the accessible name and the live region', () => {
    const { bell, root } = mount();
    track(bell);
    bell.setCount(150);
    const badge = root.querySelector<HTMLElement>('[data-nowo-notify-kit-badge]')!;
    expect(badge.textContent).toBe('99+');
    expect(badge.hidden).toBe(false);
    expect(root.querySelector('[data-nowo-notify-kit-name]')!.textContent).toBe('Notifications (150 unread)');
    expect(document.querySelector('[data-nowo-notify-kit-live]')!.textContent).toBe('150 unread');
    bell.setCount(0);
    expect(badge.hidden).toBe(true);
    document.querySelector('[data-nowo-notify-kit-live]')!.textContent = 'kept';
    bell.setCount(0);
    expect(document.querySelector('[data-nowo-notify-kit-live]')!.textContent).toBe('kept');
  });

  it('loads the menu once on the first open, then only when stale', async () => {
    fetchMock.mockResolvedValue(response(menuHtml(3)));
    const { bell, root } = mount();
    track(bell);
    root.open = true;
    root.dispatchEvent(new Event('toggle'));
    expect(root.querySelector('.nowo-notify-kit-menu__loading')!.textContent).toBe('Loading');
    await flush();
    expect(fetchMock).toHaveBeenCalledWith('/n/menu', expect.objectContaining({ credentials: 'same-origin' }));
    expect(root.querySelectorAll('[data-id]')).toHaveLength(2);
    expect(bell.count).toBe(3);
    root.dispatchEvent(new Event('toggle'));
    await flush();
    expect(fetchMock).toHaveBeenCalledTimes(1);
    const again = bell.load();
    expect(bell.load()).toBe(again);
    await again;
    expect(fetchMock).toHaveBeenCalledTimes(2);
  });

  it('shows the error message when the menu fails', async () => {
    fetchMock.mockResolvedValueOnce(response('nope', { status: 500 }));
    const { bell, root } = mount();
    track(bell);
    await bell.load();
    expect(root.querySelector('[data-nowo-notify-kit-panel]')!.textContent).toBe('Could not load');
  });

  it('does nothing without a menu URL', async () => {
    const { bell } = mount({ 'data-menu-url': '' });
    track(bell);
    await bell.load();
    expect(fetchMock).not.toHaveBeenCalled();
  });

  it('marks a row read and unread with the CSRF header', async () => {
    fetchMock.mockResolvedValueOnce(response(menuHtml()));
    const { bell, root } = mount();
    track(bell);
    await bell.load();
    fetchMock.mockResolvedValueOnce(response({ ok: true, unreadCount: 1 }));
    const toggle = root.querySelector<HTMLElement>(`[data-id="${ID_A}"] [data-action="toggle"]`)!;
    toggle.click();
    await flush();
    expect(fetchMock).toHaveBeenLastCalledWith(`/n/${ID_A}/read`, expect.objectContaining({ method: 'POST', headers: expect.objectContaining({ 'X-CSRF-TOKEN': 'tok' }) }));
    const item = root.querySelector<HTMLElement>(`[data-id="${ID_A}"]`)!;
    expect(item.dataset.unread).toBe('0');
    expect(item.classList.contains('is-unread')).toBe(false);
    expect(toggle.getAttribute('aria-label')).toBe('Mark unread');
    expect(bell.count).toBe(1);

    fetchMock.mockResolvedValueOnce(response({ ok: true, unreadCount: 2 }));
    toggle.click();
    await flush();
    expect(fetchMock).toHaveBeenLastCalledWith(`/n/${ID_A}/unread`, expect.anything());
    expect(item.dataset.unread).toBe('1');
    expect(toggle.getAttribute('aria-label')).toBe('Mark read');
  });

  it('keeps the row when the server refuses or fails', async () => {
    fetchMock.mockResolvedValueOnce(response(menuHtml()));
    const { bell, root } = mount();
    track(bell);
    await bell.load();
    fetchMock.mockResolvedValueOnce(response({ ok: false }));
    expect(await bell.mark(ID_A, true)).toBe(false);
    fetchMock.mockRejectedValueOnce(new Error('offline'));
    expect(await bell.mark(ID_A, true)).toBe(false);
    fetchMock.mockResolvedValueOnce(response({ ok: true }));
    expect(await bell.mark('019a0000-0000-7000-8000-0000000000ff', true)).toBe(true);
    expect(root.querySelector<HTMLElement>(`[data-id="${ID_A}"]`)!.dataset.unread).toBe('1');
  });

  it('refuses to mark without URL templates', async () => {
    const { bell } = mount({ 'data-read-url': '', 'data-read-all-url': '' });
    track(bell);
    expect(await bell.mark(ID_A, true)).toBe(false);
    expect(await bell.markAll()).toBe(false);
    expect(fetchMock).not.toHaveBeenCalled();
  });

  it('marks all rows read', async () => {
    fetchMock.mockResolvedValueOnce(response(menuHtml()));
    const { bell, root } = mount();
    track(bell);
    await bell.load();
    fetchMock.mockResolvedValueOnce(response({ ok: true, unreadCount: 0 }));
    root.querySelector<HTMLElement>('[data-action="read-all"]')!.click();
    await flush();
    expect(fetchMock).toHaveBeenLastCalledWith('/n/read-all', expect.objectContaining({ method: 'POST' }));
    expect(root.querySelectorAll('[data-unread="1"]')).toHaveLength(0);
    expect(root.querySelector('[data-action="read-all"]')).toBeNull();
    expect(bell.count).toBe(0);
    fetchMock.mockResolvedValueOnce(response({ ok: false }));
    expect(await bell.markAll()).toBe(false);
  });

  it('marks an unread row read before following its link, at most 1.5 s', async () => {
    vi.useFakeTimers();
    const assign = vi.fn();
    vi.stubGlobal('location', { ...window.location, assign, href: 'http://localhost/' });
    fetchMock.mockResolvedValueOnce(response(menuHtml()));
    const { bell, root } = mount();
    track(bell);
    await bell.load();
    fetchMock.mockReturnValueOnce(new Promise(() => undefined));
    const link = root.querySelector<HTMLElement>(`[data-id="${ID_A}"] [data-action="open"]`)!;
    const click = new MouseEvent('click', { bubbles: true, cancelable: true, button: 0 });
    link.querySelector('.t')!.dispatchEvent(click);
    expect(click.defaultPrevented).toBe(true);
    await vi.advanceTimersByTimeAsync(OPEN_WAIT_MS + 10);
    expect(assign).toHaveBeenCalledWith('/target/a');
  });

  it('lets modified clicks and read rows follow their link', async () => {
    fetchMock.mockResolvedValueOnce(response(menuHtml()));
    const { bell, root } = mount();
    track(bell);
    await bell.load();
    fetchMock.mockResolvedValue(response({ ok: true, unreadCount: 1 }));
    const unreadLink = root.querySelector<HTMLElement>(`[data-id="${ID_A}"] [data-action="open"]`)!;
    const modified = new MouseEvent('click', { bubbles: true, cancelable: true, ctrlKey: true });
    unreadLink.dispatchEvent(modified);
    expect(modified.defaultPrevented).toBe(false);
    await flush();
    expect(fetchMock).toHaveBeenLastCalledWith(`/n/${ID_A}/read`, expect.anything());
    const readLink = root.querySelector<HTMLElement>(`[data-id="${ID_B}"] [data-action="open"]`)!;
    const plain = new MouseEvent('click', { bubbles: true, cancelable: true });
    readLink.dispatchEvent(plain);
    expect(plain.defaultPrevented).toBe(false);
    root.querySelector('ul')!.dispatchEvent(new MouseEvent('click', { bubbles: true }));
  });

  it('closes on Escape and on an outside click', () => {
    const { bell, root } = mount();
    track(bell);
    root.open = true;
    document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
    expect(root.open).toBe(false);
    expect(document.activeElement).toBe(root.querySelector('summary'));
    root.open = true;
    root.querySelector('[data-nowo-notify-kit-panel]')!.dispatchEvent(new MouseEvent('click', { bubbles: true }));
    document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter' }));
    expect(root.open).toBe(true);
    document.getElementById('outside')!.click();
    expect(root.open).toBe(false);
  });

  it('follows the host event and reloads an open menu when the count changed', async () => {
    fetchMock.mockResolvedValue(response(menuHtml(5)));
    const { bell, root } = mount();
    track(bell);
    document.dispatchEvent(new CustomEvent(UPDATED_EVENT, { detail: { unread: 'x' } }));
    expect(bell.count).toBe(2);
    document.dispatchEvent(new CustomEvent(UPDATED_EVENT, { detail: { unread: 4 } }));
    expect(bell.count).toBe(4);
    expect(fetchMock).not.toHaveBeenCalled();
    root.open = true;
    document.dispatchEvent(new CustomEvent(UPDATED_EVENT, { detail: { unread: 5 } }));
    await flush();
    expect(fetchMock).toHaveBeenCalledTimes(1);
    root.dispatchEvent(new Event('toggle'));
    await flush();
    expect(fetchMock).toHaveBeenCalledTimes(1);
  });

  it('subscribes to the private Mercure topic', () => {
    const sources: Array<{ url: string; init: EventSourceInit | undefined; onmessage: ((event: MessageEvent<string>) => void) | null; close: () => void }> = [];
    class FakeSource {
      onmessage: ((event: MessageEvent<string>) => void) | null = null;
      close = vi.fn();
      constructor(public url: string, public init?: EventSourceInit) {
        sources.push(this);
      }
    }
    vi.stubGlobal('EventSource', FakeSource);
    const { bell } = mount({ 'data-realtime': 'mercure', 'data-hub-url': 'https://hub.example/.well-known/mercure', 'data-topic': 'notify-kit/r/abc' });
    expect(sources).toHaveLength(1);
    expect(sources[0]!.url).toBe('https://hub.example/.well-known/mercure?topic=notify-kit%2Fr%2Fabc');
    expect(sources[0]!.init).toEqual({ withCredentials: true });
    sources[0]!.onmessage!({ data: '{"event":"notify.updated","unread":7}' } as MessageEvent<string>);
    expect(bell.count).toBe(7);
    sources[0]!.onmessage!({ data: '{"event":"other","unread":1}' } as MessageEvent<string>);
    sources[0]!.onmessage!({ data: 'not json' } as MessageEvent<string>);
    expect(bell.count).toBe(7);
    bell.destroy();
    expect(sources[0]!.close).toHaveBeenCalled();
  });

  it('polls the count with its ETag and pauses while the tab is hidden', async () => {
    vi.useFakeTimers();
    let visibility = 'visible';
    vi.spyOn(document, 'visibilityState', 'get').mockImplementation(() => visibility as DocumentVisibilityState);
    fetchMock.mockResolvedValueOnce(response({ unread: 3 }, { etag: '"u3"' }));
    const { bell } = mount({ 'data-realtime': 'poll', 'data-poll-seconds': '1' });
    track(bell);
    await vi.advanceTimersByTimeAsync(MIN_POLL_SECONDS * 1000 + 5);
    expect(fetchMock).toHaveBeenCalledWith('/n/count', expect.objectContaining({ headers: { Accept: 'application/json' } }));
    expect(bell.count).toBe(3);

    fetchMock.mockResolvedValueOnce(response(null, { status: 304 }));
    await vi.advanceTimersByTimeAsync(MIN_POLL_SECONDS * 1000 + 5);
    expect(fetchMock).toHaveBeenLastCalledWith('/n/count', expect.objectContaining({ headers: { Accept: 'application/json', 'If-None-Match': '"u3"' } }));
    expect(bell.count).toBe(3);

    visibility = 'hidden';
    document.dispatchEvent(new Event('visibilitychange'));
    await vi.advanceTimersByTimeAsync(MIN_POLL_SECONDS * 3000);
    expect(fetchMock).toHaveBeenCalledTimes(2);
    await bell.poll();
    expect(fetchMock).toHaveBeenCalledTimes(2);

    visibility = 'visible';
    fetchMock.mockRejectedValueOnce(new Error('offline'));
    document.dispatchEvent(new Event('visibilitychange'));
    await vi.advanceTimersByTimeAsync(1);
    expect(fetchMock).toHaveBeenCalledTimes(3);
    document.dispatchEvent(new Event('visibilitychange'));
    await vi.advanceTimersByTimeAsync(1);
    expect(fetchMock).toHaveBeenCalledTimes(3);
  });

  it('uses the default interval and needs a count URL to poll', async () => {
    vi.useFakeTimers();
    const { bell } = mount({ 'data-realtime': 'poll', 'data-poll-seconds': 'x', 'data-count-url': '' });
    track(bell);
    await vi.advanceTimersByTimeAsync(61_000);
    expect(fetchMock).not.toHaveBeenCalled();
  });

  it('copes with a bell without badge, panel or labels', async () => {
    document.body.innerHTML = '<details data-nowo-notify-kit-bell><summary></summary></details>';
    const bell = track(NotifyKitBell.attach(document.querySelector<HTMLElement>('[data-nowo-notify-kit-bell]')!));
    expect(bell.count).toBe(0);
    bell.setCount(3.7);
    expect(bell.count).toBe(3);
    await bell.load();
    expect(await bell.mark(ID_A, true)).toBe(false);
    expect(await bell.markAll()).toBe(false);
    await bell.poll();
    expect(fetchMock).not.toHaveBeenCalled();
  });
});
