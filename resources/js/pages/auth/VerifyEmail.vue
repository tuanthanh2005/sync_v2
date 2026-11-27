<script setup lang="ts">
import {
    Head,
    router,
    setLayoutProps,
    useForm,
    usePage,
} from '@inertiajs/vue3';
import {
    AlertCircle,
    ArrowLeft,
    CheckCircle2,
    Mail,
    RefreshCw,
    ShieldCheck,
} from '@lucide/vue';
import {
    computed,
    onBeforeUnmount,
    onMounted,
    ref,
    watch,
    watchEffect,
} from 'vue';
import { useI18n } from 'vue-i18n';
import TurnstileWidget from '@/components/auth/TurnstileWidget.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { login, logout } from '@/routes';

const props = withDefaults(
    defineProps<{
        status?: string;
        maskedEmail?: string;
        isPendingRegistration?: boolean;
        cooldownSeconds?: number;
        expireMinutes?: number;
        requireTurnstileOnResend?: boolean;
        isAuthenticatedUnverified?: boolean;
    }>(),
    {
        maskedEmail: '',
        isPendingRegistration: false,
        cooldownSeconds: 0,
        expireMinutes: 5,
        requireTurnstileOnResend: false,
        isAuthenticatedUnverified: false,
    },
);

const { t } = useI18n();
const page = usePage();

const countdown = ref(Math.max(0, props.cooldownSeconds));
const showChangeEmail = ref(false);
const resendTurnstileToken = ref('');
const turnstileRef = ref<InstanceType<typeof TurnstileWidget> | null>(null);
let timerId: ReturnType<typeof setInterval> | null = null;

const turnstileSiteKey = computed(
    () => page.props.systemSettings?.auth?.turnstileSiteKey ?? '',
);

const showResendTurnstile = computed(
    () =>
        props.requireTurnstileOnResend &&
        Boolean(page.props.systemSettings?.auth?.turnstileEnabled) &&
        turnstileSiteKey.value !== '',
);

const otpForm = useForm({
    code: '',
});

const resendForm = useForm({
    cf_turnstile_response: '',
});

const changeEmailForm = useForm({
    email: '',
});

function startCountdown(seconds: number): void {
    countdown.value = Math.max(0, seconds);
    if (timerId !== null) {
        clearInterval(timerId);
        timerId = null;
    }
    if (countdown.value <= 0) {
        return;
    }
    timerId = setInterval(() => {
        if (countdown.value > 0) {
            countdown.value -= 1;
        }
        if (countdown.value <= 0 && timerId !== null) {
            clearInterval(timerId);
            timerId = null;
        }
    }, 1000);
}

watch(
    () => props.cooldownSeconds,
    (next) => {
        if (next > 0) {
            startCountdown(next);
        }
    },
);

onMounted(() => {
    if (countdown.value > 0) {
        startCountdown(countdown.value);
    }
});

onBeforeUnmount(() => {
    if (timerId !== null) {
        clearInterval(timerId);
    }
});

function handleOtpInput(event: Event): void {
    const target = event.target as HTMLInputElement;
    otpForm.code = target.value.replace(/\D/g, '').slice(0, 6);
}

function handleOtpPaste(event: ClipboardEvent): void {
    const pasted = event.clipboardData?.getData('text') ?? '';
    const digits = pasted.replace(/\D/g, '').slice(0, 6);
    if (digits.length > 0) {
        event.preventDefault();
        otpForm.code = digits;
    }
}

function submitOtp(): void {
    otpForm.post('/email/verify/otp', {
        preserveScroll: true,
    });
}

function submitResend(): void {
    if (countdown.value > 0 || resendForm.processing) {
        return;
    }

    resendForm.cf_turnstile_response = resendTurnstileToken.value;
    resendForm.post('/email/verify/resend', {
        preserveScroll: true,
        onSuccess: () => {
            const defaultCooldown =
                page.props.systemSettings?.auth?.otpResendCooldown ?? 60;
            startCountdown(defaultCooldown);
            turnstileRef.value?.reset();
        },
        onError: () => {
            turnstileRef.value?.reset();
        },
    });
}

function submitChangeEmail(): void {
    changeEmailForm.post('/email/verify/change-email', {
        preserveScroll: true,
        onSuccess: () => {
            showChangeEmail.value = false;
            changeEmailForm.reset();
            const defaultCooldown =
                page.props.systemSettings?.auth?.otpResendCooldown ?? 60;
            startCountdown(defaultCooldown);
        },
    });
}

function cancelOrLogout(): void {
    if (props.isPendingRegistration) {
        router.post('/email/verify/cancel');
        return;
    }

    if (props.isAuthenticatedUnverified) {
        router.post(logout.url());
        return;
    }

    router.visit(login.url());
}

watchEffect(() => {
    setLayoutProps({
        title: t('auth.verifyEmail.layoutTitle'),
        description: t('auth.verifyEmail.layoutDescription'),
    });
});
</script>

<template>
    <div class="space-y-5">
        <Head :title="t('auth.verifyEmail.pageTitle')" />

        <div
            v-if="status === 'verification-link-sent'"
            class="flex items-start gap-2.5 rounded-lg border border-success/25 bg-success-soft px-3.5 py-2.5 text-start text-sm font-medium text-success-soft-foreground"
            data-test="verification-sent-banner"
        >
            <CheckCircle2 class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
            <span>{{ t('auth.verifyEmail.linkSent') }}</span>
        </div>

        <div
            v-else-if="status"
            class="flex items-start gap-2.5 rounded-lg border border-primary/25 bg-primary-soft/60 px-3.5 py-2.5 text-start text-sm font-medium text-foreground"
        >
            <ShieldCheck
                class="mt-0.5 size-4 shrink-0 text-primary"
                aria-hidden="true"
            />
            <span>{{ status }}</span>
        </div>

        <div
            v-if="maskedEmail"
            class="flex items-center gap-3 rounded-xl border border-border bg-muted/40 px-3.5 py-3 text-start"
        >
            <div
                class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-primary-soft text-primary"
            >
                <Mail class="size-4" aria-hidden="true" />
            </div>
            <div class="min-w-0 flex-1">
                <p class="text-xs text-muted-foreground">
                    {{ t('auth.verifyEmail.sentToLabel') }}
                </p>
                <p
                    class="truncate font-mono text-sm font-semibold text-foreground"
                    data-test="verify-masked-email"
                >
                    {{ maskedEmail }}
                </p>
            </div>
        </div>

        <!-- 6-Digit OTP Verification Form -->
        <form class="space-y-4" @submit.prevent="submitOtp">
            <div class="grid gap-2 text-start">
                <Label for="otp-code">{{
                    t('auth.verifyEmail.otpLabel')
                }}</Label>
                <Input
                    id="otp-code"
                    :model-value="otpForm.code"
                    type="text"
                    inputmode="numeric"
                    autocomplete="one-time-code"
                    maxlength="6"
                    required
                    v-focus
                    :placeholder="t('auth.verifyEmail.otpPlaceholder')"
                    class="h-12 text-center font-mono text-xl font-bold tracking-[0.45em]"
                    data-test="verify-otp-input"
                    @input="handleOtpInput"
                    @paste="handleOtpPaste"
                />
                <p class="text-xs text-muted-foreground">
                    {{
                        t('auth.verifyEmail.otpExpiryHint', {
                            minutes: expireMinutes,
                        })
                    }}
                </p>
                <InputError :message="otpForm.errors.code" />
            </div>

            <Button
                type="submit"
                class="w-full rounded-lg"
                :disabled="otpForm.processing || otpForm.code.length < 6"
                data-test="verify-otp-submit"
            >
                <Spinner v-if="otpForm.processing" />
                <span>{{ t('auth.verifyEmail.verifyButton') }}</span>
            </Button>
        </form>

        <!-- Resend Code Section with Countdown -->
        <div class="space-y-3 border-t border-border pt-4">
            <TurnstileWidget
                v-if="showResendTurnstile"
                ref="turnstileRef"
                v-model="resendTurnstileToken"
                :site-key="turnstileSiteKey"
                action="resend_otp"
            />
            <InputError :message="resendForm.errors.cf_turnstile_response" />

            <Button
                type="button"
                variant="secondary"
                class="w-full gap-2 rounded-lg"
                :disabled="resendForm.processing || countdown > 0"
                data-test="verify-resend-button"
                @click="submitResend"
            >
                <Spinner v-if="resendForm.processing" />
                <RefreshCw v-else class="size-4" aria-hidden="true" />
                <span v-if="countdown > 0">
                    {{
                        t('auth.verifyEmail.resendCooldown', {
                            seconds: countdown,
                        })
                    }}
                </span>
                <span v-else>{{ t('auth.verifyEmail.resendButton') }}</span>
            </Button>
        </div>

        <!-- Spam / Updates Folder Hint -->
        <div
            class="flex items-start gap-2 rounded-lg border border-border/70 bg-muted/25 px-3 py-2.5 text-start text-xs text-muted-foreground"
        >
            <AlertCircle
                class="mt-0.5 size-3.5 shrink-0 text-muted-foreground"
                aria-hidden="true"
            />
            <span>{{ t('auth.verifyEmail.spamFolderHint') }}</span>
        </div>

        <!-- Change Registration Email (Pending Registration Only) -->
        <div
            v-if="isPendingRegistration"
            class="space-y-3 border-t border-border pt-3 text-start"
        >
            <button
                type="button"
                class="text-xs font-medium text-primary hover:underline"
                data-test="toggle-change-email"
                @click="showChangeEmail = !showChangeEmail"
            >
                {{ t('auth.verifyEmail.changeEmailToggle') }}
            </button>

            <form
                v-if="showChangeEmail"
                class="space-y-3 rounded-lg border border-border bg-muted/30 p-3"
                @submit.prevent="submitChangeEmail"
            >
                <div class="grid gap-1.5">
                    <Label for="new-pending-email" class="text-xs">
                        {{ t('auth.verifyEmail.newEmailLabel') }}
                    </Label>
                    <Input
                        id="new-pending-email"
                        v-model="changeEmailForm.email"
                        type="email"
                        required
                        autocomplete="email"
                        :placeholder="t('auth.fields.emailPlaceholder')"
                        data-test="change-pending-email-input"
                    />
                    <InputError :message="changeEmailForm.errors.email" />
                </div>
                <Button
                    type="submit"
                    size="sm"
                    class="w-full rounded-lg"
                    :disabled="changeEmailForm.processing"
                    data-test="change-pending-email-submit"
                >
                    <Spinner v-if="changeEmailForm.processing" />
                    <span>{{ t('auth.verifyEmail.updateEmailSubmit') }}</span>
                </Button>
            </form>
        </div>

        <!-- Cancel / Logout / Back to Login -->
        <div class="flex items-center justify-between gap-2 pt-1 text-xs">
            <button
                type="button"
                class="inline-flex items-center gap-1.5 font-medium text-muted-foreground hover:text-foreground"
                data-test="verify-cancel-button"
                @click="cancelOrLogout"
            >
                <ArrowLeft class="size-3.5" aria-hidden="true" />
                <span>
                    {{
                        isPendingRegistration
                            ? t('auth.verifyEmail.cancelRegistration')
                            : t('auth.verifyEmail.logout')
                    }}
                </span>
            </button>

            <button
                type="button"
                class="font-medium text-primary hover:underline"
                @click="router.visit(login.url())"
            >
                {{ t('auth.verifyEmail.backToLogin') }}
            </button>
        </div>
    </div>
</template>
