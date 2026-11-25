<script setup lang="ts">
import { Inbox } from '@lucide/vue';
import type { Component } from 'vue';

const { icon = Inbox } = defineProps<{
    title: string;
    description: string;
    icon?: Component;
}>();
</script>

<template>
    <div
        class="flex flex-col items-center justify-center rounded-xl border border-dashed border-border bg-card px-6 py-10 text-center"
    >
        <div
            class="mb-4 flex size-12 items-center justify-center rounded-full bg-primary-soft text-primary"
        >
            <component :is="icon" class="size-6" aria-hidden="true" />
        </div>
        <h3 class="text-base font-semibold text-foreground">{{ title }}</h3>
        <p
            class="mt-1.5 max-w-md text-sm leading-relaxed text-muted-foreground"
        >
            {{ description }}
        </p>
        <div
            v-if="$slots.action"
            class="mt-5 flex flex-wrap justify-center gap-2"
        >
            <slot name="action" />
        </div>
    </div>
</template>
