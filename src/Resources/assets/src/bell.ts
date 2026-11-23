/**
 * NotifyKitBundle header bell (contract §8.1). Progressive enhancement of `bell/_bell.html.twig`: the <details>
 * disclosure works without JavaScript (its panel links to the inbox page); with this script it loads the menu the first
 * time it opens, marks rows read / unread (`fetch` + `X-CSRF-TOKEN`), marks a row read when it is opened (waits at
 * most 1.5 s before following the link), *Mark all as read*, closes on Escape or an outside click, and follows the
 * unread badge in realtime: Mercure (private topic), a host event (`nowo-notify-kit:updated` on document, detail
 * `{unread}`), or a bounded poll paused while the tab is hidden. The count is announced by a polite live region.
 *
 * No framework: `attach()` binds one element once (the IIFE binds every `[data-nowo-notify-kit-bell]`; the Stimulus
 * peer `nowo-notify-kit--bell` calls the same).
 */

export const UPDATED_EVENT = 'nowo-notify-kit:updated';

/** Longest wait before following a link while the row is marked read. */
export const OPEN_WAIT_MS = 1500;

/** Shortest poll interval. */
export const MIN_POLL_SECONDS = 10;

type JsonAnswer = { ok?: boolean; unreadCount?: number; unread?: number | boolean };

const bound = new WeakMap<HTMLElement, NotifyKitBell>();

export class NotifyKitBell {
  private readonly root: HTMLDetailsElement;
  private readonly panel: HTMLElement | null;
  private readonly badge: HTMLElement | null;
  private readonly live: HTMLElement | null;
  private readonly summary: HTMLElement | null;
  private readonly name: HTMLElement | null;
  private loaded = false;
  private stale = false;
  private loading: Promise<void> | null = null;
  private unread: number;
  private etag: string | null = null;
  private timer: ReturnType<typeof setTimeout> | null = null;
  private lastPoll = 0;
  private source: EventSource | null = null;
  private readonly cleanups: Array<() => void> = [];

  /** Binds $element once and returns its bell. */
  static attach(element: HTMLElement): NotifyKitBell {
    const existing = bound.get(element);
    if (existing) {
      return existing;
    }
    const bell = new NotifyKitBell(element as HTMLDetailsElement);
    bound.set(element, bell);

    return bell;
  }

  private constructor(root: HTMLDetailsElement) {
    this.root = root;
    this.panel = root.querySelector<HTMLElement>('[data-nowo-notify-kit-panel]');
    this.badge = root.querySelector<HTMLElement>('[data-nowo-notify-kit-badge]');
    this.summary = root.querySelector<HTMLElement>('summary');
    this.name = root.querySelector<HTMLElement>('[data-nowo-notify-kit-name]');
    this.live = root.parentElement?.querySelector<HTMLElement>('[data-nowo-notify-kit-live]') ?? null;
    this.unread = Number.parseInt(this.badge?.textContent ?? '0', 10) || 0;

    this.listen(root, 'toggle', () => this.onToggle());
    this.listen(this.panel, 'click', (event) => this.onClick(event as MouseEvent));
    this.listen(document, 'keydown', (event) => this.onKey(event as KeyboardEvent));
    this.listen(document, 'click', (event) => this.onOutside(event));
    this.listen(document, UPDATED_EVENT, (event) => this.onUpdated((event as CustomEvent<{ unread?: unknown }>).detail?.unread));
    this.startRealtime();
  }

  /** Current unread count. */
  get count(): number {
    return this.unread;
  }

  /** Stops every listener, stream and timer. */
  destroy(): void {
    this.cleanups.splice(0).forEach((cleanup) => cleanup());
    this.source?.close();
    this.source = null;
    this.stopPoll();
    bound.delete(this.root);
  }

  /** Shows $count on the badge, in the button's (visually hidden) name and, when it changed, in the live region. */
  setCount(count: number): void {
    const value = Math.max(0, Math.trunc(count));
    const changed = value !== this.unread;
    this.unread = value;
    if (this.badge) {
      this.badge.textContent = value > 99 ? '99+' : String(value);
      this.badge.hidden = value === 0;
    }
    const text = (this.root.dataset.unreadLabel ?? '__count__').replace('__count__', String(value));
    if (this.name) {
      this.name.textContent = `${this.root.dataset.label ?? ''} (${text})`;
    }
    if (changed && this.live) {
      this.live.textContent = text;
    }
  }

  /** Loads (or reloads) the menu fragment into the panel. */
  load(): Promise<void> {
    if (this.loading) {
      return this.loading;
    }
    const url = this.root.dataset.menuUrl;
    if (!url || !this.panel) {
      return Promise.resolve();
    }
    const panel = this.panel;
    if (!this.loaded) {
      panel.replaceChildren(this.message('loadingLabel', 'nowo-notify-kit-menu__loading'));
    }
    this.loading = fetch(url, { credentials: 'same-origin', headers: { Accept: 'text/html' } })
      .then((response) => {
        if (!response.ok) {
          throw new Error(String(response.status));
        }

        return response.text();
      })
      .then((html) => {
        const template = document.createElement('template');
        template.innerHTML = html;
        panel.replaceChildren(template.content);
        this.loaded = true;
        this.stale = false;
        const menu = panel.querySelector<HTMLElement>('[data-nowo-notify-kit-menu]');
        if (menu?.dataset.unread !== undefined) {
          this.setCount(Number.parseInt(menu.dataset.unread, 10) || 0);
        }
      })
      .catch(() => {
        panel.replaceChildren(this.message('errorLabel', 'nowo-notify-kit-menu__empty'));
      })
      .finally(() => {
        this.loading = null;
      });

    return this.loading;
  }

  /** Marks the row $id read (true) or unread (false); resolves with whether the server accepted it. */
  mark(id: string, read: boolean): Promise<boolean> {
    const template = read ? this.root.dataset.readUrl : this.root.dataset.unreadUrl;
    const placeholder = this.root.dataset.idPlaceholder ?? '';
    if (!template || placeholder === '') {
      return Promise.resolve(false);
    }

    return this.post(template.replace(placeholder, id)).then((answer) => {
      if (!answer?.ok) {
        return false;
      }
      const item = this.item(id);
      if (item) {
        this.paint(item, !read);
      }

      return true;
    });
  }

  /** *Mark all as read*. */
  markAll(): Promise<boolean> {
    const url = this.root.dataset.readAllUrl;
    if (!url) {
      return Promise.resolve(false);
    }

    return this.post(url).then((answer) => {
      if (!answer?.ok) {
        return false;
      }
      this.panel?.querySelectorAll<HTMLElement>('[data-id]').forEach((item) => this.paint(item, false));
      this.panel?.querySelector('[data-action="read-all"]')?.remove();

      return true;
    });
  }

  /** One poll of the count (`If-None-Match`); skipped while the tab is hidden. */
  poll(): Promise<void> {
    const url = this.root.dataset.countUrl;
    if (!url || document.visibilityState === 'hidden') {
      return Promise.resolve();
    }
    this.lastPoll = Date.now();
    const headers: Record<string, string> = { Accept: 'application/json' };
    if (this.etag) {
      headers['If-None-Match'] = this.etag;
    }

    return fetch(url, { credentials: 'same-origin', headers })
      .then((response) => {
        if (response.status !== 200) {
          return;
        }
        this.etag = response.headers.get('ETag');

        return response.json().then((data: JsonAnswer) => this.onUpdated(data.unread));
      })
      .catch(() => undefined);
  }

  private onToggle(): void {
    if (this.root.open && (!this.loaded || this.stale)) {
      void this.load();
    }
  }

  private onClick(event: MouseEvent): void {
    const target = event.target instanceof Element ? event.target : null;
    const action = target?.closest<HTMLElement>('[data-action]');
    if (!action) {
      return;
    }
    const item = action.closest<HTMLElement>('[data-id]');
    const id = item?.dataset.id;
    switch (action.dataset.action) {
      case 'read-all':
        event.preventDefault();
        void this.markAll();
        break;
      case 'toggle':
        event.preventDefault();
        if (id) {
          void this.mark(id, item.dataset.unread === '1');
        }
        break;
      case 'open':
        this.open(event, action, item, id);
        break;
    }
  }

  private open(event: MouseEvent, link: HTMLElement, item: HTMLElement | null | undefined, id: string | undefined): void {
    const href = link.getAttribute('href');
    if (!id || item?.dataset.unread !== '1' || !href) {
      return;
    }
    if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
      void this.mark(id, true);

      return;
    }
    event.preventDefault();
    const wait = new Promise<boolean>((resolve) => setTimeout(() => resolve(false), OPEN_WAIT_MS));
    void Promise.race([this.mark(id, true), wait]).finally(() => window.location.assign(href));
  }

  private onKey(event: KeyboardEvent): void {
    if (event.key === 'Escape' && this.root.open) {
      this.root.open = false;
      this.summary?.focus();
    }
  }

  private onOutside(event: Event): void {
    if (this.root.open && event.target instanceof Node && !this.root.contains(event.target)) {
      this.root.open = false;
    }
  }

  private onUpdated(unread: unknown): void {
    if (typeof unread !== 'number' || !Number.isFinite(unread)) {
      return;
    }
    if (unread !== this.unread) {
      this.stale = true;
      if (this.root.open) {
        void this.load();
      }
    }
    this.setCount(unread);
  }

  private startRealtime(): void {
    const mode = this.root.dataset.realtime;
    const hub = this.root.dataset.hubUrl;
    const topic = this.root.dataset.topic;
    if (mode === 'mercure' && hub && topic && typeof EventSource !== 'undefined') {
      const url = new URL(hub, window.location.href);
      url.searchParams.append('topic', topic);
      this.source = new EventSource(url.toString(), { withCredentials: true });
      this.source.onmessage = (message: MessageEvent<string>) => {
        try {
          const data = JSON.parse(message.data) as { event?: string; unread?: unknown };
          if (data.event === 'notify.updated') {
            this.onUpdated(data.unread);
          }
        } catch {
          // Not a bell payload.
        }
      };

      return;
    }
    if (mode === 'poll') {
      this.listen(document, 'visibilitychange', () => this.onVisibility());
      this.schedule();
    }
  }

  private interval(): number {
    return Math.max(MIN_POLL_SECONDS, Number.parseInt(this.root.dataset.pollSeconds ?? '60', 10) || 60) * 1000;
  }

  private schedule(): void {
    this.stopPoll();
    this.timer = setTimeout(() => {
      void this.poll().finally(() => this.schedule());
    }, this.interval());
  }

  private onVisibility(): void {
    if (document.visibilityState === 'hidden') {
      this.stopPoll();

      return;
    }
    if (Date.now() - this.lastPoll >= this.interval()) {
      void this.poll().finally(() => this.schedule());
    } else {
      this.schedule();
    }
  }

  private stopPoll(): void {
    if (this.timer !== null) {
      clearTimeout(this.timer);
      this.timer = null;
    }
  }

  private post(url: string): Promise<JsonAnswer | null> {
    return fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { Accept: 'application/json', 'X-CSRF-TOKEN': this.root.dataset.csrfToken ?? '' },
    })
      .then((response) => response.json() as Promise<JsonAnswer>)
      .then((answer) => {
        if (answer.ok && typeof answer.unreadCount === 'number') {
          this.setCount(answer.unreadCount);
        }

        return answer;
      })
      .catch(() => null);
  }

  private item(id: string): HTMLElement | null {
    for (const item of this.panel?.querySelectorAll<HTMLElement>('[data-id]') ?? []) {
      if (item.dataset.id === id) {
        return item;
      }
    }

    return null;
  }

  private paint(item: HTMLElement, unread: boolean): void {
    item.dataset.unread = unread ? '1' : '0';
    item.classList.toggle('is-unread', unread);
    const toggle = item.querySelector<HTMLElement>('[data-action="toggle"]');
    const label = unread ? toggle?.dataset.labelRead : toggle?.dataset.labelUnread;
    if (toggle && label) {
      toggle.setAttribute('aria-label', label);
    }
  }

  private message(key: 'loadingLabel' | 'errorLabel', className: string): HTMLElement {
    const paragraph = document.createElement('p');
    paragraph.className = className;
    paragraph.textContent = this.root.dataset[key] ?? '';

    return paragraph;
  }

  private listen(target: EventTarget | null, type: string, handler: (event: Event) => void): void {
    if (!target) {
      return;
    }
    target.addEventListener(type, handler);
    this.cleanups.push(() => target.removeEventListener(type, handler));
  }
}

/** Binds every bell under $scope. */
export function attachAll(scope: ParentNode = document): NotifyKitBell[] {
  return Array.from(scope.querySelectorAll<HTMLElement>('[data-nowo-notify-kit-bell]'), (element) => NotifyKitBell.attach(element));
}
