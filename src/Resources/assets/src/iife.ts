import { NotifyKitBell, attachAll } from './bell';
import { createBundleLogger } from './logger';

declare const __NOTIFY_KIT_BUILD_TIME__: string;

declare global {
  interface Window {
    NowoNotifyKitBell?: typeof NotifyKitBell;
  }
}

createBundleLogger('notify-kit/bell', {
  buildTime: typeof __NOTIFY_KIT_BUILD_TIME__ !== 'undefined' ? __NOTIFY_KIT_BUILD_TIME__ : undefined,
}).scriptLoaded();

window.NowoNotifyKitBell = NotifyKitBell;
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', () => attachAll(), { once: true });
} else {
  attachAll();
}
