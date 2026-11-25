<script setup lang="ts">
import { RefreshCw, Wifi, WifiOff } from '@lucide/vue';
import { useI18n } from 'vue-i18n';
import { Button } from '@/components/ui/button';
import { useNetworkStatus } from '@/composables/useNetworkStatus';

const { t } = useI18n();
const { isOnline, wasOffline } = useNetworkStatus();

function handleRetry(): void {
    if (typeof window !== 'undefined') {
        window.location.reload();
    }
}
</script>

<template>
    <div
        v-if="!isOnline"
        role="status"
        aria-live="assertive"
        class="border-b border-warning/30 bg-warning-soft px-3 py-2 text-xs text-warning-soft-foreground"
    >
        <div
            class="mx-auto flex max-w-[1360px] items-center justify-between gap-3"
        >
            <div class="flex min-w-0 items-center gap-2 font-medium">
                <WifiOff class="size-4 shrink-0" aria-hidden="true" />
                <span class="truncate">{{ t('common.offlineBanner') }}</span>
            </div>
            <Button
                type="button"
                size="sm"
                variant="outline"
                class="h-8 shrink-0 gap-1.5 rounded-lg border-warning/40 bg-card px-2.5 text-xs text-foreground"
                @click="handleRetry"
            >
                <RefreshCw class="size-3.5" aria-hidden="true" />
                <span>{{ t('common.retry') }}</span>
            </Button>
        </div>
    </div>

    <div
        v-else-if="wasOffline"
        role="status"
        aria-live="polite"
        class="border-b border-success/30 bg-success-soft px-3 py-1.5 text-xs text-success-soft-foreground"
    >
        <div
            class="mx-auto flex max-w-[1360px] items-center justify-center gap-2 font-medium"
        >
            <Wifi class="size-3.5 shrink-0" aria-hidden="true" />
            <span>{{ t('common.backOnline') }}</span>
        </div>
    </div>
</template>
