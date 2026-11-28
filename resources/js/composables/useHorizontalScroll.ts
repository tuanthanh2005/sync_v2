import type { Ref, WatchSource } from 'vue';
import { nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';

/**
 * Shared RTL-aware horizontal scrollable rail controller with start/end chevron state,
 * wheel horizontal normalization, and ResizeObserver lifecycle cleanup.
 */
export function useHorizontalScroll(
    containerRef: Ref<HTMLElement | null>,
    watchSources: WatchSource<unknown>[] = [],
) {
    const canScrollStart = ref(false);
    const canScrollEnd = ref(false);

    let resizeObserver: ResizeObserver | null = null;

    function isElementRtl(el: HTMLElement): boolean {
        if (typeof window === 'undefined') {
            return false;
        }

        return window.getComputedStyle(el).direction === 'rtl';
    }

    function updateScrollState(): void {
        const el = containerRef.value;
        if (!el) {
            canScrollStart.value = false;
            canScrollEnd.value = false;
            return;
        }

        const maxScroll = el.scrollWidth - el.clientWidth;
        if (maxScroll <= 2) {
            canScrollStart.value = false;
            canScrollEnd.value = false;
            return;
        }

        const offsetFromStart = Math.abs(el.scrollLeft);
        canScrollStart.value = offsetFromStart > 4;
        canScrollEnd.value = offsetFromStart < maxScroll - 4;
    }

    function scrollByDirection(direction: -1 | 1): void {
        const el = containerRef.value;
        if (!el) {
            return;
        }

        const dirFactor = isElementRtl(el) ? -1 : 1;
        const step = Math.max(160, Math.round(el.clientWidth * 0.6));

        el.scrollBy({
            left: dirFactor * direction * step,
            behavior: 'smooth',
        });
    }

    function handleWheel(event: WheelEvent): void {
        const el = containerRef.value;
        if (!el || el.scrollWidth <= el.clientWidth) {
            return;
        }

        if (Math.abs(event.deltaY) > Math.abs(event.deltaX)) {
            event.preventDefault();
            const dirFactor = isElementRtl(el) ? -1 : 1;
            el.scrollLeft += dirFactor * event.deltaY;
            updateScrollState();
        }
    }

    function scrollChildIntoView(target: HTMLElement | null | undefined): void {
        if (!target || typeof target.scrollIntoView !== 'function') {
            updateScrollState();
            return;
        }

        target.scrollIntoView({
            inline: 'center',
            block: 'nearest',
            behavior: 'smooth',
        });
        updateScrollState();
    }

    watch(
        containerRef,
        (el) => {
            resizeObserver?.disconnect();
            resizeObserver = null;

            if (el && typeof ResizeObserver !== 'undefined') {
                resizeObserver = new ResizeObserver(() => {
                    updateScrollState();
                });
                resizeObserver.observe(el);
            }

            void nextTick(() => updateScrollState());
        },
        { flush: 'post' },
    );

    if (watchSources.length > 0) {
        watch(watchSources, () => {
            void nextTick(() => updateScrollState());
        });
    }

    onMounted(() => {
        if (typeof window === 'undefined') {
            return;
        }

        void nextTick(() => updateScrollState());
        window.addEventListener('resize', updateScrollState, { passive: true });
    });

    onBeforeUnmount(() => {
        resizeObserver?.disconnect();
        resizeObserver = null;

        if (typeof window !== 'undefined') {
            window.removeEventListener('resize', updateScrollState);
        }
    });

    return {
        canScrollStart,
        canScrollEnd,
        updateScrollState,
        scrollByDirection,
        handleWheel,
        scrollChildIntoView,
    };
}
