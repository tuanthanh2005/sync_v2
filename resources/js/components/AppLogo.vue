<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import AppLogoIcon from '@/components/AppLogoIcon.vue';

const { showTagline = true } = defineProps<{
    showTagline?: boolean;
}>();

const { t } = useI18n();
const page = usePage();
const brandName = computed(() => page.props.name);
</script>

<template>
    <div class="flex items-center gap-2.5">
        <div
            class="flex size-9 shrink-0 items-center justify-center rounded-lg border border-primary/15 bg-primary-soft"
        >
            <AppLogoIcon class="size-6" />
        </div>
        <div class="flex flex-col text-left leading-none">
            <span class="text-base font-bold tracking-tight text-foreground">
                {{ brandName }}
            </span>
            <span
                v-if="showTagline"
                class="text-[11px] font-medium text-muted-foreground"
            >
                {{ t('brand.tagline') }}
            </span>
        </div>
    </div>
</template>
