/**
 * NotifyKitBundle preferences page (contract §8.3). Progressive enhancement of `preferences/_matrix.html.twig`: the
 * form saves with its submit button without JavaScript; with this script every change is saved at once (autosave):
 *
 * - a checkbox (`[data-nowo-notify-kit-cell]`: a matrix cell, the master switch or a context switch) or a scope select
 *   (`[data-nowo-notify-kit-scope]`: every unlocked cell of its row) posts `{changes: [...]}` as JSON with the
 *   `X-CSRF-TOKEN` header; each change carries the token of the stored state the page showed (`expected`);
 * - saves run one after the other; the answer's cells update the inputs and their tokens;
 * - 409 `stale` (changed in another tab or device): the inputs take the current values and the status says so;
 *   409 with `Retry-After` (a lock conflict, `CONFLICT_001`): retried once after the delay; anything else reverts the
 *   change and shows the error;
 * - *Customise* of a context loads that context's grid on demand into a modal `<dialog>` (closed by Esc, the close
 *   button or a click outside), autosaved the same way; the dialog is removed when it closes.
 *
 * The polite `role="status"` region announces "Saving…", "Saved" and errors. No framework: `attach()` binds one grid
 * once.
 */

export type CellState = { context: string; category: string; channel: string; enabled: boolean; scope: string; token: string };
export type Change = { context: string; category: string; channel: string; enabled: boolean; scope: string; expected: string };
type Answer = { ok?: boolean; error?: string; cells?: CellState[] };

/** Longest wait before a retry after a lock conflict. */
export const MAX_RETRY_MS = 5000;

const bound = new WeakMap<HTMLElement, NotifyKitPreferences>();

export class NotifyKitPreferences {
  private readonly root: HTMLElement;
  private readonly form: HTMLFormElement | null;
  private readonly status: HTMLElement | null;
  private queue: Promise<void> = Promise.resolve();
  private readonly cleanups: Array<() => void> = [];
  private dialog: HTMLDialogElement | null = null;

  /** Binds $element once and returns its controller. */
  static attach(element: HTMLElement): NotifyKitPreferences {
    const existing = bound.get(element);
    if (existing) {
      return existing;
    }
    const preferences = new NotifyKitPreferences(element);
    bound.set(element, preferences);

    return preferences;
  }

  private constructor(root: HTMLElement) {
    this.root = root;
    this.form = root.querySelector<HTMLFormElement>('form');
    this.status = root.querySelector<HTMLElement>('[data-nowo-notify-kit-status]');
    const submit = root.querySelector<HTMLElement>('[data-nowo-notify-kit-submit]');
    if (submit) {
      // Every change is saved at once: the button would only repeat it.
      submit.hidden = true;
    }
    root.querySelectorAll<HTMLSelectElement>('select[data-nowo-notify-kit-scope]').forEach((select) => {
      select.dataset.previous = select.value;
    });
    this.listen(root, 'change', (event) => this.onChange(event.target));
    this.listen(root, 'click', (event) => this.onClick(event as MouseEvent));
  }

  /** Stops every listener and closes an open dialog. */
  destroy(): void {
    this.cleanups.splice(0).forEach((cleanup) => cleanup());
    this.dialog?.close();
    bound.delete(this.root);
  }

  /** Resolves once every queued save finished. */
  idle(): Promise<void> {
    return this.queue;
  }

  /** Builds the changes of an input's change and queues them. */
  private onChange(target: EventTarget | null): void {
    if (target instanceof HTMLInputElement && target.matches('[data-nowo-notify-kit-cell]')) {
      const row = this.scopeSelect(target.dataset.context ?? '', target.dataset.category ?? '');
      this.save([this.change(target, row?.value ?? 'all')], [target], target);
    } else if (target instanceof HTMLSelectElement && target.matches('[data-nowo-notify-kit-scope]')) {
      const cells = this.rowCells(target.dataset.context ?? '', target.dataset.category ?? '');
      if (cells.length > 0) {
        this.save(cells.map((cell) => this.change(cell, target.value)), [...cells, target], target);
      }
    }
  }

  /** Opens a context's overrides in a dialog. */
  private onClick(event: MouseEvent): void {
    const link = (event.target as Element | null)?.closest?.('a[data-nowo-notify-kit-override]');
    if (!(link instanceof HTMLAnchorElement) || event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
      return;
    }
    event.preventDefault();
    void this.openOverrides(link.href);
  }

  /** Loads the context grid (no redirects, HTML only) and shows it in a modal dialog; follows the link on failure. */
  async openOverrides(href: string): Promise<void> {
    const url = new URL(href, window.location.href);
    url.searchParams.set('fragment', '1');
    try {
      const response = await fetch(url.toString(), { credentials: 'same-origin', redirect: 'error', headers: { Accept: 'text/html' } });
      if (!response.ok || response.redirected || !(response.headers.get('Content-Type') ?? '').startsWith('text/html')) {
        throw new Error(String(response.status));
      }
      const template = document.createElement('template');
      template.innerHTML = await response.text();
      const fragment = template.content.querySelector<HTMLElement>('[data-nowo-notify-kit-fragment]');
      const grid = fragment?.querySelector<HTMLElement>('[data-nowo-notify-kit-preferences]');
      if (!fragment || !grid) {
        throw new Error('fragment');
      }
      this.showDialog(fragment.dataset.title ?? '', grid);
    } catch {
      window.location.assign(href);
    }
  }

  private showDialog(title: string, grid: HTMLElement): void {
    this.dialog?.close();
    const dialog = document.createElement('dialog');
    dialog.className = 'nowo-notify-kit-dialog';
    dialog.setAttribute('closedby', 'any');
    const id = `nowo-notify-kit-dialog-${Date.now().toString(36)}`;
    dialog.setAttribute('aria-labelledby', id);
    const header = document.createElement('header');
    header.className = 'nowo-notify-kit-dialog__header';
    const heading = document.createElement('h1');
    heading.id = id;
    heading.textContent = title;
    const close = document.createElement('button');
    close.type = 'button';
    close.className = 'nowo-notify-kit-dialog__close';
    close.textContent = this.root.dataset.closeLabel ?? 'Close';
    close.addEventListener('click', () => dialog.close());
    header.append(heading, close);
    dialog.append(header, grid);
    if (!('closedBy' in HTMLDialogElement.prototype)) {
      // Light dismiss where `closedby` is not supported: a click on the backdrop targets the dialog itself.
      dialog.addEventListener('click', (event) => {
        if (event.target !== dialog) {
          return;
        }
        const rect = dialog.getBoundingClientRect();
        const inside = rect.top <= event.clientY && event.clientY <= rect.bottom && rect.left <= event.clientX && event.clientX <= rect.right;
        if (!inside) {
          dialog.close();
        }
      });
    }
    const inner = NotifyKitPreferences.attach(grid);
    dialog.addEventListener('close', () => {
      inner.destroy();
      dialog.remove();
      if (this.dialog === dialog) {
        this.dialog = null;
      }
    }, { once: true });
    document.body.append(dialog);
    this.dialog = dialog;
    dialog.showModal();
    grid.querySelector<HTMLElement>('input, select')?.focus();
  }

  private change(input: HTMLInputElement, scope: string): Change {
    return {
      context: input.dataset.context ?? '',
      category: input.dataset.category ?? '',
      channel: input.dataset.channel ?? '',
      enabled: input.checked,
      scope: input.dataset.category === '*' ? 'all' : scope,
      expected: this.token(input)?.value ?? '-',
    };
  }

  private token(input: HTMLElement): HTMLInputElement | null {
    const id = input.dataset.tokenId;
    const field = id ? this.root.ownerDocument.getElementById(id) : null;

    return field instanceof HTMLInputElement ? field : null;
  }

  private rowCells(context: string, category: string): HTMLInputElement[] {
    return Array.from(this.root.querySelectorAll<HTMLInputElement>('input[data-nowo-notify-kit-cell]')).filter(
      (cell) => (cell.dataset.context ?? '') === context && cell.dataset.category === category,
    );
  }

  private scopeSelect(context: string, category: string): HTMLSelectElement | null {
    return Array.from(this.root.querySelectorAll<HTMLSelectElement>('select[data-nowo-notify-kit-scope]')).find(
      (select) => (select.dataset.context ?? '') === context && select.dataset.category === category,
    ) ?? null;
  }

  /** Queues one save; the inputs are disabled while it runs. */
  private save(changes: Change[], inputs: Array<HTMLInputElement | HTMLSelectElement>, origin: HTMLInputElement | HTMLSelectElement): void {
    this.queue = this.queue.then(() => this.send(changes, inputs, origin, true));
  }

  private async send(changes: Change[], inputs: Array<HTMLInputElement | HTMLSelectElement>, origin: HTMLInputElement | HTMLSelectElement, retry: boolean): Promise<void> {
    const url = this.root.dataset.cellsUrl;
    if (!url) {
      return;
    }
    // The tokens may have moved on since the change was queued (an earlier save of the same cell).
    changes.forEach((change, index) => {
      const input = inputs[index];
      if (input instanceof HTMLInputElement) {
        change.expected = this.token(input)?.value ?? change.expected;
      }
    });
    this.say('savingLabel');
    inputs.forEach((input) => input.setAttribute('aria-busy', 'true'));
    try {
      const response = await fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        redirect: 'error',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': this.root.dataset.csrfToken ?? '' },
        body: JSON.stringify({ changes }),
      });
      const answer = (await response.json().catch(() => ({}))) as Answer;
      if (response.ok && answer.ok) {
        this.apply(answer.cells ?? []);
        this.say('savedLabel');

        return;
      }
      if (response.status === 409 && answer.error === 'stale') {
        this.apply(answer.cells ?? []);
        this.say('staleLabel');

        return;
      }
      const wait = Number.parseInt(response.headers.get('Retry-After') ?? '', 10);
      if (response.status === 409 && retry && Number.isFinite(wait)) {
        await new Promise((resolve) => setTimeout(resolve, Math.min(MAX_RETRY_MS, Math.max(0, wait * 1000))));
        await this.send(changes, inputs, origin, false);

        return;
      }
      throw new Error(String(response.status));
    } catch {
      this.revert(origin);
      this.say('errorLabel');
    } finally {
      inputs.forEach((input) => input.removeAttribute('aria-busy'));
    }
  }

  /** Puts the server's cells into the inputs (value, scope, token). */
  apply(cells: CellState[]): void {
    for (const cell of cells) {
      const input = Array.from(this.root.querySelectorAll<HTMLInputElement>('input[data-nowo-notify-kit-cell]')).find(
        (candidate) => (candidate.dataset.context ?? '') === cell.context && candidate.dataset.category === cell.category && candidate.dataset.channel === cell.channel,
      );
      if (!input) {
        continue;
      }
      input.checked = cell.enabled;
      const token = this.token(input);
      if (token) {
        token.value = cell.token;
      }
      const select = this.scopeSelect(cell.context, cell.category);
      if (select && cell.category !== '*') {
        select.value = cell.scope;
        select.dataset.previous = cell.scope;
      }
    }
  }

  /** Undoes the change that could not be saved. */
  private revert(origin: HTMLInputElement | HTMLSelectElement): void {
    if (origin instanceof HTMLInputElement) {
      origin.checked = !origin.checked;
    } else {
      origin.value = origin.dataset.previous ?? origin.value;
    }
  }

  private say(key: 'savingLabel' | 'savedLabel' | 'staleLabel' | 'errorLabel'): void {
    if (this.status) {
      this.status.textContent = this.root.dataset[key] ?? '';
    }
  }

  private listen(target: EventTarget, type: string, handler: (event: Event) => void): void {
    target.addEventListener(type, handler);
    this.cleanups.push(() => target.removeEventListener(type, handler));
  }
}

/** Binds every preferences grid of the document. */
export function attachAll(root: ParentNode = document): NotifyKitPreferences[] {
  return Array.from(root.querySelectorAll<HTMLElement>('[data-nowo-notify-kit-preferences]')).map((element) => NotifyKitPreferences.attach(element));
}
