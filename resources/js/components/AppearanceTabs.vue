<script setup lang="ts">
import { Monitor, Moon, Sun } from '@lucide/vue';
import { useAppearance } from '@/composables/useAppearance';

const { appearance, updateAppearance } = useAppearance();

const tabs = [
    { value: 'light', Icon: Sun, label: 'Sáng' },
    { value: 'dark', Icon: Moon, label: 'Tối' },
    { value: 'system', Icon: Monitor, label: 'Hệ thống' },
] as const;
</script>

<template>
    <div class="inline-flex gap-1 rounded-xl border border-border bg-muted p-1">
        <button
            v-for="{ value, Icon, label } in tabs"
            :key="value"
            type="button"
            @click="updateAppearance(value)"
            :class="[
                'flex items-center rounded-lg px-3.5 py-1.5 text-sm font-medium transition-colors',
                appearance === value
                    ? 'bg-card text-foreground shadow-xs'
                    : 'text-muted-foreground hover:text-foreground',
            ]"
        >
            <component :is="Icon" class="mr-1.5 size-4" aria-hidden="true" />
            <span>{{ label }}</span>
        </button>
    </div>
</template>
