<script setup lang="ts">
import { Form, Head, setLayoutProps } from '@inertiajs/vue3';
import { History, Laptop, LogOut, ShieldAlert } from '@lucide/vue';
import { ref, watchEffect } from 'vue';
import { useI18n } from 'vue-i18n';
import SecurityController from '@/actions/App/Http/Controllers/Settings/SecurityController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import type { Props as ManageTwoFactorProps } from '@/components/ManageTwoFactor.vue';
import ManageTwoFactor from '@/components/ManageTwoFactor.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import PasswordRequirementsHint from '@/components/PasswordRequirementsHint.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { formatDateTime } from '@/lib/formatters';
import { edit } from '@/routes/security';
import type { BrowserSessionItem, LoginHistoryItem } from '@/types';

// oxfmt-ignore
type Props = {
    hasPassword?: boolean;
    passwordRules: string;
    sessions?: BrowserSessionItem[];
    loginHistory?: LoginHistoryItem[];
    requiresAdminTwoFactor?: boolean;
} &
    ManageTwoFactorProps;

const props = withDefaults(defineProps<Props>(), {
    hasPassword: true,
    sessions: () => [],
    loginHistory: () => [],
    requiresAdminTwoFactor: false,
});

const { t, locale } = useI18n();
const logoutDialogOpen = ref(false);
const newPassword = ref('');

watchEffect(() => {
    setLayoutProps({
        breadcrumbs: [
            {
                title: t('settings.security.breadcrumb'),
                href: edit(),
            },
        ],
    });
});
</script>

<template>
    <div class="space-y-10">
        <Head :title="t('settings.security.pageTitle')" />

        <h1 class="sr-only">{{ t('settings.security.srHeading') }}</h1>

        <div
            v-if="requiresAdminTwoFactor"
            role="alert"
            class="flex items-start gap-3 rounded-xl border border-warning/30 bg-warning-soft p-4 text-sm text-warning-soft-foreground"
        >
            <ShieldAlert class="mt-0.5 size-5 shrink-0" aria-hidden="true" />
            <div>
                <p class="font-semibold">
                    {{ t('settings.security.adminTwoFactorRequiredTitle') }}
                </p>
                <p class="mt-0.5 text-xs opacity-90">
                    {{ t('settings.security.adminTwoFactorRequiredDesc') }}
                </p>
            </div>
        </div>

        <div class="space-y-6">
            <Heading
                variant="small"
                :title="
                    hasPassword
                        ? t('settings.security.updatePasswordTitle')
                        : t('settings.security.setPasswordTitle')
                "
                :description="
                    hasPassword
                        ? t('settings.security.updatePasswordDesc')
                        : t('settings.security.setPasswordDesc')
                "
            />

            <Form
                v-bind="SecurityController.update.form()"
                :options="{
                    preserveScroll: true,
                }"
                reset-on-success
                :reset-on-error="[
                    'password',
                    'password_confirmation',
                    'current_password',
                ]"
                @success="newPassword = ''"
                @error="newPassword = ''"
                class="space-y-5"
                v-slot="{ errors, processing }"
            >
                <div v-if="hasPassword" class="grid gap-2">
                    <Label for="current_password">{{
                        t('settings.security.currentPassword')
                    }}</Label>
                    <PasswordInput
                        id="current_password"
                        name="current_password"
                        class="mt-1 block w-full"
                        autocomplete="current-password"
                        :placeholder="
                            t('settings.security.currentPasswordPlaceholder')
                        "
                    />
                    <InputError :message="errors.current_password" />
                </div>

                <div class="grid gap-2">
                    <Label for="password">{{
                        t('settings.security.newPassword')
                    }}</Label>
                    <PasswordInput
                        id="password"
                        v-model="newPassword"
                        name="password"
                        class="mt-1 block w-full"
                        autocomplete="new-password"
                        :placeholder="
                            t('settings.security.newPasswordPlaceholder')
                        "
                        :passwordrules="props.passwordRules"
                    />
                    <PasswordRequirementsHint :password="newPassword" />
                    <InputError :message="errors.password" />
                </div>

                <div class="grid gap-2">
                    <Label for="password_confirmation">{{
                        t('settings.security.confirmNewPassword')
                    }}</Label>
                    <PasswordInput
                        id="password_confirmation"
                        name="password_confirmation"
                        class="mt-1 block w-full"
                        autocomplete="new-password"
                        :placeholder="
                            t('settings.security.confirmNewPasswordPlaceholder')
                        "
                        :passwordrules="props.passwordRules"
                    />
                    <InputError :message="errors.password_confirmation" />
                </div>

                <div class="flex items-center gap-4">
                    <Button
                        class="rounded-lg"
                        :disabled="processing"
                        data-test="update-password-button"
                    >
                        {{
                            hasPassword
                                ? t('settings.security.updatePasswordBtn')
                                : t('settings.security.setPasswordBtn')
                        }}
                    </Button>
                </div>
            </Form>
        </div>

        <ManageTwoFactor
            v-if="hasPassword"
            :canManageTwoFactor="canManageTwoFactor"
            :requiresConfirmation="requiresConfirmation"
            :twoFactorEnabled="twoFactorEnabled"
        />
        <div
            v-else-if="canManageTwoFactor"
            class="space-y-3 rounded-xl border border-border bg-muted/40 p-4"
        >
            <Heading
                variant="small"
                :title="t('settings.twoFactor.headingTitle')"
                :description="t('settings.twoFactor.headingDescription')"
            />
            <p class="text-sm text-muted-foreground">
                {{ t('settings.security.setPasswordFirstFor2fa') }}
            </p>
        </div>

        <!-- Active Browser Sessions & Logout All Devices -->
        <div class="space-y-4">
            <Heading
                variant="small"
                :title="t('settings.security.sessionsTitle')"
                :description="t('settings.security.sessionsDesc')"
            />

            <div
                v-if="sessions.length > 0"
                class="divide-y divide-border rounded-xl border border-border"
            >
                <div
                    v-for="(session, idx) in sessions"
                    :key="idx"
                    class="flex items-center justify-between gap-3 px-4 py-3 text-xs"
                >
                    <div class="flex min-w-0 items-center gap-3">
                        <Laptop
                            class="size-4 shrink-0 text-muted-foreground"
                            aria-hidden="true"
                        />
                        <div class="min-w-0">
                            <p class="truncate font-medium text-foreground">
                                {{
                                    session.user_agent ||
                                    t('settings.security.unknownBrowser')
                                }}
                            </p>
                            <p class="text-muted-foreground">
                                {{
                                    t('settings.security.ipLabel', {
                                        ip: session.ip_address || '-',
                                    })
                                }}
                                ·
                                {{
                                    formatDateTime(
                                        session.last_active_at,
                                        locale,
                                    )
                                }}
                            </p>
                        </div>
                    </div>
                    <Badge
                        v-if="session.is_current_device"
                        variant="secondary"
                        class="shrink-0 text-[10px]"
                    >
                        {{ t('settings.security.currentDevice') }}
                    </Badge>
                </div>
            </div>

            <Dialog v-model:open="logoutDialogOpen">
                <DialogTrigger as-child>
                    <Button
                        variant="outline"
                        class="rounded-lg"
                        data-test="logout-other-sessions-button"
                    >
                        <LogOut class="size-4" />
                        <span>{{
                            t('settings.security.logoutOtherSessionsBtn')
                        }}</span>
                    </Button>
                </DialogTrigger>
                <DialogContent>
                    <Form
                        v-bind="SecurityController.logoutOtherSessions.form()"
                        reset-on-success
                        @success="logoutDialogOpen = false"
                        class="space-y-5"
                        v-slot="{ errors, processing }"
                    >
                        <DialogHeader>
                            <DialogTitle>
                                {{
                                    t(
                                        'settings.security.logoutOtherSessionsTitle',
                                    )
                                }}
                            </DialogTitle>
                            <DialogDescription>
                                {{
                                    t(
                                        'settings.security.logoutOtherSessionsDesc',
                                    )
                                }}
                            </DialogDescription>
                        </DialogHeader>

                        <div class="grid gap-2">
                            <Label for="logout_sessions_password">{{
                                t('settings.security.currentPassword')
                            }}</Label>
                            <PasswordInput
                                id="logout_sessions_password"
                                name="password"
                                required
                                autocomplete="current-password"
                                :placeholder="
                                    t('settings.security.passwordPlaceholder')
                                "
                            />
                            <InputError :message="errors.password" />
                        </div>

                        <DialogFooter class="gap-2">
                            <DialogClose as-child>
                                <Button
                                    type="button"
                                    variant="secondary"
                                    class="rounded-lg"
                                >
                                    {{ t('common.cancel') }}
                                </Button>
                            </DialogClose>
                            <Button
                                type="submit"
                                class="rounded-lg"
                                :disabled="processing"
                                data-test="confirm-logout-other-sessions-button"
                            >
                                {{ t('settings.security.confirmLogoutBtn') }}
                            </Button>
                        </DialogFooter>
                    </Form>
                </DialogContent>
            </Dialog>
        </div>

        <!-- Recent Login History -->
        <div class="space-y-4">
            <div class="flex items-center gap-2">
                <History class="size-4 text-primary" aria-hidden="true" />
                <Heading
                    variant="small"
                    :title="t('settings.security.loginHistoryTitle')"
                    :description="t('settings.security.loginHistoryDesc')"
                />
            </div>

            <div
                v-if="loginHistory.length > 0"
                class="divide-y divide-border rounded-xl border border-border text-xs"
            >
                <div
                    v-for="entry in loginHistory"
                    :key="entry.id"
                    class="flex flex-wrap items-center justify-between gap-2 px-4 py-2.5"
                >
                    <div class="flex items-center gap-2">
                        <Badge
                            :variant="
                                entry.event_type === 'failed'
                                    ? 'destructive'
                                    : 'secondary'
                            "
                            class="text-[10px]"
                        >
                            {{ entry.event_type }}
                        </Badge>
                        <span class="text-muted-foreground">
                            {{
                                t('settings.security.ipLabel', {
                                    ip: entry.ip_address || '-',
                                })
                            }}
                        </span>
                    </div>
                    <span class="text-muted-foreground">
                        {{ formatDateTime(entry.created_at, locale) }}
                    </span>
                </div>
            </div>
            <p v-else class="text-xs text-muted-foreground">
                {{ t('settings.security.emptyLoginHistory') }}
            </p>
        </div>
    </div>
</template>
