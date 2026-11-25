<script setup lang="ts">
import { AlertTriangle, RefreshCw } from '@lucide/vue';
import { Button } from '@/components/ui/button';

const {
    title = 'Không thể tải dữ liệu',
    retryLabel = 'Thử lại',
    showRetry = true,
} = defineProps<{
    title?: string;
    message: string;
    retryLabel?: string;
    showRetry?: boolean;
}>();

const emit = defineEmits<{
    retry: [];
}>();
</script>

<template>
    <div
        role="alert"
        class="flex flex-col items-start gap-4 rounded-xl border border-destructive/30 bg-destructive/5 p-5 sm:flex-row sm:items-center sm:justify-between"
    >
        <div class="flex items-start gap-3">
            <div
                class="flex size-10 shrink-0 items-center justify-center rounded-lg bg-destructive/10 text-destructive"
            >
                <AlertTriangle class="size-5" aria-hidden="true" />
            </div>
            <div>
                <h3 class="text-sm font-semibold text-foreground">
                    {{ title }}
                </h3>
                <p class="mt-1 text-sm text-muted-foreground">{{ message }}</p>
            </div>
        </div>
        <Button
            v-if="showRetry"
            type="button"
            variant="outline"
            size="sm"
            class="shrink-0 rounded-lg"
            @click="emit('retry')"
        >
            <RefreshCw class="size-3.5" />
            <span>{{ retryLabel }}</span>
        </Button>
    </div>
</template>
