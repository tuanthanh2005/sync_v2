import { ref } from 'vue';

interface BeforeInstallPromptEvent extends Event {
    prompt: () => Promise<void>;
    userChoice: Promise<{ outcome: 'accepted' | 'dismissed' }>;
}

const deferredInstallPrompt = ref<BeforeInstallPromptEvent | null>(null);
const canInstallApp = ref(false);
const isStandalone = ref(false);
const updateAvailable = ref(false);

let waitingWorker: ServiceWorker | null = null;
let initialized = false;

export function initializePwa(): void {
    if (initialized || typeof window === 'undefined') {
        return;
    }

    initialized = true;

    const nav = window.navigator as Navigator & { standalone?: boolean };
    isStandalone.value =
        window.matchMedia('(display-mode: standalone)').matches ||
        Boolean(nav.standalone);

    window.addEventListener('beforeinstallprompt', (event) => {
        event.preventDefault();
        deferredInstallPrompt.value = event as BeforeInstallPromptEvent;

        let dismissed = false;
        try {
            dismissed =
                window.localStorage.getItem('syncviet_pwa_dismissed') === '1';
        } catch {
            // Ignore storage errors
        }

        if (!dismissed && !isStandalone.value) {
            canInstallApp.value = true;
        }
    });

    window.addEventListener('appinstalled', () => {
        deferredInstallPrompt.value = null;
        canInstallApp.value = false;
        isStandalone.value = true;
    });

    if (
        'serviceWorker' in navigator &&
        (window.location.protocol === 'https:' ||
            window.location.hostname === 'localhost' ||
            window.location.hostname === '127.0.0.1')
    ) {
        window.addEventListener('load', () => {
            navigator.serviceWorker
                .register('/sw.js', { scope: '/' })
                .then((registration) => {
                    if (registration.waiting) {
                        waitingWorker = registration.waiting;
                        updateAvailable.value = true;
                    }

                    registration.addEventListener('updatefound', () => {
                        const installing = registration.installing;
                        if (!installing) {
                            return;
                        }

                        installing.addEventListener('statechange', () => {
                            if (
                                installing.state === 'installed' &&
                                navigator.serviceWorker.controller
                            ) {
                                waitingWorker = installing;
                                updateAvailable.value = true;
                            }
                        });
                    });
                })
                .catch(() => {
                    // Fail silently if service worker registration is blocked
                });

            let refreshing = false;
            navigator.serviceWorker.addEventListener('controllerchange', () => {
                if (refreshing) {
                    return;
                }
                refreshing = true;
                window.location.reload();
            });
        });
    }
}

export async function promptInstallApp(): Promise<boolean> {
    if (!deferredInstallPrompt.value) {
        return false;
    }

    const promptEvent = deferredInstallPrompt.value;
    await promptEvent.prompt();
    const { outcome } = await promptEvent.userChoice;

    deferredInstallPrompt.value = null;
    canInstallApp.value = false;

    if (outcome === 'dismissed') {
        try {
            window.localStorage.setItem('syncviet_pwa_dismissed', '1');
        } catch {
            // Ignore storage errors
        }
    }

    return outcome === 'accepted';
}

export function dismissInstallApp(): void {
    canInstallApp.value = false;
    try {
        window.localStorage.setItem('syncviet_pwa_dismissed', '1');
    } catch {
        // Ignore storage errors
    }
}

export function applyPwaUpdate(): void {
    if (waitingWorker) {
        waitingWorker.postMessage({ type: 'SKIP_WAITING' });
    } else if (typeof window !== 'undefined') {
        window.location.reload();
    }
}

export function usePwa() {
    return {
        canInstallApp,
        isStandalone,
        updateAvailable,
        promptInstallApp,
        dismissInstallApp,
        applyPwaUpdate,
    };
}
