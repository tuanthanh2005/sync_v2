<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
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
import { computed } from 'vue';
import RoleBadge from '@/components/common/RoleBadge.vue';
import { Button } from '@/components/ui/button';
import { dashboard, home } from '@/routes';
import { overview as adminOverview } from '@/routes/admin';
import { edit as editAppearance } from '@/routes/appearance';
import { edit as editProfile } from '@/routes/profile';
import { edit as editSecurity } from '@/routes/security';
import type { AccountChecklist } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Không gian cá nhân',
                href: dashboard(),
            },
        ],
    },
});

const props = defineProps<{
    accountChecklist: AccountChecklist;
}>();

const page = usePage();
const user = computed(() => page.props.auth.user);

const checklistItems = computed(() => [
    {
        label: 'Xác minh địa chỉ email',
        completed: props.accountChecklist.emailVerified,
        href: editProfile(),
        actionLabel: 'Kiểm tra email',
    },
    {
        label: 'Bảo mật 2 lớp (2FA)',
        completed: props.accountChecklist.twoFactorEnabled,
        href: editSecurity(),
        actionLabel: 'Cấu hình 2FA',
    },
    {
        label: 'Định danh @username duy nhất',
        completed: props.accountChecklist.usernameConfigured,
        href: editProfile(),
        actionLabel: 'Cập nhật hồ sơ',
    },
    {
        label: 'Múi giờ hiển thị cá nhân',
        completed: props.accountChecklist.timezoneConfigured,
        href: editProfile(),
        actionLabel: 'Tùy chỉnh múi giờ',
    },
]);
</script>

<template>
    <div>
        <Head title="Không gian cá nhân" />

        <div v-if="user" class="space-y-5">
            <!-- Member Identity Card -->
            <section
                class="rounded-xl border border-border bg-card p-6 shadow-xs"
            >
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <div class="flex flex-wrap items-center gap-2.5">
                            <h1 class="text-xl font-bold text-foreground">
                                Xin chào, {{ user.name }}
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
                                Trạng thái: {{ user.status_label }}
                            </span>
                            <span class="inline-flex items-center gap-1.5">
                                <Clock class="size-3.5 text-primary" />
                                Múi giờ: {{ user.timezone }}
                            </span>
                        </div>
                    </div>

                    <div class="flex flex-wrap gap-2">
                        <Button size="sm" class="rounded-lg" as-child>
                            <Link :href="home()">
                                <Newspaper class="size-4" />
                                <span>Bảng tin</span>
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
                                <span>Chỉnh sửa hồ sơ</span>
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
                                <span>Quản trị hệ thống</span>
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
                            Hoàn thiện tài khoản của bạn
                        </h2>
                        <p class="text-xs text-muted-foreground">
                            Kiểm tra trạng thái xác minh và cài đặt bảo mật cá
                            nhân
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
                        :key="item.label"
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
                                Mật khẩu & Xác thực 2 lớp
                            </h3>
                            <p class="text-xs text-muted-foreground">
                                {{
                                    user.two_factor_enabled
                                        ? '2FA đã được kích hoạt'
                                        : 'Nên bật 2FA để tăng an toàn'
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
                                Giao diện Sáng / Tối
                            </h3>
                            <p class="text-xs text-muted-foreground">
                                Tùy chỉnh Light Mode, Dark Mode hoặc theo hệ
                                thống
                            </p>
                        </div>
                    </div>
                </Link>
            </section>
        </div>
    </div>
</template>
