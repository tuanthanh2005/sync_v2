<script setup lang="ts">
import { Form, Head, setLayoutProps, usePage } from '@inertiajs/vue3';
import { computed, ref, watchEffect } from 'vue';
import { useI18n } from 'vue-i18n';
import TurnstileWidget from '@/components/auth/TurnstileWidget.vue';
import InputError from '@/components/InputError.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { login } from '@/routes';
import { email } from '@/routes/password';

defineProps<{
    status?: string;
}>();

const { t } = useI18n();
const page = usePage();
const turnstileToken = ref('');
const turnstileRef = ref<InstanceType<typeof TurnstileWidget> | null>(null);

const turnstileSiteKey = computed(
    () => page.props.systemSettings?.auth?.turnstileSiteKey ?? '',
);

const turnstileEnabled = computed(
    () =>
        Boolean(page.props.systemSettings?.auth?.turnstileEnabled) &&
        turnstileSiteKey.value !== '',
);

watchEffect(() => {
    setLayoutProps({
        title: t('auth.forgotPassword.layoutTitle'),
        description: t('auth.forgotPassword.layoutDescription'),
    });
});
</script>

<template>
    <div>
        <Head :title="t('auth.forgotPassword.pageTitle')" />

        <div
            v-if="status"
            class="mb-4 rounded-lg border border-success/25 bg-success-soft px-3.5 py-2.5 text-center text-sm font-medium text-success-soft-foreground"
        >
            {{ status }}
        </div>

        <div class="space-y-6">
            <Form
                v-bind="email.form()"
                @finish="turnstileRef?.reset()"
                v-slot="{ errors, processing }"
            >
                <input
                    type="hidden"
                    name="cf_turnstile_response"
                    :value="turnstileToken"
                />

                <div class="grid gap-2">
                    <Label for="email">{{ t('auth.fields.email') }}</Label>
                    <Input
                        id="email"
                        type="email"
                        name="email"
                        autocomplete="off"
                        v-focus
                        :placeholder="t('auth.fields.emailPlaceholder')"
                    />
                    <InputError :message="errors.email" />
                </div>

                <div v-if="turnstileEnabled" class="mt-4 grid gap-1.5">
                    <TurnstileWidget
                        ref="turnstileRef"
                        v-model="turnstileToken"
                        :site-key="turnstileSiteKey"
                        action="forgot_password"
                    />
                    <InputError :message="errors.cf_turnstile_response" />
                </div>

                <div class="my-6 flex items-center justify-start">
                    <Button
                        class="w-full rounded-lg"
                        :disabled="
                            processing || (turnstileEnabled && !turnstileToken)
                        "
                        data-test="email-password-reset-link-button"
                    >
                        <Spinner v-if="processing" />
                        {{ t('auth.forgotPassword.submit') }}
                    </Button>
                </div>
            </Form>

            <div class="space-x-1 text-center text-sm text-muted-foreground">
                <span>{{ t('auth.forgotPassword.orReturnTo') }}</span>
                <TextLink :href="login()" class="font-medium text-primary">
                    {{ t('auth.forgotPassword.loginLink') }}
                </TextLink>
            </div>
        </div>
    </div>
</template>
