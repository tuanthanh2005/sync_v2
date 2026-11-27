import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
import type { Ref } from 'vue';

export type ScrollbarSize = 'sm' | 'md';
export type ScrollbarOrientation = 'vertical' | 'horizontal' | 'both';

export type AutoHideScrollbarOptions = {
    /** Inactivity delay before fading out (ms). Default: 3000ms. */
    hideDelay?: number;
    /** Visual size preset: sm = 4px (6px hover), md = 6px (8px hover). */
    size?: ScrollbarSize;
    /** Allowed scroll axes. */
    orientation?: ScrollbarOrientation;
    /** Enable automatic hide after inactivity. Default: true. */
    autoHide?: boolean;
    /** Keep thumb visible at all times. Default: false. */
    alwaysVisible?: boolean;
    /** Fall back to unmodified browser native scrollbar. Default: false. */
    nativeMode?: boolean;
};

type GlobalContainerState = {
    timer: ReturnType<typeof setTimeout> | null;
    hovering: boolean;
    dragging: boolean;
};

const DEFAULT_HIDE_DELAY_MS = 3000;
const EDGE_HOVER_PROXIMITY_PX = 14;
const STYLE_CACHE_TTL_MS = 600;
const POINTER_THROTTLE_MS = 48;

type CachedElementStyle = {
    checkedAt: number;
    allowsVertical: boolean;
    allowsHorizontal: boolean;
    rtl: boolean;
};

const globalStates = new WeakMap<HTMLElement, GlobalContainerState>();
const styleCache = new WeakMap<HTMLElement, CachedElementStyle>();
let globalInitialized = false;
let globalCleanup: (() => void) | null = null;
let finePointerQuery: MediaQueryList | null = null;
let finePointerCached: boolean | null = null;
let finePointerListener: ((e: MediaQueryListEvent) => void) | null = null;

function isFinePointerDevice(): boolean {
    if (typeof window === 'undefined' || !window.matchMedia) {
        return false;
    }

    if (finePointerCached !== null) {
        return finePointerCached;
    }

    finePointerQuery = window.matchMedia('(hover: hover) and (pointer: fine)');
    finePointerCached = finePointerQuery.matches;
    finePointerListener = (e: MediaQueryListEvent) => {
        finePointerCached = e.matches;
    };
    finePointerQuery.addEventListener('change', finePointerListener);

    return finePointerCached;
}

function getElementStyleMeta(el: HTMLElement): CachedElementStyle {
    const now = performance.now();
    const cached = styleCache.get(el);
    if (cached && now - cached.checkedAt < STYLE_CACHE_TTL_MS) {
        return cached;
    }

    // Fast path for SyncViet explicit scroll containers (avoids getComputedStyle reflow cost)
    if (
        el.dataset.svScrollArea === 'true' ||
        el.dataset.svScrollable === 'true' ||
        el.classList.contains('sv-scrollbar')
    ) {
        const meta: CachedElementStyle = {
            checkedAt: now,
            allowsVertical: true,
            allowsHorizontal: true,
            rtl: el.dir === 'rtl' || document.documentElement.dir === 'rtl',
        };
        styleCache.set(el, meta);
        return meta;
    }

    const style = window.getComputedStyle(el);
    const meta: CachedElementStyle = {
        checkedAt: now,
        allowsVertical:
            style.overflowY === 'auto' || style.overflowY === 'scroll',
        allowsHorizontal:
            style.overflowX === 'auto' || style.overflowX === 'scroll',
        rtl:
            style.direction === 'rtl' || document.documentElement.dir === 'rtl',
    };
    styleCache.set(el, meta);

    return meta;
}

function getOrCreateState(el: HTMLElement): GlobalContainerState {
    let state = globalStates.get(el);
    if (!state) {
        state = { timer: null, hovering: false, dragging: false };
        globalStates.set(el, state);
    }

    return state;
}

function resolveDelay(el: HTMLElement, fallbackDelay: number): number {
    const raw = el.dataset.scrollbarDelay;
    if (raw) {
        const parsed = Number(raw);
        if (Number.isFinite(parsed) && parsed >= 0) {
            return parsed;
        }
    }

    return fallbackDelay;
}

function clearContainerTimer(state: GlobalContainerState): void {
    if (state.timer !== null) {
        clearTimeout(state.timer);
        state.timer = null;
    }
}

function scheduleHide(el: HTMLElement, delayMs: number): void {
    const state = getOrCreateState(el);
    clearContainerTimer(state);

    if (
        state.hovering ||
        state.dragging ||
        el.dataset.scrollbarMode === 'always' ||
        el.dataset.scrollbarMode === 'native'
    ) {
        return;
    }

    state.timer = setTimeout(() => {
        state.timer = null;
        if (!state.hovering && !state.dragging) {
            delete el.dataset.scrolling;
        }
    }, delayMs);
}

function markElementScrolling(el: HTMLElement, defaultDelayMs: number): void {
    if (
        el.dataset.svScrollArea === 'true' ||
        el.dataset.scrollbarMode === 'native' ||
        el.classList.contains('no-scrollbar')
    ) {
        return;
    }

    if (el !== document.documentElement && el.dataset.svScrollable !== 'true') {
        el.dataset.svScrollable = 'true';
    }

    if (el.dataset.scrolling !== 'true') {
        el.dataset.scrolling = 'true';
    }
    scheduleHide(el, resolveDelay(el, defaultDelayMs));
}

function isScrollableElement(el: HTMLElement): boolean {
    if (el === document.documentElement) {
        return (
            el.scrollHeight > el.clientHeight + 1 ||
            el.scrollWidth > el.clientWidth + 1
        );
    }

    const hasVertical = el.scrollHeight > el.clientHeight + 1;
    const hasHorizontal = el.scrollWidth > el.clientWidth + 1;
    if (!hasVertical && !hasHorizontal) {
        return false;
    }

    const meta = getElementStyleMeta(el);
    return (
        (hasVertical && meta.allowsVertical) ||
        (hasHorizontal && meta.allowsHorizontal)
    );
}

function isPointerNearScrollbarEdge(
    el: HTMLElement,
    clientX: number,
    clientY: number,
): boolean {
    const isRtl =
        el === document.documentElement
            ? document.documentElement.dir === 'rtl'
            : getElementStyleMeta(el).rtl;

    if (el === document.documentElement) {
        const hasV = el.scrollHeight > el.clientHeight + 1;
        const hasH = el.scrollWidth > el.clientWidth + 1;
        if (!hasV && !hasH) {
            return false;
        }
        const nearVertical =
            hasV &&
            (isRtl
                ? clientX <= EDGE_HOVER_PROXIMITY_PX
                : clientX >= window.innerWidth - EDGE_HOVER_PROXIMITY_PX);
        const nearHorizontal =
            hasH && clientY >= window.innerHeight - EDGE_HOVER_PROXIMITY_PX;

        return nearVertical || nearHorizontal;
    }

    const rect = el.getBoundingClientRect();
    const hasV = el.scrollHeight > el.clientHeight + 1;
    const hasH = el.scrollWidth > el.clientWidth + 1;

    const withinY = clientY >= rect.top && clientY <= rect.bottom;
    const withinX = clientX >= rect.left && clientX <= rect.right;

    const nearVertical =
        hasV &&
        withinY &&
        (isRtl
            ? clientX >= rect.left &&
              clientX <= rect.left + EDGE_HOVER_PROXIMITY_PX
            : clientX <= rect.right &&
              clientX >= rect.right - EDGE_HOVER_PROXIMITY_PX);

    const nearHorizontal =
        hasH &&
        withinX &&
        clientY <= rect.bottom &&
        clientY >= rect.bottom - EDGE_HOVER_PROXIMITY_PX;

    return nearVertical || nearHorizontal;
}

function findNearestScrollableAncestor(
    start: EventTarget | null,
): HTMLElement | null {
    let node = start instanceof HTMLElement ? start : null;
    while (
        node &&
        node !== document.body &&
        node !== document.documentElement
    ) {
        if (
            node.dataset.svScrollArea !== 'true' &&
            !node.classList.contains('no-scrollbar') &&
            node.dataset.scrollbarMode !== 'native' &&
            isScrollableElement(node)
        ) {
            return node;
        }
        node = node.parentElement;
    }

    return isScrollableElement(document.documentElement)
        ? document.documentElement
        : null;
}

/**
 * Initializes one single passive, zero-MutationObserver global auto-hide
 * scrollbar controller for the document and all scrollable containers.
 * Safe to call once in app bootstrap (`resources/js/app.ts`).
 */
export function initializeAutoHideScrollbars(
    defaultDelayMs = DEFAULT_HIDE_DELAY_MS,
): () => void {
    if (typeof window === 'undefined' || typeof document === 'undefined') {
        return () => {};
    }

    if (globalInitialized && globalCleanup) {
        return globalCleanup;
    }

    let hoveredElement: HTMLElement | null = null;
    let draggingElement: HTMLElement | null = null;
    let pointerMoveFrame: number | null = null;
    let lastPointerEvent: PointerEvent | null = null;

    const clearHover = (el: HTMLElement | null): void => {
        if (!el) {
            return;
        }
        const state = getOrCreateState(el);
        state.hovering = false;
        delete el.dataset.scrollbarHover;
        if (el.dataset.scrolling === 'true') {
            scheduleHide(el, resolveDelay(el, defaultDelayMs));
        }
    };

    const handleScrollCapture = (event: Event): void => {
        if (!isFinePointerDevice()) {
            return;
        }

        const target = event.target;
        const scrollEl =
            target === document ||
            target === document.body ||
            target === document.documentElement
                ? document.documentElement
                : target instanceof HTMLElement
                  ? target
                  : null;

        if (!scrollEl) {
            return;
        }

        markElementScrolling(scrollEl, defaultDelayMs);
    };

    let lastPointerCheckAt = 0;

    const processPointerMove = (): void => {
        pointerMoveFrame = null;
        const event = lastPointerEvent;
        lastPointerEvent = null;

        if (!event || !isFinePointerDevice()) {
            return;
        }

        const now = performance.now();
        if (!hoveredElement && now - lastPointerCheckAt < POINTER_THROTTLE_MS) {
            return;
        }
        lastPointerCheckAt = now;

        // Fast check: keep current hovered scrollbar if cursor remains on its edge
        if (
            hoveredElement &&
            hoveredElement.isConnected &&
            isPointerNearScrollbarEdge(
                hoveredElement,
                event.clientX,
                event.clientY,
            )
        ) {
            return;
        }

        const docEl = document.documentElement;
        if (
            docEl.scrollHeight > docEl.clientHeight + 1 &&
            isPointerNearScrollbarEdge(docEl, event.clientX, event.clientY)
        ) {
            if (hoveredElement !== docEl) {
                clearHover(hoveredElement);
                hoveredElement = docEl;
                const state = getOrCreateState(docEl);
                state.hovering = true;
                clearContainerTimer(state);
                docEl.dataset.scrollbarHover = 'true';
            }
            return;
        }

        const candidate = findNearestScrollableAncestor(event.target);
        let nextHovered: HTMLElement | null = null;

        if (
            candidate &&
            isPointerNearScrollbarEdge(candidate, event.clientX, event.clientY)
        ) {
            nextHovered = candidate;
        }

        if (hoveredElement !== nextHovered) {
            clearHover(hoveredElement);
            hoveredElement = nextHovered;

            if (hoveredElement) {
                if (
                    hoveredElement !== document.documentElement &&
                    hoveredElement.dataset.svScrollable !== 'true'
                ) {
                    hoveredElement.dataset.svScrollable = 'true';
                }
                const state = getOrCreateState(hoveredElement);
                state.hovering = true;
                clearContainerTimer(state);
                hoveredElement.dataset.scrollbarHover = 'true';
            }
        }
    };

    const handlePointerMove = (event: PointerEvent): void => {
        if (event.pointerType !== 'mouse' && event.pointerType !== 'pen') {
            return;
        }

        lastPointerEvent = event;
        if (pointerMoveFrame === null) {
            pointerMoveFrame = window.requestAnimationFrame(processPointerMove);
        }
    };

    const handlePointerDown = (event: PointerEvent): void => {
        if (
            (event.pointerType !== 'mouse' && event.pointerType !== 'pen') ||
            !isFinePointerDevice()
        ) {
            return;
        }

        const candidate =
            hoveredElement ?? findNearestScrollableAncestor(event.target);
        if (
            candidate &&
            isPointerNearScrollbarEdge(candidate, event.clientX, event.clientY)
        ) {
            draggingElement = candidate;
            const state = getOrCreateState(candidate);
            state.dragging = true;
            clearContainerTimer(state);
            candidate.dataset.scrollbarDragging = 'true';
        }
    };

    const releaseDrag = (): void => {
        if (!draggingElement) {
            return;
        }

        const el = draggingElement;
        draggingElement = null;
        const state = getOrCreateState(el);
        state.dragging = false;
        delete el.dataset.scrollbarDragging;
        if (el.dataset.scrolling === 'true') {
            scheduleHide(el, resolveDelay(el, defaultDelayMs));
        }
    };

    const handleDocumentLeave = (): void => {
        clearHover(hoveredElement);
        hoveredElement = null;
    };

    document.addEventListener('scroll', handleScrollCapture, {
        capture: true,
        passive: true,
    });
    document.addEventListener('pointermove', handlePointerMove, {
        passive: true,
    });
    document.addEventListener('pointerdown', handlePointerDown, {
        passive: true,
    });
    window.addEventListener('pointerup', releaseDrag, { passive: true });
    window.addEventListener('pointercancel', releaseDrag, { passive: true });
    document.addEventListener('mouseleave', handleDocumentLeave, {
        passive: true,
    });

    globalInitialized = true;
    globalCleanup = () => {
        document.removeEventListener('scroll', handleScrollCapture, {
            capture: true,
        });
        document.removeEventListener('pointermove', handlePointerMove);
        document.removeEventListener('pointerdown', handlePointerDown);
        window.removeEventListener('pointerup', releaseDrag);
        window.removeEventListener('pointercancel', releaseDrag);
        document.removeEventListener('mouseleave', handleDocumentLeave);
        if (pointerMoveFrame !== null) {
            window.cancelAnimationFrame(pointerMoveFrame);
            pointerMoveFrame = null;
        }
        clearHover(hoveredElement);
        hoveredElement = null;
        releaseDrag();
        globalInitialized = false;
        globalCleanup = null;
    };

    return globalCleanup;
}

/**
 * Reusable per-container composable for components that want explicit control
 * over hide delay, scrollbar size, always-visible, or native fallback modes.
 */
export function useAutoHideScrollbar(
    targetRef: Ref<HTMLElement | null>,
    options: AutoHideScrollbarOptions = {},
) {
    const hideDelay = options.hideDelay ?? DEFAULT_HIDE_DELAY_MS;
    const autoHide = options.autoHide ?? true;
    const alwaysVisible = options.alwaysVisible ?? false;
    const nativeMode = options.nativeMode ?? false;

    const isScrolling = ref(false);
    const isHovering = ref(false);
    const isDragging = ref(false);
    let hideTimer: ReturnType<typeof setTimeout> | null = null;
    let boundEl: HTMLElement | null = null;

    function clearTimer(): void {
        if (hideTimer !== null) {
            clearTimeout(hideTimer);
            hideTimer = null;
        }
    }

    function applyModeAttributes(el: HTMLElement): void {
        el.dataset.svScrollArea = 'true';
        el.dataset.scrollbarDelay = String(hideDelay);

        if (nativeMode) {
            el.dataset.scrollbarMode = 'native';
        } else if (alwaysVisible || !autoHide) {
            el.dataset.scrollbarMode = 'always';
        } else {
            delete el.dataset.scrollbarMode;
        }

        if (options.size === 'md') {
            el.style.setProperty('--scrollbar-size', '8px');
        } else if (options.size === 'sm') {
            el.style.setProperty('--scrollbar-size', '6px');
        }
    }

    function hideNow(): void {
        clearTimer();
        isScrolling.value = false;
        if (boundEl) {
            delete boundEl.dataset.scrolling;
        }
    }

    function resetTimer(): void {
        clearTimer();
        if (
            !autoHide ||
            alwaysVisible ||
            nativeMode ||
            isHovering.value ||
            isDragging.value
        ) {
            return;
        }

        hideTimer = setTimeout(() => {
            hideTimer = null;
            if (!isHovering.value && !isDragging.value) {
                isScrolling.value = false;
                if (boundEl) {
                    delete boundEl.dataset.scrolling;
                }
            }
        }, hideDelay);
    }

    function show(): void {
        if (!boundEl || nativeMode) {
            return;
        }

        if (!isScrolling.value) {
            isScrolling.value = true;
        }
        if (boundEl.dataset.scrolling !== 'true') {
            boundEl.dataset.scrolling = 'true';
        }
        resetTimer();
    }

    function onScroll(): void {
        show();
    }

    let containerPointerCheckAt = 0;

    function onPointerMove(event: PointerEvent): void {
        if (
            !boundEl ||
            (event.pointerType !== 'mouse' && event.pointerType !== 'pen')
        ) {
            return;
        }

        const now = performance.now();
        if (
            !isHovering.value &&
            now - containerPointerCheckAt < POINTER_THROTTLE_MS
        ) {
            return;
        }
        containerPointerCheckAt = now;

        const near = isPointerNearScrollbarEdge(
            boundEl,
            event.clientX,
            event.clientY,
        );
        if (near !== isHovering.value) {
            isHovering.value = near;
            if (near) {
                clearTimer();
                boundEl.dataset.scrollbarHover = 'true';
            } else {
                delete boundEl.dataset.scrollbarHover;
                if (isScrolling.value) {
                    resetTimer();
                }
            }
        }
    }

    function onPointerLeave(): void {
        if (!boundEl || !isHovering.value) {
            return;
        }
        isHovering.value = false;
        delete boundEl.dataset.scrollbarHover;
        if (isScrolling.value) {
            resetTimer();
        }
    }

    function onPointerDown(event: PointerEvent): void {
        if (
            !boundEl ||
            (event.pointerType !== 'mouse' && event.pointerType !== 'pen')
        ) {
            return;
        }

        if (isPointerNearScrollbarEdge(boundEl, event.clientX, event.clientY)) {
            isDragging.value = true;
            clearTimer();
            boundEl.dataset.scrollbarDragging = 'true';
            window.addEventListener('pointerup', onPointerUp, {
                passive: true,
                once: true,
            });
            window.addEventListener('pointercancel', onPointerUp, {
                passive: true,
                once: true,
            });
        }
    }

    function onPointerUp(): void {
        if (!isDragging.value) {
            return;
        }
        isDragging.value = false;
        if (boundEl) {
            delete boundEl.dataset.scrollbarDragging;
        }
        if (isScrolling.value) {
            resetTimer();
        }
    }

    function unbind(): void {
        clearTimer();
        if (boundEl) {
            boundEl.removeEventListener('scroll', onScroll);
            boundEl.removeEventListener('pointermove', onPointerMove);
            boundEl.removeEventListener('pointerleave', onPointerLeave);
            boundEl.removeEventListener('pointerdown', onPointerDown);
            delete boundEl.dataset.scrolling;
            delete boundEl.dataset.scrollbarHover;
            delete boundEl.dataset.scrollbarDragging;
            boundEl = null;
        }
        window.removeEventListener('pointerup', onPointerUp);
        window.removeEventListener('pointercancel', onPointerUp);
    }

    function bind(el: HTMLElement | null): void {
        unbind();
        if (!el) {
            return;
        }

        boundEl = el;
        applyModeAttributes(el);
        el.addEventListener('scroll', onScroll, { passive: true });
        el.addEventListener('pointermove', onPointerMove, { passive: true });
        el.addEventListener('pointerleave', onPointerLeave, { passive: true });
        el.addEventListener('pointerdown', onPointerDown, { passive: true });
    }

    onMounted(() => {
        bind(targetRef.value);
    });

    watch(targetRef, (el) => {
        bind(el);
    });

    onBeforeUnmount(() => {
        unbind();
    });

    return {
        isScrolling,
        isHovering,
        isDragging,
        show,
        hideNow,
        resetTimer,
    };
}
