<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { ConfigProvider } from 'reka-ui';
import { computed } from 'vue';

/**
 * Persistent root provider shared by every Inertia layout.
 *
 * `scroll-body=false` disables Reka UI's automatic `padding-right` scrollbar
 * compensation on <body>. The scrollbar gutter is already reserved globally via
 * `html { scrollbar-gutter: stable }`, so compensating again would double the
 * gap and squeeze/shift the App Shell whenever a Dialog, Sheet or Select opens.
 * Scroll locking itself (overflow: hidden + focus trap) stays fully active.
 */
const page = usePage();
const direction = computed<'ltr' | 'rtl'>(() =>
    page.props.isRtl ? 'rtl' : 'ltr',
);
</script>

<template>
    <ConfigProvider :scroll-body="false" :dir="direction">
        <slot />
    </ConfigProvider>
</template>
