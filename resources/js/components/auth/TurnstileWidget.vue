<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';

declare global {
    interface Window {
        turnstile?: {
            render: (
                container: HTMLElement,
                options: {
                    sitekey: string;
                    action?: string;
                    theme?: 'light' | 'dark' | 'auto';
                    callback: (token: string) => void;
                    'expired-callback'?: () => void;
                    'error-callback'?: () => void;
                },
            ) => string;
            reset: (widgetId?: string) => void;
            remove: (widgetId?: string) => void;
        };
    }
}

const props = withDefaults(
    defineProps<{
        siteKey: string;
        action?: string;
        modelValue?: string;
    }>(),
    {
        action: 'auth',
        modelValue: '',
    },
);

const emit = defineEmits<{
    'update:modelValue': [value: string];
}>();

const { t } = useI18n();
const containerRef = ref<HTMLElement | null>(null);
const loadError = ref(false);
let widgetId: string | null = null;

const isActive = computed(() => props.siteKey.trim().length > 0);

function currentTheme(): 'light' | 'dark' {
    if (typeof document === 'undefined') {
        return 'light';
    }

    return document.documentElement.classList.contains('dark')
        ? 'dark'
        : 'light';
}

function renderWidget(): void {
    if (!isActive.value || !containerRef.value || !window.turnstile) {
        return;
    }

    if (widgetId !== null) {
        try {
            window.turnstile.remove(widgetId);
        } catch {
            // Ignore cleanup errors
        }
        widgetId = null;
    }

    loadError.value = false;
    widgetId = window.turnstile.render(containerRef.value, {
        sitekey: props.siteKey.trim(),
        action: props.action,
        theme: currentTheme(),
        callback: (token: string) => {
            loadError.value = false;
            emit('update:modelValue', token);
        },
        'expired-callback': () => {
            emit('update:modelValue', '');
        },
        'error-callback': () => {
            emit('update:modelValue', '');
            loadError.value = true;
        },
    });
}

function loadTurnstileScript(): Promise<void> {
    if (typeof window === 'undefined') {
        return Promise.resolve();
    }

    if (window.turnstile) {
        return Promise.resolve();
    }

    const existing = document.querySelector<HTMLScriptElement>(
        'script[data-syncviet-turnstile="1"]',
    );

    if (existing) {
        return new Promise((resolve, reject) => {
            if (window.turnstile) {
                resolve();
                return;
            }
            existing.addEventListener('load', () => resolve(), { once: true });
            existing.addEventListener(
                'error',
                () => reject(new Error('Turnstile script failed')),
                { once: true },
            );
        });
    }

    return new Promise((resolve, reject) => {
        const script = document.createElement('script');
        script.src =
            'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit';
        script.async = true;
        script.defer = true;
        script.dataset.syncvietTurnstile = '1';
        script.onload = () => resolve();
        script.onerror = () => reject(new Error('Turnstile script failed'));
        document.head.appendChild(script);
    });
}

function reset(): void {
    emit('update:modelValue', '');
    if (widgetId !== null && window.turnstile) {
        try {
            window.turnstile.reset(widgetId);
        } catch {
            // Ignore reset errors
        }
    }
}

defineExpose({ reset });

onMounted(async () => {
    if (!isActive.value) {
        return;
    }

    try {
        await loadTurnstileScript();
        renderWidget();
    } catch {
        loadError.value = true;
    }
});

watch(
    () => props.siteKey,
    () => {
        if (isActive.value) {
            renderWidget();
        }
    },
);

onBeforeUnmount(() => {
    if (widgetId !== null && window.turnstile) {
        try {
            window.turnstile.remove(widgetId);
        } catch {
            // Ignore cleanup errors
        }
        widgetId = null;
    }
});
</script>

<template>
    <div v-if="isActive" class="space-y-1.5">
        <div ref="containerRef" class="min-h-[65px]" />
        <p v-if="loadError" class="text-xs text-destructive">
            {{ t('auth.turnstile.loadError') }}
        </p>
    </div>
</template>
