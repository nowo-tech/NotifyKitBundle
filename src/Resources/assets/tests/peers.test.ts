import { Application } from '@hotwired/stimulus';
import { afterEach, describe, expect, it, vi } from 'vitest';
import BellController from '../stimulus-peers/bell_controller';
import { NotifyKitBell } from '../src/bell';

describe('Stimulus peer nowo-notify-kit--bell', () => {
  afterEach(() => {
    document.body.innerHTML = '';
  });

  it('attaches the bell on connect and destroys it on disconnect', async () => {
    document.body.innerHTML = '<details data-controller="nowo-notify-kit--bell"><summary><span data-nowo-notify-kit-badge>4</span></summary></details>';
    const application = Application.start();
    application.register('nowo-notify-kit--bell', BellController);
    await new Promise((resolve) => setTimeout(resolve, 0));
    const element = document.querySelector<HTMLElement>('details')!;
    const bell = NotifyKitBell.attach(element);
    expect(bell.count).toBe(4);
    const destroy = vi.spyOn(bell, 'destroy');
    element.remove();
    await new Promise((resolve) => setTimeout(resolve, 0));
    expect(destroy).toHaveBeenCalled();
    application.stop();
  });
});
