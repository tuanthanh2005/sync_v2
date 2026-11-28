<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import AppLogoIcon from '@/components/AppLogoIcon.vue';

const { showTagline = true } = defineProps<{
    showTagline?: boolean;
}>();

const { t } = useI18n();
const page = usePage();
const brandName = computed(() => page.props.name);
const branding = computed(() => page.props.systemSettings?.branding);

const lightLogoFailed = ref(false);
const darkLogoFailed = ref(false);

const customLogoLight = computed(() =>
    lightLogoFailed.value ? null : (branding.value?.logoLightUrl ?? null),
);
const customLogoDark = computed(() =>
    darkLogoFailed.value ? null : (branding.value?.logoDarkUrl ?? null),
);

watch(
    () => branding.value?.logoLightUrl,
    () => {
        lightLogoFailed.value = false;
    },
);
watch(
    () => branding.value?.logoDarkUrl,
    () => {
        darkLogoFailed.value = false;
    },
);

const hasCustomLogo = computed(() =>
    Boolean(customLogoLight.value || customLogoDark.value),
);

const displayTagline = computed(() => {
    const raw = (page.props.systemSettings?.siteSlogan ?? '').trim();
    const isBuiltInDefault =
        raw === '' ||
        raw === 'Connect Beyond Borders.' ||
        raw === 'Global Social Community';

    return isBuiltInDefault ? t('brand.tagline') : raw;
});
</script>

<template>
    <div class="flex min-w-0 items-center gap-2.5">
        <template v-if="hasCustomLogo">
            <img
                v-if="customLogoLight"
                :src="customLogoLight"
                :alt="brandName"
                :class="[
                    'h-8 w-auto max-w-[144px] shrink-0 object-contain',
                    customLogoDark ? 'dark:hidden' : '',
                ]"
                @error="lightLogoFailed = true"
            />
            <img
                v-if="customLogoDark"
                :src="customLogoDark"
                :alt="brandName"
                :class="[
                    'h-8 w-auto max-w-[144px] shrink-0 object-contain',
                    customLogoLight ? 'hidden dark:block' : '',
                ]"
                @error="darkLogoFailed = true"
            />
        </template>
        <template v-else>
            <div
                class="flex size-9 shrink-0 items-center justify-center rounded-lg border border-primary/15 bg-primary-soft"
            >
                <AppLogoIcon class="size-6" />
            </div>
            <div class="flex min-w-0 flex-col text-start leading-none">
                <span
                    class="truncate text-base font-bold tracking-tight text-foreground"
                >
                    {{ brandName }}
                </span>
                <span
                    v-if="showTagline"
                    class="hidden truncate pt-0.5 text-[11px] font-medium text-muted-foreground sm:block"
                >
                    {{ displayTagline }}
                </span>
            </div>
        </template>
    </div>
</template>
