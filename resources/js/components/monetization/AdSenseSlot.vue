<script setup lang="ts">
import { computed, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import type { AdSensePageConfig } from '@/types';

const props = withDefaults(
    defineProps<{
        config: AdSensePageConfig | null | undefined;
        slotKey: string;
        hasDirectBanner?: boolean;
    }>(),
    {
        hasDirectBanner: false,
    },
);

const { t } = useI18n();

const validPublisherId = computed(() =>
    props.config?.publisher_id &&
    /^ca-pub-\d{16}$/.test(props.config.publisher_id)
        ? props.config.publisher_id
        : null,
);

const validSlotId = computed(() => {
    const raw = props.config?.slots?.[props.slotKey];

    return raw && /^\d{8,16}$/.test(raw) ? raw : null;
});

const shouldRender = computed(
    () =>
        !props.hasDirectBanner &&
        Boolean(props.config?.enabled) &&
        validPublisherId.value !== null &&
        validSlotId.value !== null,
);

onMounted(() => {
    if (!shouldRender.value || typeof window === 'undefined') {
        return;
    }

    const w = window as unknown as { adsbygoogle?: unknown[] };
    if (Array.isArray(w.adsbygoogle)) {
        try {
            w.adsbygoogle.push({});
        } catch {
            // Gracefully ignore AdSense push errors when blocked by client
        }
    }
});
</script>

<template>
    <aside
        v-if="shouldRender"
        :aria-label="t('monetization.banner.sponsoredLabel')"
        class="overflow-hidden rounded-xl border border-border bg-card p-3"
    >
        <p
            class="mb-1.5 text-[10px] font-semibold tracking-wide text-muted-foreground uppercase"
        >
            {{ t('monetization.banner.sponsoredLabel') }}
        </p>
        <ins
            class="adsbygoogle block"
            style="display: block"
            :data-ad-client="validPublisherId!"
            :data-ad-slot="validSlotId!"
            data-ad-format="auto"
            data-full-width-responsive="true"
        />
    </aside>
</template>
