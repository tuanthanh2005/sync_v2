<script setup lang="ts">
import UserBadges from '@/components/social/UserBadges.vue';
import type { MemberBadgeItem } from '@/types';

withDefaults(
    defineProps<{
        badges?: MemberBadgeItem[] | null;
        size?: 'sm' | 'md';
        max?: number;
    }>(),
    {
        badges: () => [],
        size: 'sm',
        max: 3,
    },
);
</script>

<template>
    <UserBadges
        :badges="badges"
        mode="showcase"
        :size="size"
        :max="max"
    />
</template>
