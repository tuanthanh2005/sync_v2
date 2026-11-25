<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ExternalLink, EyeOff, Flag, Info, MoreHorizontal } from '@lucide/vue';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { appToast } from '@/lib/toast';
import type { ActiveBannerDisplay } from '@/types';

const props = withDefaults(
    defineProps<{
        banner: ActiveBannerDisplay | null | undefined;
        compact?: boolean;
    }>(),
    {
        compact: false,
    },
);

const { t } = useI18n();
const dismissed = ref(false);
const showInfo = ref(false);

function sendFeedback(eventType: 'hide' | 'report'): void {
    if (!props.banner) {
        return;
    }

    if (eventType === 'hide') {
        dismissed.value = true;
    }

    router.post(
        `/banners/${props.banner.id}/event`,
        {
            event_type: eventType,
            reason:
                eventType === 'report' ? 'Reported via banner menu' : undefined,
        },
        {
            preserveScroll: true,
            preserveState: true,
            onError: () => {
                appToast.error(t('common.actionFailed'));
            },
        },
    );
}
</script>

<template>
    <aside
        v-if="banner && !dismissed"
        :aria-label="t('monetization.banner.sponsoredLabel')"
        class="overflow-hidden rounded-xl border border-border bg-card shadow-xs"
    >
        <div
            class="flex items-center justify-between gap-2 border-b border-border/70 px-3 py-1.5"
        >
            <div class="flex min-w-0 items-center gap-2">
                <span
                    class="rounded bg-muted px-1.5 py-0.5 text-[10px] font-semibold tracking-wide text-muted-foreground uppercase"
                >
                    {{ t('monetization.banner.sponsoredLabel') }}
                </span>
                <span
                    v-if="banner.business_name"
                    class="truncate text-[11px] font-medium text-muted-foreground"
                >
                    {{ banner.business_name }}
                </span>
            </div>

            <DropdownMenu>
                <DropdownMenuTrigger as-child>
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        class="size-7 rounded-md text-muted-foreground"
                        :aria-label="t('monetization.banner.adOptions')"
                    >
                        <MoreHorizontal class="size-3.5" />
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end" class="w-48 rounded-xl">
                    <DropdownMenuItem
                        class="cursor-pointer text-xs"
                        @click="showInfo = !showInfo"
                    >
                        <Info class="mr-2 size-3.5" />
                        <span>{{ t('monetization.banner.adInfo') }}</span>
                    </DropdownMenuItem>
                    <DropdownMenuItem
                        class="cursor-pointer text-xs"
                        @click="sendFeedback('hide')"
                    >
                        <EyeOff class="mr-2 size-3.5" />
                        <span>{{ t('monetization.banner.hideAd') }}</span>
                    </DropdownMenuItem>
                    <DropdownMenuItem
                        class="cursor-pointer text-xs text-destructive focus:text-destructive"
                        @click="sendFeedback('report')"
                    >
                        <Flag class="mr-2 size-3.5" />
                        <span>{{ t('monetization.banner.reportAd') }}</span>
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>
        </div>

        <div
            v-if="showInfo"
            class="border-b border-border bg-muted/40 px-3 py-2 text-[11px] text-muted-foreground"
        >
            {{ t('monetization.banner.adInfoDesc') }}
        </div>

        <a
            :href="banner.click_url"
            target="_blank"
            rel="noopener noreferrer sponsored"
            class="group block focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
        >
            <div class="relative overflow-hidden bg-muted/30">
                <img
                    v-if="banner.desktop_image_url"
                    :src="banner.desktop_image_url"
                    :alt="banner.alt_text"
                    loading="lazy"
                    decoding="async"
                    :class="[
                        'hidden w-full object-cover transition-transform duration-200 group-hover:scale-[1.01] sm:block',
                        compact ? 'max-h-[260px]' : 'max-h-[140px]',
                    ]"
                />
                <img
                    v-if="banner.mobile_image_url || banner.desktop_image_url"
                    :src="
                        banner.mobile_image_url ||
                        banner.desktop_image_url ||
                        ''
                    "
                    :alt="banner.alt_text"
                    loading="lazy"
                    decoding="async"
                    class="block max-h-[140px] w-full object-cover sm:hidden"
                />
            </div>

            <div
                v-if="banner.headline || banner.cta_label"
                class="flex items-center justify-between gap-2 px-3 py-2.5"
            >
                <p
                    v-if="banner.headline"
                    class="line-clamp-1 min-w-0 flex-1 text-xs font-semibold text-foreground group-hover:text-primary"
                >
                    {{ banner.headline }}
                </p>
                <span
                    class="inline-flex shrink-0 items-center gap-1 rounded-lg bg-primary-soft px-2.5 py-1 text-[11px] font-semibold text-accent-foreground"
                >
                    <span>{{ banner.cta_label }}</span>
                    <ExternalLink class="size-3" />
                </span>
            </div>
        </a>
    </aside>
</template>
