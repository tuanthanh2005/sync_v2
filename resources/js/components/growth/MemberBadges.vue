<script setup lang="ts">
import { Award, Crown, Gift, ShieldCheck, Users } from '@lucide/vue';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import type { MemberBadgeItem } from '@/types';

const props = withDefaults(
    defineProps<{
        badges?: MemberBadgeItem[];
        size?: 'sm' | 'md';
        max?: number;
    }>(),
    {
        badges: () => [],
        size: 'sm',
        max: 2,
    },
);

const { t } = useI18n();

const visibleBadges = computed(() => (props.badges ?? []).slice(0, props.max));

function badgeClasses(color: string): string {
    switch (color) {
        case 'amber':
            return 'border-amber-500/35 bg-amber-500/10 text-amber-700 dark:border-amber-400/35 dark:bg-amber-500/15 dark:text-amber-300';
        case 'emerald':
            return 'border-emerald-500/35 bg-emerald-500/10 text-emerald-700 dark:border-emerald-400/35 dark:bg-emerald-500/15 dark:text-emerald-300';
        case 'sky':
            return 'border-sky-500/35 bg-sky-500/10 text-sky-700 dark:border-sky-400/35 dark:bg-sky-500/15 dark:text-sky-300';
        default:
            return 'border-indigo-500/35 bg-indigo-500/10 text-indigo-700 dark:border-indigo-400/35 dark:bg-indigo-500/15 dark:text-indigo-300';
    }
}

function resolveIcon(icon: string) {
    switch (icon) {
        case 'crown':
            return Crown;
        case 'gift':
            return Gift;
        case 'shield-check':
            return ShieldCheck;
        case 'users':
            return Users;
        default:
            return Award;
    }
}

function badgeLabel(badge: MemberBadgeItem): string {
    const base = t(badge.label_key);
    if (badge.type === 'early_member' && badge.member_number) {
        return `${base} #${badge.member_number}`;
    }

    return base;
}
</script>

<template>
    <span
        v-for="b in visibleBadges"
        :key="b.type"
        :title="badgeLabel(b)"
        :class="[
            'inline-flex shrink-0 items-center gap-1 rounded-md border font-semibold',
            badgeClasses(b.color),
            size === 'md' ? 'px-2 py-0.5 text-xs' : 'h-5 px-1.5 text-[10px]',
        ]"
    >
        <component
            :is="resolveIcon(b.icon)"
            :class="size === 'md' ? 'size-3.5' : 'size-3'"
            aria-hidden="true"
        />
        <span>{{ badgeLabel(b) }}</span>
    </span>
</template>
