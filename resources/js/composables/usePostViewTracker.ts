import { onBeforeUnmount, onMounted, type Ref } from 'vue';
import PostInteractionController from '@/actions/App/Http/Controllers/Social/PostInteractionController';

export type PostViewSource =
    | 'feed'
    | 'detail'
    | 'profile'
    | 'community'
    | 'search'
    | 'topic';

type QueuedView = {
    post_id: number;
    source: PostViewSource;
    is_promoted: boolean;
};

type ObservedMeta = {
    postId: number;
    source: PostViewSource;
    isPromoted: boolean;
    dwellMs: number;
    timer: ReturnType<typeof setTimeout> | null;
    isVisible: boolean;
};

const trackedPostIds = new Set<number>();
const pendingQueue = new Map<number, QueuedView>();
const observedElements = new WeakMap<Element, ObservedMeta>();
const activeElements = new Set<Element>();

let sharedObserver: IntersectionObserver | null = null;
let flushTimer: ReturnType<typeof setTimeout> | null = null;
let listenersBound = false;

const FLUSH_INTERVAL_MS = 4000;
const FLUSH_BATCH_THRESHOLD = 10;

function readXsrfToken(): string {
    if (typeof document === 'undefined') {
        return '';
    }

    const match = document.cookie
        .split('; ')
        .find((row) => row.startsWith('XSRF-TOKEN='));

    return match ? decodeURIComponent(match.slice('XSRF-TOKEN='.length)) : '';
}

function flushQueue(useKeepalive = false): void {
    if (flushTimer !== null) {
        clearTimeout(flushTimer);
        flushTimer = null;
    }

    if (pendingQueue.size === 0 || typeof window === 'undefined') {
        return;
    }

    const batch = Array.from(pendingQueue.values()).slice(0, 25);
    for (const item of batch) {
        pendingQueue.delete(item.post_id);
    }

    const xsrf = readXsrfToken();

    try {
        void fetch(PostInteractionController.trackViews.url(), {
            method: 'POST',
            credentials: 'same-origin',
            keepalive: useKeepalive,
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                ...(xsrf ? { 'X-XSRF-TOKEN': xsrf } : {}),
            },
            body: JSON.stringify({ views: batch }),
        });
    } catch {
        // Silently ignore telemetry transport errors so UI never degrades
    }
}

function scheduleFlush(): void {
    if (pendingQueue.size >= FLUSH_BATCH_THRESHOLD) {
        flushQueue(false);
        return;
    }

    if (flushTimer === null) {
        flushTimer = setTimeout(() => {
            flushQueue(false);
        }, FLUSH_INTERVAL_MS);
    }
}

function clearElementTimer(meta: ObservedMeta): void {
    if (meta.timer !== null) {
        clearTimeout(meta.timer);
        meta.timer = null;
    }
}

function startDwellIfEligible(el: Element, meta: ObservedMeta): void {
    if (
        trackedPostIds.has(meta.postId) ||
        !meta.isVisible ||
        typeof document === 'undefined' ||
        document.visibilityState !== 'visible'
    ) {
        return;
    }

    if (meta.timer !== null) {
        return;
    }

    meta.timer = setTimeout(() => {
        meta.timer = null;

        if (
            !meta.isVisible ||
            document.visibilityState !== 'visible' ||
            trackedPostIds.has(meta.postId)
        ) {
            return;
        }

        trackedPostIds.add(meta.postId);
        pendingQueue.set(meta.postId, {
            post_id: meta.postId,
            source: meta.source,
            is_promoted: meta.isPromoted,
        });
        sharedObserver?.unobserve(el);
        activeElements.delete(el);
        scheduleFlush();
    }, meta.dwellMs);
}

function handleVisibilityChange(): void {
    if (typeof document === 'undefined') {
        return;
    }

    if (document.visibilityState === 'hidden') {
        for (const el of activeElements) {
            const meta = observedElements.get(el);
            if (meta) {
                clearElementTimer(meta);
            }
        }
        flushQueue(true);
    } else if (document.visibilityState === 'visible') {
        for (const el of activeElements) {
            const meta = observedElements.get(el);
            if (meta && meta.isVisible) {
                startDwellIfEligible(el, meta);
            }
        }
    }
}

function ensureObserver(): IntersectionObserver | null {
    if (typeof window === 'undefined' || !('IntersectionObserver' in window)) {
        return null;
    }

    if (!listenersBound) {
        listenersBound = true;
        document.addEventListener('visibilitychange', handleVisibilityChange);
        window.addEventListener('pagehide', () => flushQueue(true));
    }

    if (sharedObserver === null) {
        sharedObserver = new IntersectionObserver(
            (entries) => {
                for (const entry of entries) {
                    const meta = observedElements.get(entry.target);
                    if (!meta) {
                        continue;
                    }

                    // Qualify if >= 50% visible OR (for very tall posts) >= 260px of the card is inside viewport.
                    const visibleHeight = entry.intersectionRect.height;
                    const qualified =
                        entry.isIntersecting &&
                        (entry.intersectionRatio >= 0.5 ||
                            visibleHeight >= 260);

                    meta.isVisible = qualified;

                    if (qualified) {
                        startDwellIfEligible(entry.target, meta);
                    } else {
                        clearElementTimer(meta);
                    }
                }
            },
            {
                threshold: [0, 0.25, 0.5, 0.75],
            },
        );
    }

    return sharedObserver;
}

export function usePostViewTracker(
    elementRef: Ref<HTMLElement | null>,
    options: {
        postId: number;
        source?: PostViewSource;
        isPromoted?: boolean;
    },
): void {
    onMounted(() => {
        const el = elementRef.value;
        if (!el || options.postId <= 0 || trackedPostIds.has(options.postId)) {
            return;
        }

        const observer = ensureObserver();
        if (!observer) {
            return;
        }

        const source = options.source ?? 'feed';
        const meta: ObservedMeta = {
            postId: options.postId,
            source,
            isPromoted: Boolean(options.isPromoted),
            dwellMs: source === 'detail' ? 1500 : 2000,
            timer: null,
            isVisible: false,
        };

        observedElements.set(el, meta);
        activeElements.add(el);
        observer.observe(el);
    });

    onBeforeUnmount(() => {
        const el = elementRef.value;
        if (!el) {
            return;
        }

        const meta = observedElements.get(el);
        if (meta) {
            clearElementTimer(meta);
        }

        sharedObserver?.unobserve(el);
        activeElements.delete(el);
        observedElements.delete(el);
    });
}
