import { onBeforeUnmount, onMounted, ref } from 'vue';

const isOnline = ref(
    typeof navigator === 'undefined' ? true : navigator.onLine,
);
const wasOffline = ref(false);

let listenersAttached = 0;

function handleOnline(): void {
    isOnline.value = true;
    wasOffline.value = true;
    window.setTimeout(() => {
        wasOffline.value = false;
    }, 4000);
}

function handleOffline(): void {
    isOnline.value = false;
}

export function useNetworkStatus() {
    onMounted(() => {
        if (typeof window === 'undefined') {
            return;
        }

        isOnline.value = navigator.onLine;

        if (listenersAttached === 0) {
            window.addEventListener('online', handleOnline);
            window.addEventListener('offline', handleOffline);
        }

        listenersAttached += 1;
    });

    onBeforeUnmount(() => {
        listenersAttached = Math.max(0, listenersAttached - 1);

        if (listenersAttached === 0 && typeof window !== 'undefined') {
            window.removeEventListener('online', handleOnline);
            window.removeEventListener('offline', handleOffline);
        }
    });

    return {
        isOnline,
        wasOffline,
    };
}
