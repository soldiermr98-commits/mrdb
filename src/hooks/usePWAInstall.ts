/**
 * @file hooks/usePWAInstall.ts
 * @description In-app PWA install hook compliant with AI Studio PWA Integration Guidelines
 */

import { useEffect, useState } from 'react';

interface BeforeInstallPromptEvent extends Event {
  prompt: () => Promise<void>;
  userChoice: Promise<{ outcome: 'accepted' | 'dismissed'; platform: string }>;
}

// BUG-66c: localStorage key for the persisted banner dismissal.
const INSTALL_DISMISSED_KEY = 'mrdastbaaf_install_dismissed';

export function usePWAInstall() {
  const [deferredPrompt, setDeferredPrompt] = useState<BeforeInstallPromptEvent | null>(null);
  const [isInstalled, setIsInstalled] = useState(false);
  const [isIOS, setIsIOS] = useState(false);
  // BUG-66c: banners stay hidden once the user dismissed them.
  const [isDismissed, setIsDismissed] = useState<boolean>(() => {
    try {
      return localStorage.getItem(INSTALL_DISMISSED_KEY) === '1';
    } catch {
      return false;
    }
  });

  useEffect(() => {
    // Detect standalone mode (already installed)
    const isStandalone =
      window.matchMedia('(display-mode: standalone)').matches ||
      (window.navigator as unknown as { standalone?: boolean }).standalone === true;
    setIsInstalled(isStandalone);

    // Detect iOS devices.
    // BUG-66e: iPadOS 13+ reports itself as a Macintosh UA with multi-touch
    // support — detect it too, otherwise iPad users miss the install guide.
    const userAgent = window.navigator.userAgent;
    const isIOSDevice =
      /iphone|ipad|ipod/i.test(userAgent) ||
      (window.navigator.maxTouchPoints > 1 && /macintosh/i.test(userAgent));
    setIsIOS(isIOSDevice);

    const handleBeforeInstallPrompt = (e: Event) => {
      e.preventDefault();
      setDeferredPrompt(e as BeforeInstallPromptEvent);
    };

    const handleAppInstalled = () => {
      setIsInstalled(true);
      setDeferredPrompt(null);
    };

    window.addEventListener('beforeinstallprompt', handleBeforeInstallPrompt);
    window.addEventListener('appinstalled', handleAppInstalled);

    return () => {
      window.removeEventListener('beforeinstallprompt', handleBeforeInstallPrompt);
      window.removeEventListener('appinstalled', handleAppInstalled);
    };
  }, []);

  const install = async (): Promise<boolean> => {
    if (!deferredPrompt) return false;
    // BUG-66b: never propagate — prompt()/userChoice can reject on unsupported
    // or misconfigured browsers; the UI shows a Persian fallback message.
    try {
      await deferredPrompt.prompt();
      const { outcome } = await deferredPrompt.userChoice;
      if (outcome === 'accepted') {
        setIsInstalled(true);
        setDeferredPrompt(null);
        return true;
      }
      return false;
    } catch {
      return false;
    }
  };

  // BUG-66c: persist the dismissal so the banner does not nag on every reload.
  const dismiss = () => {
    try {
      localStorage.setItem(INSTALL_DISMISSED_KEY, '1');
    } catch {
      // storage unavailable (private mode etc.) — still hide for this session
    }
    setIsDismissed(true);
  };

  return {
    isInstallable: !!deferredPrompt,
    isInstalled,
    isIOS,
    isDismissed,
    install,
    dismiss,
  };
}
