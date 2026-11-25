import { onBeforeUnmount, onMounted, ref } from 'vue';

const isKeyboardOpen = ref(false);
const keyboardHeight = ref(0);
const viewportHeight = ref(0);

let activeSubscribers = 0;
let baseHeight = 0;

function syncViewportMetrics(): void {
    if (typeof window === 'undefined') {
        return;
    }

    const vv = window.visualViewport;
    const currentHeight = vv ? vv.height : window.innerHeight;
    viewportHeight.value = Math.round(currentHeight);

    if (baseHeight === 0 || currentHeight > baseHeight) {
        baseHeight = currentHeight;
    }

    const activeTag = document.activeElement?.tagName.toLowerCase();
    const isInputFocused =
        activeTag === 'input' ||
        activeTag === 'textarea' ||
        activeTag === 'select' ||
        document.activeElement?.getAttribute('contenteditable') === 'true';

    const delta = Math.max(0, Math.round(window.innerHeight - currentHeight));
    const keyboardVisible =
        (delta > 120 && isInputFocused) ||
        (vv !== null && delta > 150 && window.innerWidth < 1024);

    isKeyboardOpen.value = keyboardVisible;
    keyboardHeight.value = keyboardVisible ? delta : 0;

    document.documentElement.style.setProperty(
        '--sv-keyboard-height',
        `${keyboardHeight.value}px`,
    );
    document.documentElement.classList.toggle(
        'sv-keyboard-open',
        keyboardVisible,
    );
}

function handleFocusChange(): void {
    window.setTimeout(syncViewportMetrics, 60);
}

function handleOrientationChange(): void {
    baseHeight = 0;
    window.setTimeout(syncViewportMetrics, 150);
}

export function useMobileViewport() {
    onMounted(() => {
        if (typeof window === 'undefined') {
            return;
        }

        if (activeSubscribers === 0) {
            baseHeight = window.innerHeight;
            syncViewportMetrics();
            window.visualViewport?.addEventListener(
                'resize',
                syncViewportMetrics,
            );
            window.visualViewport?.addEventListener(
                'scroll',
                syncViewportMetrics,
            );
            window.addEventListener('resize', syncViewportMetrics, {
                passive: true,
            });
            window.addEventListener(
                'orientationchange',
                handleOrientationChange,
            );
            document.addEventListener('focusin', handleFocusChange);
            document.addEventListener('focusout', handleFocusChange);
        }

        activeSubscribers += 1;
    });

    onBeforeUnmount(() => {
        activeSubscribers = Math.max(0, activeSubscribers - 1);

        if (activeSubscribers === 0 && typeof window !== 'undefined') {
            window.visualViewport?.removeEventListener(
                'resize',
                syncViewportMetrics,
            );
            window.visualViewport?.removeEventListener(
                'scroll',
                syncViewportMetrics,
            );
            window.removeEventListener('resize', syncViewportMetrics);
            window.removeEventListener(
                'orientationchange',
                handleOrientationChange,
            );
            document.removeEventListener('focusin', handleFocusChange);
            document.removeEventListener('focusout', handleFocusChange);
            document.documentElement.style.setProperty(
                '--sv-keyboard-height',
                '0px',
            );
            document.documentElement.classList.remove('sv-keyboard-open');
        }
    });

    return {
        isKeyboardOpen,
        keyboardHeight,
        viewportHeight,
    };
}

export function useCompactScrollHeader() {
    const headerHidden = ref(false);
    let lastScrollY = 0;
    let ticking = false;

    function onScroll(): void {
        if (ticking || typeof window === 'undefined') {
            return;
        }

        ticking = true;
        window.requestAnimationFrame(() => {
            const currentY = Math.max(0, window.scrollY);
            const prefersReduced = window.matchMedia(
                '(prefers-reduced-motion: reduce)',
            ).matches;

            if (
                prefersReduced ||
                window.innerWidth >= 1024 ||
                currentY < 72 ||
                isKeyboardOpen.value
            ) {
                headerHidden.value = false;
            } else if (currentY > lastScrollY + 12) {
                headerHidden.value = true;
            } else if (currentY < lastScrollY - 8) {
                headerHidden.value = false;
            }

            lastScrollY = currentY;
            ticking = false;
        });
    }

    onMounted(() => {
        if (typeof window !== 'undefined') {
            lastScrollY = window.scrollY;
            window.addEventListener('scroll', onScroll, { passive: true });
        }
    });

    onBeforeUnmount(() => {
        if (typeof window !== 'undefined') {
            window.removeEventListener('scroll', onScroll);
        }
    });

    return {
        headerHidden,
    };
}
