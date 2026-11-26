<script setup lang="ts">
import { Form, Head, setLayoutProps } from '@inertiajs/vue3';
import { watchEffect } from 'vue';
import { useI18n } from 'vue-i18n';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { logout } from '@/routes';
import { send } from '@/routes/verification';

defineProps<{
    status?: string;
}>();

const { t } = useI18n();

watchEffect(() => {
    setLayoutProps({
        title: t('auth.verifyEmail.layoutTitle'),
        description: t('auth.verifyEmail.layoutDescription'),
    });
});
</script>

<template>
    <div>
        <Head :title="t('auth.verifyEmail.pageTitle')" />

        <div
            v-if="status === 'verification-link-sent'"
            class="mb-4 rounded-lg border border-success/25 bg-success-soft px-3.5 py-2.5 text-center text-sm font-medium text-success-soft-foreground"
        >
            {{ t('auth.verifyEmail.linkSent') }}
        </div>

        <Form
            v-bind="send.form()"
            class="space-y-6 text-center"
            v-slot="{ processing }"
        >
            <Button
                :disabled="processing"
                variant="secondary"
                class="w-full rounded-lg"
            >
                <Spinner v-if="processing" />
                {{ t('auth.verifyEmail.resendButton') }}
            </Button>

            <TextLink
                :href="logout()"
                as="button"
                class="mx-auto block text-sm"
            >
                {{ t('auth.verifyEmail.logout') }}
            </TextLink>
        </Form>
    </div>
</template>
