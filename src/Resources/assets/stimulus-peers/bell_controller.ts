/**
 * Optional Stimulus peer of the bell (identifier `nowo-notify-kit--bell`) for hosts that run Symfony UX Stimulus:
 * register it and add `data-controller="nowo-notify-kit--bell"` to the bell's <details> instead of loading
 * `js/nowo-notify-kit-bell.js`. It runs the same code ({@link NotifyKitBell}); not built into the IIFE.
 */
import { Controller } from '@hotwired/stimulus';
import { NotifyKitBell } from '../src/bell';

export default class extends Controller<HTMLElement> {
  private bell: NotifyKitBell | null = null;

  override connect(): void {
    this.bell = NotifyKitBell.attach(this.element);
  }

  override disconnect(): void {
    this.bell?.destroy();
    this.bell = null;
  }
}
