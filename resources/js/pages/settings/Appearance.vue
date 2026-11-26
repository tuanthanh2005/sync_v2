<script setup lang="ts">
import { Head, setLayoutProps } from '@inertiajs/vue3';
import { watchEffect } from 'vue';
import { useI18n } from 'vue-i18n';
import AppearanceTabs from '@/components/AppearanceTabs.vue';
import Heading from '@/components/Heading.vue';
import { edit } from '@/routes/appearance';

const { t } = useI18n();

watchEffect(() => {
    setLayoutProps({
        breadcrumbs: [
            {
                title: t('settings.appearance.breadcrumb'),
                href: edit(),
            },
        ],
    });
});
</script>

<template>
    <div class="space-y-6">
        <Head :title="t('settings.appearance.pageTitle')" />

        <h1 class="sr-only">{{ t('settings.appearance.srHeading') }}</h1>

        <Heading
            variant="small"
            :title="t('settings.appearance.headingTitle')"
            :description="t('settings.appearance.headingDescription')"
        />
        <AppearanceTabs />
    </div>
</template>
