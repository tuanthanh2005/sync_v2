<script setup lang="ts">
import ConnectHubLayout from '@/layouts/app/ConnectHubLayout.vue';
import type { BreadcrumbItem } from '@/types';

const { breadcrumbs = [] } = defineProps<{
    breadcrumbs?: BreadcrumbItem[];
}>();
</script>

<template>
    <ConnectHubLayout :breadcrumbs="breadcrumbs" hide-right-sidebar>
        <slot />
    </ConnectHubLayout>
</template>
