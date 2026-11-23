import { describe, expect, it, vi } from 'vitest';

describe('IIFE entry', () => {
  it('binds the bells already in the page and exposes the class', async () => {
    vi.resetModules();
    document.body.innerHTML = '<details data-nowo-notify-kit-bell><summary></summary></details>';
    await import('../src/iife');
    expect(window.NowoNotifyKitBell).toBeDefined();
    const element = document.querySelector<HTMLElement>('details')!;
    expect(window.NowoNotifyKitBell!.attach(element)).toBe(window.NowoNotifyKitBell!.attach(element));
  });

  it('waits for DOMContentLoaded while the document is loading', async () => {
    vi.resetModules();
    const state = vi.spyOn(document, 'readyState', 'get').mockReturnValue('loading');
    const add = vi.spyOn(document, 'addEventListener');
    await import('../src/iife');
    expect(add).toHaveBeenCalledWith('DOMContentLoaded', expect.any(Function), { once: true });
    state.mockRestore();
    document.dispatchEvent(new Event('DOMContentLoaded'));
  });
});
