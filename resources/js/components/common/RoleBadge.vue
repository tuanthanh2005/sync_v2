<script setup lang="ts">
import { Crown, ShieldCheck, User } from '@lucide/vue';
import { computed } from 'vue';
import { Badge } from '@/components/ui/badge';
import type { UserRole } from '@/types';

const props = defineProps<{
    role: UserRole;
    label?: string;
}>();

const badgeStyle = computed(() => {
    switch (props.role) {
        case 'admin':
            return {
                icon: Crown,
                classes:
                    'border-primary/25 bg-primary-soft text-accent-foreground',
                defaultLabel: 'Quản trị viên',
            };
        case 'moderator':
            return {
                icon: ShieldCheck,
                classes: 'border-border bg-secondary text-foreground',
                defaultLabel: 'Điều hành viên',
            };
        default:
            return {
                icon: User,
                classes: 'border-border bg-muted text-muted-foreground',
                defaultLabel: 'Thành viên',
            };
    }
});
</script>

<template>
    <Badge variant="outline" :class="['gap-1 font-medium', badgeStyle.classes]">
        <component :is="badgeStyle.icon" class="size-3" aria-hidden="true" />
        <span>{{ label || badgeStyle.defaultLabel }}</span>
    </Badge>
</template>
