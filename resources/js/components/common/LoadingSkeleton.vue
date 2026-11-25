<script setup lang="ts">
import { Skeleton } from '@/components/ui/skeleton';

const { variant = 'card', count = 2 } = defineProps<{
    variant?: 'feed' | 'card' | 'profile';
    count?: number;
}>();
</script>

<template>
    <div class="space-y-4" aria-busy="true" aria-label="Đang tải nội dung">
        <div
            v-for="item in count"
            :key="item"
            class="rounded-xl border border-border bg-card p-5 shadow-xs"
        >
            <template v-if="variant === 'profile'">
                <div class="flex items-center gap-4">
                    <Skeleton class="size-14 rounded-full" />
                    <div class="flex-1 space-y-2">
                        <Skeleton class="h-4 w-44" />
                        <Skeleton class="h-3 w-28" />
                    </div>
                </div>
                <div class="mt-5 grid gap-3 sm:grid-cols-2">
                    <Skeleton class="h-16 rounded-lg" />
                    <Skeleton class="h-16 rounded-lg" />
                </div>
            </template>

            <template v-else-if="variant === 'feed'">
                <div class="flex items-center gap-3">
                    <Skeleton class="size-10 rounded-full" />
                    <div class="flex-1 space-y-2">
                        <Skeleton class="h-3.5 w-36" />
                        <Skeleton class="h-3 w-24" />
                    </div>
                </div>
                <div class="mt-4 space-y-2.5">
                    <Skeleton class="h-4 w-3/4" />
                    <Skeleton class="h-3.5 w-full" />
                    <Skeleton class="h-3.5 w-5/6" />
                </div>
                <Skeleton class="mt-4 h-36 w-full rounded-lg" />
                <div class="mt-4 flex items-center gap-4">
                    <Skeleton class="h-4 w-14" />
                    <Skeleton class="h-4 w-14" />
                    <Skeleton class="h-4 w-14" />
                </div>
            </template>

            <template v-else>
                <div class="space-y-3">
                    <Skeleton class="h-4 w-1/3" />
                    <Skeleton class="h-3.5 w-full" />
                    <Skeleton class="h-3.5 w-4/5" />
                </div>
            </template>
        </div>
    </div>
</template>
