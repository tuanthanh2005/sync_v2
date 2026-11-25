<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import type { Component } from 'vue';

withDefaults(
    defineProps<{
        label: string;
        value: string | number;
        hint?: string;
        icon?: Component;
        tone?: 'primary' | 'success' | 'warning' | 'destructive' | 'neutral';
        href?: string;
    }>(),
    {
        tone: 'primary',
    },
);

const toneClasses: Record<string, string> = {
    primary: 'text-primary bg-primary-soft',
    success: 'text-success bg-success-soft',
    warning: 'text-warning bg-warning-soft',
    destructive: 'text-destructive bg-destructive-soft',
    neutral: 'text-muted-foreground bg-muted',
};
</script>

<template>
    <component
        :is="href ? Link : 'div'"
        :href="href"
        :class="[
            'block rounded-xl border border-border bg-card p-4 transition-colors',
            href ? 'hover:border-primary/40 hover:bg-surface-hover/50' : '',
        ]"
    >
        <div class="flex items-start justify-between gap-2">
            <span class="text-xs font-medium text-muted-foreground">{{
                label
            }}</span>
            <span
                v-if="icon"
                :class="[
                    'flex size-8 shrink-0 items-center justify-center rounded-lg',
                    toneClasses[tone],
                ]"
            >
                <component :is="icon" class="size-4" aria-hidden="true" />
            </span>
        </div>
        <p class="mt-2 text-2xl font-bold tracking-tight text-foreground">
            {{ value }}
        </p>
        <p v-if="hint" class="mt-1 text-[11px] text-muted-foreground">
            {{ hint }}
        </p>
    </component>
</template>
