<script setup lang="ts">
import {
    Form,
    Head,
    Link,
    setLayoutProps,
    useForm,
    usePage,
} from '@inertiajs/vue3';
import { computed, watchEffect } from 'vue';
import { useI18n } from 'vue-i18n';
import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
import DeleteUser from '@/components/DeleteUser.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { edit } from '@/routes/profile';
import {
    changeRequest as emailChangeRequestRoute,
    verifyOtp as emailChangeVerifyRoute,
} from '@/routes/settings/email';
import { send } from '@/routes/verification';

const props = withDefaults(
    defineProps<{
        pendingEmailChange?: string | null;
        hasPassword?: boolean;
    }>(),
    {
        pendingEmailChange: null,
        hasPassword: true,
    },
);

const { t } = useI18n();
const page = usePage();
const user = computed(() => page.props.auth.user);

const emailChangeForm = useForm({
    new_email: '',
    current_password: '',
});

const emailVerifyForm = useForm({
    new_email: props.pendingEmailChange ?? '',
    code: '',
});

function submitEmailChangeRequest(): void {
    emailChangeForm.post(emailChangeRequestRoute.url(), {
        preserveScroll: true,
        onSuccess: () => {
            emailVerifyForm.new_email = emailChangeForm.new_email;
            emailChangeForm.reset('current_password');
        },
    });
}

function submitEmailChangeVerify(): void {
    emailVerifyForm.new_email =
        props.pendingEmailChange || emailChangeForm.new_email;
    emailVerifyForm.post(emailChangeVerifyRoute.url(), {
        preserveScroll: true,
        onSuccess: () => {
            emailChangeForm.reset();
            emailVerifyForm.reset();
        },
    });
}

watchEffect(() => {
    setLayoutProps({
        breadcrumbs: [
            {
                title: t('settings.profile.breadcrumb'),
                href: edit(),
            },
        ],
    });
});

const timezoneOptions = [
    { value: 'Asia/Ho_Chi_Minh', label: 'Asia/Ho_Chi_Minh (GMT+7)' },
    { value: 'UTC', label: 'UTC (GMT+0)' },
    { value: 'Asia/Singapore', label: 'Asia/Singapore (GMT+8)' },
    { value: 'Asia/Tokyo', label: 'Asia/Tokyo (GMT+9)' },
];
</script>

<template>
    <div class="space-y-10">
        <Head :title="t('settings.profile.pageTitle')" />

        <h1 class="sr-only">{{ t('settings.profile.srHeading') }}</h1>

        <div v-if="user" class="flex flex-col space-y-6">
            <Heading
                variant="small"
                :title="t('settings.profile.headingTitle')"
                :description="t('settings.profile.headingDescription')"
            />

            <Form
                v-bind="ProfileController.update.form()"
                class="space-y-5"
                v-slot="{ errors, processing }"
            >
                <div class="grid gap-2">
                    <Label for="name">{{
                        t('settings.profile.nameLabel')
                    }}</Label>
                    <Input
                        id="name"
                        class="mt-1 block w-full"
                        name="name"
                        :default-value="user.name"
                        required
                        autocomplete="name"
                        :placeholder="t('settings.profile.namePlaceholder')"
                    />
                    <InputError class="mt-1" :message="errors.name" />
                </div>

                <div class="grid gap-2">
                    <Label for="username">{{
                        t('settings.profile.usernameLabel')
                    }}</Label>
                    <Input
                        id="username"
                        class="mt-1 block w-full"
                        name="username"
                        :default-value="user.username"
                        required
                        autocomplete="username"
                        :placeholder="t('settings.profile.usernamePlaceholder')"
                    />
                    <InputError class="mt-1" :message="errors.username" />
                </div>

                <div class="grid gap-2">
                    <Label for="email">{{
                        t('settings.profile.emailLabel')
                    }}</Label>
                    <Input
                        id="email"
                        type="email"
                        class="mt-1 block w-full bg-muted/50 text-muted-foreground"
                        :model-value="user.email"
                        disabled
                        readonly
                        autocomplete="email"
                    />
                    <p class="text-xs text-muted-foreground">
                        {{ t('settings.profile.emailReadOnlyHint') }}
                    </p>
                    <InputError class="mt-1" :message="errors.email" />
                </div>

                <div class="grid gap-2">
                    <Label for="timezone">{{
                        t('settings.profile.timezoneLabel')
                    }}</Label>
                    <select
                        id="timezone"
                        name="timezone"
                        :value="user.timezone"
                        class="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-xs transition-[color,box-shadow] outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 dark:bg-card"
                    >
                        <option
                            v-for="tz in timezoneOptions"
                            :key="tz.value"
                            :value="tz.value"
                        >
                            {{ tz.label }}
                        </option>
                    </select>
                    <InputError class="mt-1" :message="errors.timezone" />
                </div>

                <div
                    v-if="page.props.mustVerifyEmail && !user.email_verified_at"
                >
                    <p class="-mt-2 text-sm text-muted-foreground">
                        {{ t('settings.profile.emailUnverified') }}
                        <Link
                            :href="send()"
                            as="button"
                            class="font-medium text-primary underline underline-offset-4"
                        >
                            {{ t('settings.profile.resendVerification') }}
                        </Link>
                    </p>

                    <div
                        v-if="page.props.status === 'verification-link-sent'"
                        class="mt-2 text-sm font-medium text-success"
                    >
                        {{ t('settings.profile.verificationLinkSent') }}
                    </div>
                </div>

                <div class="flex items-center gap-4">
                    <Button
                        class="rounded-lg"
                        :disabled="processing"
                        data-test="update-profile-button"
                    >
                        {{ t('settings.profile.saveChanges') }}
                    </Button>
                </div>
            </Form>
        </div>

        <!-- Secure Verified Email Change (keeps current email intact until new email OTP verified) -->
        <div
            v-if="user"
            class="flex flex-col space-y-5 border-t border-border pt-8"
        >
            <Heading
                variant="small"
                :title="t('settings.profile.secureEmailChangeTitle')"
                :description="t('settings.profile.secureEmailChangeDesc')"
            />

            <form class="space-y-4" @submit.prevent="submitEmailChangeRequest">
                <div class="grid gap-2">
                    <Label for="new_email">{{
                        t('settings.profile.newEmailLabel')
                    }}</Label>
                    <Input
                        id="new_email"
                        v-model="emailChangeForm.new_email"
                        type="email"
                        required
                        autocomplete="email"
                        :placeholder="t('settings.profile.emailPlaceholder')"
                    />
                    <InputError :message="emailChangeForm.errors.new_email" />
                </div>

                <div v-if="hasPassword" class="grid gap-2">
                    <Label for="email_change_password">{{
                        t('settings.security.currentPassword')
                    }}</Label>
                    <PasswordInput
                        id="email_change_password"
                        v-model="emailChangeForm.current_password"
                        required
                        autocomplete="current-password"
                        :placeholder="
                            t('settings.security.currentPasswordPlaceholder')
                        "
                    />
                    <InputError
                        :message="emailChangeForm.errors.current_password"
                    />
                </div>

                <Button
                    type="submit"
                    variant="outline"
                    class="rounded-lg"
                    :disabled="emailChangeForm.processing"
                    data-test="request-email-change-button"
                >
                    <Spinner v-if="emailChangeForm.processing" />
                    <span>{{ t('settings.profile.sendEmailChangeCode') }}</span>
                </Button>
            </form>

            <form
                v-if="pendingEmailChange"
                class="space-y-4 rounded-xl border border-primary/25 bg-primary-soft/30 p-4"
                @submit.prevent="submitEmailChangeVerify"
            >
                <p class="text-sm font-medium text-foreground">
                    {{
                        t('settings.profile.pendingEmailVerifyNotice', {
                            email: pendingEmailChange,
                        })
                    }}
                </p>
                <div class="grid gap-2">
                    <Label for="email_change_otp">{{
                        t('auth.verifyEmail.otpLabel')
                    }}</Label>
                    <Input
                        id="email_change_otp"
                        v-model="emailVerifyForm.code"
                        type="text"
                        inputmode="numeric"
                        maxlength="6"
                        required
                        :placeholder="t('auth.verifyEmail.otpPlaceholder')"
                        class="max-w-[220px] font-mono text-base tracking-[0.35em]"
                    />
                    <InputError :message="emailVerifyForm.errors.code" />
                </div>
                <Button
                    type="submit"
                    class="rounded-lg"
                    :disabled="emailVerifyForm.processing"
                    data-test="verify-email-change-button"
                >
                    <Spinner v-if="emailVerifyForm.processing" />
                    <span>{{ t('settings.profile.confirmNewEmailBtn') }}</span>
                </Button>
            </form>
        </div>

        <DeleteUser />
    </div>
</template>
