import { NotifyKitBell, attachAll } from './bell';

declare global {
  interface Window {
    NowoNotifyKitBell?: typeof NotifyKitBell;
  }
}

window.NowoNotifyKitBell = NotifyKitBell;
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', () => attachAll(), { once: true });
} else {
  attachAll();
}
