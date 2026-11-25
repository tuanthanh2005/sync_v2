<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import AppearanceTabs from '@/components/AppearanceTabs.vue';
import Heading from '@/components/Heading.vue';
import { edit } from '@/routes/appearance';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Giao diện',
                href: edit(),
            },
        ],
    },
});
</script>

<template>
    <div class="space-y-6">
        <Head title="Giao diện" />

        <h1 class="sr-only">Cài đặt giao diện</h1>

        <Heading
            variant="small"
            title="Chế độ hiển thị"
            description="Chọn giao diện Sáng (Light), Tối (Dark) hoặc đồng bộ theo thiết bị"
        />
        <AppearanceTabs />
    </div>
</template>
