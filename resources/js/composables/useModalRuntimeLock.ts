import { computed, onBeforeUnmount, readonly, ref, watch } from 'vue';
import type { WatchSource } from 'vue';

const activeModalCount = ref(0);

/**
 * Shared reactive indicator showing whether any Dialog, Sheet, Drawer, or Lightbox
 * is currently open so background feed observers and pollers pause cleanly.
 */
export const isModalRuntimeActive = computed(() => activeModalCount.value > 0);
export const activeModalLockCount = readonly(activeModalCount);

/**
 * Acquire a single idempotent lock reference for an open modal instance.
 */
export function acquireModalLock(): () => void {
    let released = false;
    activeModalCount.value += 1;

    return () => {
        if (released) {
            return;
        }
        released = true;
        activeModalCount.value = Math.max(0, activeModalCount.value - 1);
    };
}

/**
 * Bind a root modal/sheet/lightbox lifecycle directly to the global runtime lock,
 * handling controlled (`v-model:open`), uncontrolled trigger, and unmount cleanup.
 */
export function useModalRootLock(
    controlledOpen?: WatchSource<boolean | undefined>,
): {
    handleUpdateOpen: (isOpen: boolean) => void;
    syncOpenState: (isOpen: boolean) => void;
} {
    let releaseCurrent: (() => void) | null = null;

    function syncOpenState(isOpen: boolean): void {
        if (isOpen) {
            if (!releaseCurrent) {
                releaseCurrent = acquireModalLock();
            }
            return;
        }

        if (releaseCurrent) {
            releaseCurrent();
            releaseCurrent = null;
        }
    }

    if (controlledOpen) {
        watch(
            controlledOpen,
            (val) => {
                if (typeof val === 'boolean') {
                    syncOpenState(val);
                }
            },
            { immediate: true },
        );
    }

    onBeforeUnmount(() => {
        syncOpenState(false);
    });

    return {
        handleUpdateOpen: syncOpenState,
        syncOpenState,
    };
}
