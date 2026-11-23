import { createBundleLogger } from './logger';
import { NotifyKitPreferences, attachAll } from './preferences';

declare const __NOTIFY_KIT_BUILD_TIME__: string;

declare global {
  interface Window {
    NowoNotifyKitPreferences?: typeof NotifyKitPreferences;
  }
}

createBundleLogger('notify-kit/preferences', {
  buildTime: typeof __NOTIFY_KIT_BUILD_TIME__ !== 'undefined' ? __NOTIFY_KIT_BUILD_TIME__ : undefined,
}).scriptLoaded();

window.NowoNotifyKitPreferences = NotifyKitPreferences;
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', () => attachAll(), { once: true });
} else {
  attachAll();
}
