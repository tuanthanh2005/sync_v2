<script setup lang="ts">
import ConnectHubLayout from '@/layouts/app/ConnectHubLayout.vue';
import type { BreadcrumbItem } from '@/types';

const { breadcrumbs = [] } = defineProps<{
    breadcrumbs?: BreadcrumbItem[];
}>();
</script>

<template>
    <ConnectHubLayout :breadcrumbs="breadcrumbs">
        <slot />
        <template v-if="$slots['right-sidebar']" #right-sidebar>
            <slot name="right-sidebar" />
        </template>
    </ConnectHubLayout>
</template>
