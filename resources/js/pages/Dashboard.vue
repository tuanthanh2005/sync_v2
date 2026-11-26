<script setup lang="ts">
import { Head, Link, setLayoutProps, usePage } from '@inertiajs/vue3';
import {
    AlertCircle,
    ArrowRight,
    CheckCircle2,
    Clock,
    Newspaper,
    Palette,
    Shield,
    ShieldCheck,
    UserCheck,
    UserCog,
} from '@lucide/vue';
import { computed, watchEffect } from 'vue';
import { useI18n } from 'vue-i18n';
import RoleBadge from '@/components/common/RoleBadge.vue';
import { Button } from '@/components/ui/button';
import { dashboard, home } from '@/routes';
import { overview as adminOverview } from '@/routes/admin';
import { edit as editAppearance } from '@/routes/appearance';
import { edit as editProfile } from '@/routes/profile';
import { edit as editSecurity } from '@/routes/security';
import type { AccountChecklist } from '@/types';

const props = defineProps<{
    accountChecklist: AccountChecklist;
}>();

const { t } = useI18n();
const page = usePage();
const user = computed(() => page.props.auth.user);

watchEffect(() => {
    setLayoutProps({
        breadcrumbs: [
            {
                title: t('dashboard.breadcrumb'),
                href: dashboard(),
            },
        ],
    });
});

const checklistItems = computed(() => [
    {
        key: 'email',
        label: t('dashboard.checklist.emailVerified'),
        completed: props.accountChecklist.emailVerified,
        href: editProfile(),
        actionLabel: t('dashboard.checklist.checkEmail'),
    },
    {
        key: '2fa',
        label: t('dashboard.checklist.twoFactor'),
        completed: props.accountChecklist.twoFactorEnabled,
        href: editSecurity(),
        actionLabel: t('dashboard.checklist.configure2fa'),
    },
    {
        key: 'username',
        label: t('dashboard.checklist.username'),
        completed: props.accountChecklist.usernameConfigured,
        href: editProfile(),
        actionLabel: t('dashboard.checklist.updateProfile'),
    },
    {
        key: 'timezone',
        label: t('dashboard.checklist.timezone'),
        completed: props.accountChecklist.timezoneConfigured,
        href: editProfile(),
        actionLabel: t('dashboard.checklist.customizeTimezone'),
    },
]);
</script>

<template>
    <div>
        <Head :title="t('dashboard.pageTitle')" />

        <div v-if="user" class="space-y-5">
            <!-- Member Identity Card -->
            <section
                class="rounded-xl border border-border bg-card p-6 shadow-xs"
            >
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <div class="flex flex-wrap items-center gap-2.5">
                            <h1 class="text-xl font-bold text-foreground">
                                {{
                                    t('dashboard.greeting', { name: user.name })
                                }}
                            </h1>
                            <RoleBadge
                                :role="user.role"
                                :label="user.role_label"
                            />
                        </div>
                        <p class="mt-1 text-sm text-muted-foreground">
                            @{{ user.username }} · {{ user.email }}
                        </p>
                        <div
                            class="mt-3 flex flex-wrap items-center gap-4 text-xs text-muted-foreground"
                        >
                            <span class="inline-flex items-center gap-1.5">
                                <UserCheck class="size-3.5 text-success" />
                                {{
                                    t('dashboard.statusLabel', {
                                        status: user.status_label,
                                    })
                                }}
                            </span>
                            <span class="inline-flex items-center gap-1.5">
                                <Clock class="size-3.5 text-primary" />
                                {{
                                    t('dashboard.timezoneLabel', {
                                        timezone: user.timezone,
                                    })
                                }}
                            </span>
                        </div>
                    </div>

                    <div class="flex flex-wrap gap-2">
                        <Button size="sm" class="rounded-lg" as-child>
                            <Link :href="home()">
                                <Newspaper class="size-4" />
                                <span>{{ t('dashboard.feedButton') }}</span>
                                <ArrowRight class="size-3.5" />
                            </Link>
                        </Button>
                        <Button
                            variant="outline"
                            size="sm"
                            class="rounded-lg"
                            as-child
                        >
                            <Link :href="editProfile()">
                                <UserCog class="size-4" />
                                <span>{{
                                    t('dashboard.editProfileButton')
                                }}</span>
                            </Link>
                        </Button>
                        <Button
                            v-if="user.can?.accessAdmin"
                            variant="outline"
                            size="sm"
                            class="rounded-lg"
                            as-child
                        >
                            <Link :href="adminOverview()">
                                <Shield class="size-4" />
                                <span>{{ t('dashboard.adminButton') }}</span>
                            </Link>
                        </Button>
                    </div>
                </div>
            </section>

            <!-- Account Readiness Checklist -->
            <section class="rounded-xl border border-border bg-card p-6">
                <div class="flex items-center justify-between gap-3">
                    <div>
                        <h2 class="text-base font-semibold text-foreground">
                            {{ t('dashboard.checklistTitle') }}
                        </h2>
                        <p class="text-xs text-muted-foreground">
                            {{ t('dashboard.checklistSubtitle') }}
                        </p>
                    </div>
                    <ShieldCheck
                        class="size-5 text-primary"
                        aria-hidden="true"
                    />
                </div>

                <div
                    class="mt-4 divide-y divide-border rounded-lg border border-border"
                >
                    <div
                        v-for="item in checklistItems"
                        :key="item.key"
                        class="flex flex-wrap items-center justify-between gap-3 px-4 py-3"
                    >
                        <div class="flex items-center gap-3">
                            <CheckCircle2
                                v-if="item.completed"
                                class="size-4.5 shrink-0 text-success"
                                aria-hidden="true"
                            />
                            <AlertCircle
                                v-else
                                class="size-4.5 shrink-0 text-warning"
                                aria-hidden="true"
                            />
                            <span class="text-sm font-medium text-foreground">
                                {{ item.label }}
                            </span>
                        </div>
                        <Button
                            variant="ghost"
                            size="sm"
                            class="h-8 rounded-lg text-xs text-primary"
                            as-child
                        >
                            <Link :href="item.href">{{
                                item.actionLabel
                            }}</Link>
                        </Button>
                    </div>
                </div>
            </section>

            <!-- Quick Actions -->
            <section class="grid gap-4 sm:grid-cols-2">
                <Link
                    :href="editSecurity()"
                    class="group rounded-xl border border-border bg-card p-5 transition-colors hover:border-primary/40"
                >
                    <div class="flex items-center gap-3">
                        <div
                            class="flex size-10 items-center justify-center rounded-lg bg-primary-soft text-primary"
                        >
                            <ShieldCheck class="size-5" aria-hidden="true" />
                        </div>
                        <div>
                            <h3
                                class="text-sm font-semibold text-foreground group-hover:text-primary"
                            >
                                {{ t('dashboard.securityCardTitle') }}
                            </h3>
                            <p class="text-xs text-muted-foreground">
                                {{
                                    user.two_factor_enabled
                                        ? t('dashboard.securityCardEnabled')
                                        : t('dashboard.securityCardDisabled')
                                }}
                            </p>
                        </div>
                    </div>
                </Link>

                <Link
                    :href="editAppearance()"
                    class="group rounded-xl border border-border bg-card p-5 transition-colors hover:border-primary/40"
                >
                    <div class="flex items-center gap-3">
                        <div
                            class="flex size-10 items-center justify-center rounded-lg bg-primary-soft text-primary"
                        >
                            <Palette class="size-5" aria-hidden="true" />
                        </div>
                        <div>
                            <h3
                                class="text-sm font-semibold text-foreground group-hover:text-primary"
                            >
                                {{ t('dashboard.appearanceCardTitle') }}
                            </h3>
                            <p class="text-xs text-muted-foreground">
                                {{ t('dashboard.appearanceCardDesc') }}
                            </p>
                        </div>
                    </div>
                </Link>
            </section>
        </div>
    </div>
</template>
