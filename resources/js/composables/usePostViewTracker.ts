import { usePage } from '@inertiajs/vue3';
import {
    computed,
    onBeforeUnmount,
    onMounted,
    reactive,
    toValue,
    watch,
    type ComputedRef,
    type MaybeRefOrGetter,
    type Ref,
} from 'vue';
import PostInteractionController from '@/actions/App/Http/Controllers/Social/PostInteractionController';
import { isModalRuntimeActive } from '@/composables/useModalRuntimeLock';
import type { PostViewAnalytics } from '@/types';

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
    visible_ms: number;
    visible_ratio: number;
    is_tall_post_probe: boolean;
    is_tab_visible: boolean;
};

type ViewTrackingRuntimeConfig = {
    minDurationMs: number;
    minVisibleRatio: number;
    dedupWindowSeconds: number;
    trackGuests: boolean;
    trackAi: boolean;
};

type ObservedMeta = {
    postId: number;
    source: PostViewSource;
    isPromoted: boolean;
    timer: ReturnType<typeof setTimeout> | null;
    visibleSinceMs: number | null;
    isVisible: boolean;
    visibleRatio: number;
    isTallPostProbe: boolean;
    recordedInCurrentStay: boolean;
};

type LivePostViewState = {
    views_count: number;
    view_analytics: PostViewAnalytics | null;
};

type TrackViewsResponse = {
    recorded?: number;
    skipped?: number;
    invalid_traffic?: boolean;
    dedup_window_seconds?: number;
    counts?: Record<
        string,
        {
            views_count?: number;
            view_analytics?: PostViewAnalytics | null;
        }
    >;
};

const DEFAULT_CONFIG: ViewTrackingRuntimeConfig = {
    minDurationMs: 500,
    minVisibleRatio: 50,
    dedupWindowSeconds: 300,
    trackGuests: true,
    trackAi: true,
};

let activeConfig: ViewTrackingRuntimeConfig = { ...DEFAULT_CONFIG };

// Shared reactive store updated ONLY after backend confirmation (never optimistic before server confirms).
const livePostViews = reactive<Record<number, LivePostViewState>>({});

// Timestamped cooldown map per post_id (replaces permanent V1 set so returning after 5 min works seamlessly).
const lastTrackedAt = new Map<number, number>();
const pendingQueue = new Map<number, QueuedView>();
const observedElements = new WeakMap<Element, ObservedMeta>();
const activeElements = new Set<Element>();

let sharedObserver: IntersectionObserver | null = null;
let flushTimer: ReturnType<typeof setTimeout> | null = null;
let listenersBound = false;

const FLUSH_INTERVAL_MS = 1000;
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

function applyConfirmedCounts(
    counts: TrackViewsResponse['counts'] | undefined,
): void {
    if (!counts || typeof counts !== 'object') {
        return;
    }

    for (const [rawId, payload] of Object.entries(counts)) {
        const postId = Number(rawId);
        if (!Number.isFinite(postId) || postId <= 0 || !payload) {
            continue;
        }

        const confirmedViews =
            typeof payload.views_count === 'number' &&
            Number.isFinite(payload.views_count)
                ? Math.max(0, Math.trunc(payload.views_count))
                : null;

        if (confirmedViews === null) {
            continue;
        }

        const existing = livePostViews[postId];
        livePostViews[postId] = {
            views_count: confirmedViews,
            view_analytics:
                payload.view_analytics !== undefined
                    ? payload.view_analytics
                    : (existing?.view_analytics ?? null),
        };
    }
}

function isInCooldown(postId: number): boolean {
    const last = lastTrackedAt.get(postId);
    if (last === undefined) {
        return false;
    }

    return Date.now() - last < activeConfig.dedupWindowSeconds * 1000;
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
        const requestPromise = fetch(
            PostInteractionController.trackViews.url(),
            {
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
            },
        );

        if (!useKeepalive) {
            void requestPromise
                .then(async (response) => {
                    if (!response.ok) {
                        return;
                    }
                    const data = (await response.json()) as TrackViewsResponse;
                    if (
                        typeof data.dedup_window_seconds === 'number' &&
                        data.dedup_window_seconds >= 30
                    ) {
                        activeConfig = {
                            ...activeConfig,
                            dedupWindowSeconds: data.dedup_window_seconds,
                        };
                    }
                    applyConfirmedCounts(data.counts);
                })
                .catch(() => {
                    // Silently ignore telemetry transport errors so UI never degrades
                });
        }
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
    meta.visibleSinceMs = null;
}

function startDwellIfEligible(meta: ObservedMeta): void {
    if (
        meta.recordedInCurrentStay ||
        !meta.isVisible ||
        isModalRuntimeActive.value ||
        isInCooldown(meta.postId) ||
        typeof document === 'undefined' ||
        document.visibilityState !== 'visible'
    ) {
        return;
    }

    if (meta.timer !== null) {
        return;
    }

    const minDurationMs = Math.max(100, activeConfig.minDurationMs);
    meta.visibleSinceMs = performance.now();

    meta.timer = setTimeout(() => {
        meta.timer = null;

        if (
            !meta.isVisible ||
            meta.recordedInCurrentStay ||
            isModalRuntimeActive.value ||
            document.visibilityState !== 'visible' ||
            isInCooldown(meta.postId)
        ) {
            return;
        }

        const elapsedMs =
            meta.visibleSinceMs !== null
                ? Math.max(
                      minDurationMs,
                      Math.round(performance.now() - meta.visibleSinceMs),
                  )
                : minDurationMs;

        // Lock this viewport stay so staying idle > 5 min NEVER auto-increments views.
        meta.recordedInCurrentStay = true;
        lastTrackedAt.set(meta.postId, Date.now());

        pendingQueue.set(meta.postId, {
            post_id: meta.postId,
            source: meta.source,
            is_promoted: meta.isPromoted,
            visible_ms: elapsedMs,
            visible_ratio: meta.visibleRatio,
            is_tall_post_probe: meta.isTallPostProbe,
            is_tab_visible: true,
        });

        scheduleFlush();
    }, minDurationMs);
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
                startDwellIfEligible(meta);
            }
        }
    }
}

function evaluateVisibility(entry: IntersectionObserverEntry): {
    qualified: boolean;
    visibleRatio: number;
    isTallPostProbe: boolean;
} {
    if (!entry.isIntersecting) {
        return { qualified: false, visibleRatio: 0, isTallPostProbe: false };
    }

    const viewportHeight =
        entry.rootBounds?.height ||
        (typeof window !== 'undefined' ? window.innerHeight : 800);
    const cardHeight = entry.boundingClientRect.height;
    const visibleHeight = entry.intersectionRect.height;
    const minRatio = Math.max(10, Math.min(100, activeConfig.minVisibleRatio));

    // Representative viewport probe for posts taller than the viewport:
    // measure visible height against viewport height so tall posts can qualify cleanly.
    const isTallPost = cardHeight > viewportHeight * 0.9;
    if (isTallPost) {
        const probeDenominator = Math.max(
            1,
            Math.min(cardHeight, viewportHeight),
        );
        const probeRatio = Math.min(
            100,
            Math.round((visibleHeight / probeDenominator) * 100),
        );
        const rawRatio = Math.min(
            100,
            Math.round(entry.intersectionRatio * 100),
        );
        const qualified = probeRatio >= minRatio;

        return {
            qualified,
            visibleRatio: Math.max(rawRatio, Math.min(100, probeRatio)),
            isTallPostProbe: true,
        };
    }

    const ratioPercent = Math.min(
        100,
        Math.round(entry.intersectionRatio * 100),
    );

    return {
        qualified: ratioPercent >= minRatio,
        visibleRatio: ratioPercent,
        isTallPostProbe: false,
    };
}

function ensureObserver(): IntersectionObserver | null {
    if (typeof window === 'undefined' || !('IntersectionObserver' in window)) {
        return null;
    }

    if (!listenersBound) {
        listenersBound = true;
        document.addEventListener('visibilitychange', handleVisibilityChange);
        window.addEventListener('pagehide', () => flushQueue(true));
        watch(isModalRuntimeActive, (modalActive) => {
            if (modalActive) {
                for (const el of activeElements) {
                    const meta = observedElements.get(el);
                    if (meta) {
                        clearElementTimer(meta);
                    }
                }
                if (flushTimer !== null) {
                    clearTimeout(flushTimer);
                    flushTimer = null;
                }
            } else if (document.visibilityState === 'visible') {
                for (const el of activeElements) {
                    const meta = observedElements.get(el);
                    if (meta && meta.isVisible) {
                        startDwellIfEligible(meta);
                    }
                }
                if (pendingQueue.size > 0) {
                    scheduleFlush();
                }
            }
        });
    }

    if (sharedObserver === null) {
        sharedObserver = new IntersectionObserver(
            (entries) => {
                for (const entry of entries) {
                    const meta = observedElements.get(entry.target);
                    if (!meta) {
                        continue;
                    }

                    const result = evaluateVisibility(entry);
                    meta.isVisible = result.qualified;
                    meta.visibleRatio = result.visibleRatio;
                    meta.isTallPostProbe = result.isTallPostProbe;

                    if (result.qualified) {
                        startDwellIfEligible(meta);
                    } else {
                        clearElementTimer(meta);
                        // Only reset stay lock when the post genuinely leaves viewport
                        // so scrolling away and returning after >= 5 minutes can record a fresh view.
                        if (!entry.isIntersecting) {
                            meta.recordedInCurrentStay = false;
                        }
                    }
                }
            },
            {
                threshold: [0, 0.1, 0.25, 0.5, 0.75, 1],
            },
        );
    }

    return sharedObserver;
}

export function useLivePostViewCount(
    postId: MaybeRefOrGetter<number>,
    initialCount: MaybeRefOrGetter<number | undefined>,
): ComputedRef<number> {
    return computed(() => {
        const id = toValue(postId);
        const propCount = Math.max(0, Number(toValue(initialCount) ?? 0));
        const confirmed = livePostViews[id]?.views_count;

        if (confirmed === undefined) {
            return propCount;
        }

        return Math.max(propCount, confirmed);
    });
}

export function useLivePostViewAnalytics(
    postId: MaybeRefOrGetter<number>,
    initialAnalytics: MaybeRefOrGetter<PostViewAnalytics | null | undefined>,
): ComputedRef<PostViewAnalytics | null> {
    return computed(() => {
        const id = toValue(postId);
        const propAnalytics = toValue(initialAnalytics) ?? null;
        const confirmed = livePostViews[id]?.view_analytics;

        if (confirmed && propAnalytics) {
            return confirmed.total_views >= propAnalytics.total_views
                ? confirmed
                : propAnalytics;
        }

        return confirmed ?? propAnalytics;
    });
}

export function usePostViewTracker(
    elementRef: Ref<HTMLElement | null>,
    options: {
        postId: number;
        source?: PostViewSource;
        isPromoted?: boolean;
    },
): void {
    const page = usePage();

    onMounted(() => {
        const el = elementRef.value;
        if (!el || options.postId <= 0) {
            return;
        }

        const serverViewsConfig = page.props.systemSettings?.views;
        if (serverViewsConfig) {
            activeConfig = {
                minDurationMs: Math.max(
                    100,
                    Number(serverViewsConfig.minDurationMs ?? 500),
                ),
                minVisibleRatio: Math.max(
                    10,
                    Math.min(
                        100,
                        Number(serverViewsConfig.minVisibleRatio ?? 50),
                    ),
                ),
                dedupWindowSeconds: Math.max(
                    30,
                    Number(serverViewsConfig.dedupWindowSeconds ?? 300),
                ),
                trackGuests: Boolean(serverViewsConfig.trackGuests ?? true),
                trackAi: Boolean(serverViewsConfig.trackAi ?? true),
            };
        }

        // Skip observing if server explicitly disabled guest or AI tracking for this viewer.
        const currentUser = page.props.auth?.user ?? null;
        if (currentUser === null && !activeConfig.trackGuests) {
            return;
        }
        if (currentUser?.is_ai_agent && !activeConfig.trackAi) {
            return;
        }

        const observer = ensureObserver();
        if (!observer) {
            return;
        }

        const meta: ObservedMeta = {
            postId: options.postId,
            source: options.source ?? 'feed',
            isPromoted: Boolean(options.isPromoted),
            timer: null,
            visibleSinceMs: null,
            isVisible: false,
            visibleRatio: 0,
            isTallPostProbe: false,
            recordedInCurrentStay: false,
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
