<script setup lang="ts">
import { Head, router, setLayoutProps } from '@inertiajs/vue3';
import { AlertCircle, CheckCircle2, ShieldCheck } from '@lucide/vue';
import { ref, watchEffect } from 'vue';
import { useI18n } from 'vue-i18n';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { login, register } from '@/routes';

const props = defineProps<{
    token: string | null;
    valid: boolean;
    maskedEmail: string | null;
    purpose: string | null;
}>();

const { t } = useI18n();
const submitting = ref(false);

function confirmActivation(): void {
    if (!props.token || submitting.value) {
        return;
    }

    submitting.value = true;
    router.post(
        `/verify-email/confirm/${props.token}`,
        {},
        {
            onFinish: () => {
                submitting.value = false;
            },
        },
    );
}

watchEffect(() => {
    setLayoutProps({
        title: t('auth.verifyEmail.confirmLinkTitle'),
        description: t('auth.verifyEmail.confirmLinkDescription'),
    });
});
</script>

<template>
    <div class="space-y-5">
        <Head :title="t('auth.verifyEmail.confirmLinkTitle')" />

        <div
            v-if="valid && token"
            class="space-y-4 rounded-xl border border-border bg-muted/30 p-4 text-center"
        >
            <div
                class="mx-auto flex size-12 items-center justify-center rounded-full bg-primary-soft text-primary"
            >
                <ShieldCheck class="size-6" aria-hidden="true" />
            </div>
            <p class="text-sm text-foreground">
                {{
                    t('auth.verifyEmail.confirmLinkPrompt', {
                        email: maskedEmail ?? '',
                    })
                }}
            </p>
            <Button
                type="button"
                class="w-full gap-2 rounded-lg"
                :disabled="submitting"
                data-test="confirm-verification-link-submit"
                @click="confirmActivation"
            >
                <Spinner v-if="submitting" />
                <CheckCircle2 v-else class="size-4" aria-hidden="true" />
                <span>{{ t('auth.verifyEmail.confirmLinkButton') }}</span>
            </Button>
        </div>

        <div
            v-else
            class="space-y-4 rounded-xl border border-destructive/25 bg-destructive-soft/40 p-4 text-center"
        >
            <div
                class="mx-auto flex size-12 items-center justify-center rounded-full bg-destructive-soft text-destructive"
            >
                <AlertCircle class="size-6" aria-hidden="true" />
            </div>
            <p class="text-sm font-medium text-foreground">
                {{ t('auth.verifyEmail.confirmLinkInvalid') }}
            </p>
            <div class="flex items-center justify-center gap-4 text-sm">
                <TextLink :href="login()" class="font-medium text-primary">
                    {{ t('auth.verifyEmail.backToLogin') }}
                </TextLink>
                <TextLink :href="register()" class="font-medium text-primary">
                    {{ t('auth.login.registerLink') }}
                </TextLink>
            </div>
        </div>
    </div>
</template>
