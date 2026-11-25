<script setup lang="ts">
import { Moon, Sun } from '@lucide/vue';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { Button } from '@/components/ui/button';
import { useAppearance } from '@/composables/useAppearance';

const { t } = useI18n();
const { resolvedAppearance, updateAppearance } = useAppearance();

const isDark = computed(() => resolvedAppearance.value === 'dark');

function toggleTheme(): void {
    updateAppearance(isDark.value ? 'light' : 'dark');
}
</script>

<template>
    <Button
        type="button"
        variant="ghost"
        size="icon"
        class="size-9 rounded-lg text-muted-foreground hover:bg-accent hover:text-accent-foreground"
        :aria-label="isDark ? t('theme.toLight') : t('theme.toDark')"
        :title="isDark ? t('theme.light') : t('theme.dark')"
        @click="toggleTheme"
    >
        <Sun v-if="isDark" class="size-4.5" />
        <Moon v-else class="size-4.5" />
    </Button>
</template>
