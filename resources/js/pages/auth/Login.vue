<script setup lang="ts">
import { Form, Head, setLayoutProps, usePage } from '@inertiajs/vue3';
import { computed, ref, watchEffect } from 'vue';
import { useI18n } from 'vue-i18n';
import TurnstileWidget from '@/components/auth/TurnstileWidget.vue';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { register } from '@/routes';
import { store } from '@/routes/login';
import { request } from '@/routes/password';

const props = defineProps<{
    status?: string;
    canResetPassword: boolean;
    requireTurnstile?: boolean;
}>();

const { t } = useI18n();
const page = usePage();
const turnstileToken = ref('');
const turnstileRef = ref<InstanceType<typeof TurnstileWidget> | null>(null);

const pageErrors = computed(
    () => (page.props.errors ?? {}) as Record<string, string | undefined>,
);

const showGoogleLogin = computed(
    () => page.props.systemSettings?.features?.googleLogin ?? true,
);

const showRegistration = computed(
    () =>
        (page.props.systemSettings?.features?.registration ?? true) &&
        !page.props.systemSettings?.auth?.emergencyRegistrationPause,
);

const turnstileSiteKey = computed(
    () => page.props.systemSettings?.auth?.turnstileSiteKey ?? '',
);

const showTurnstile = computed(
    () =>
        Boolean(
            props.requireTurnstile || pageErrors.value.cf_turnstile_response,
        ) &&
        Boolean(page.props.systemSettings?.auth?.turnstileEnabled) &&
        turnstileSiteKey.value !== '',
);

watchEffect(() => {
    setLayoutProps({
        title: t('auth.login.layoutTitle'),
        description: t('auth.login.layoutDescription'),
    });
});
</script>

<template>
    <div>
        <Head :title="t('auth.login.pageTitle')" />

        <div
            v-if="status"
            class="mb-4 rounded-lg border border-success/25 bg-success-soft px-3.5 py-2.5 text-center text-sm font-medium text-success-soft-foreground"
        >
            {{ status }}
        </div>

        <!-- Google OAuth Button -->
        <template v-if="showGoogleLogin">
            <a
                href="/auth/google/redirect"
                class="mb-5 flex w-full items-center justify-center gap-3 rounded-lg border border-border bg-card px-4 py-2.5 text-sm font-medium text-foreground transition hover:bg-muted"
            >
                <svg class="h-5 w-5" viewBox="0 0 24 24" aria-hidden="true">
                    <path
                        d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"
                        fill="#4285F4"
                    />
                    <path
                        d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"
                        fill="#34A853"
                    />
                    <path
                        d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l3.66-2.84z"
                        fill="#FBBC05"
                    />
                    <path
                        d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"
                        fill="#EA4335"
                    />
                </svg>
                {{ t('auth.login.continueWithGoogle') }}
            </a>

            <div class="relative mb-5 flex items-center gap-3">
                <div class="h-px flex-1 bg-border" />
                <span class="text-xs text-muted-foreground">{{
                    t('auth.login.orDivider')
                }}</span>
                <div class="h-px flex-1 bg-border" />
            </div>
        </template>

        <Form
            v-bind="store.form()"
            :reset-on-success="['password']"
            @error="turnstileRef?.reset()"
            v-slot="{ errors, processing }"
            class="flex flex-col gap-5"
        >
            <input
                type="hidden"
                name="cf_turnstile_response"
                :value="turnstileToken"
            />
            <div class="grid gap-5">
                <div class="grid gap-2">
                    <Label for="email">{{ t('auth.fields.email') }}</Label>
                    <Input
                        id="email"
                        type="email"
                        name="email"
                        required
                        v-focus
                        :tabindex="1"
                        autocomplete="email"
                        :placeholder="t('auth.fields.emailPlaceholder')"
                    />
                    <InputError :message="errors.email || pageErrors.email" />
                </div>

                <div class="grid gap-2">
                    <div class="flex items-center justify-between">
                        <Label for="password">{{
                            t('auth.fields.password')
                        }}</Label>
                        <TextLink
                            v-if="canResetPassword"
                            :href="request()"
                            class="text-sm text-primary"
                            :tabindex="5"
                        >
                            {{ t('auth.login.forgotPassword') }}
                        </TextLink>
                    </div>
                    <PasswordInput
                        id="password"
                        name="password"
                        required
                        :tabindex="2"
                        autocomplete="current-password"
                        :placeholder="t('auth.fields.passwordPlaceholder')"
                    />
                    <InputError :message="errors.password" />
                </div>

                <div class="flex items-center justify-between">
                    <Label for="remember" class="flex items-center space-x-2.5">
                        <Checkbox id="remember" name="remember" :tabindex="3" />
                        <span class="text-sm font-normal text-muted-foreground">
                            {{ t('auth.login.rememberMe') }}
                        </span>
                    </Label>
                </div>

                <div v-if="showTurnstile" class="grid gap-1.5">
                    <TurnstileWidget
                        ref="turnstileRef"
                        v-model="turnstileToken"
                        :site-key="turnstileSiteKey"
                        action="login"
                    />
                    <InputError
                        :message="
                            errors.cf_turnstile_response ||
                            pageErrors.cf_turnstile_response
                        "
                    />
                </div>

                <Button
                    type="submit"
                    class="mt-2 w-full rounded-lg"
                    :tabindex="4"
                    :disabled="processing || (showTurnstile && !turnstileToken)"
                    data-test="login-button"
                >
                    <Spinner v-if="processing" />
                    {{ t('auth.login.submit') }}
                </Button>
            </div>

            <div
                v-if="showRegistration"
                class="text-center text-sm text-muted-foreground"
            >
                {{ t('auth.login.noAccount') }}
                <TextLink
                    :href="register()"
                    class="font-medium text-primary"
                    :tabindex="5"
                >
                    {{ t('auth.login.registerLink') }}
                </TextLink>
            </div>
        </Form>
    </div>
</template>
