<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import {
    Activity,
    FileText,
    MailCheck,
    Server,
    Shield,
    ShieldAlert,
    UserCheck,
    Users,
} from '@lucide/vue';
import { computed } from 'vue';
import EmptyState from '@/components/common/EmptyState.vue';
import RoleBadge from '@/components/common/RoleBadge.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { overview as adminOverview } from '@/routes/admin';
import { index as adminAds } from '@/routes/admin/ads';
import { index as adminPolicies } from '@/routes/admin/policies';
import type {
    AdminAuditRecord,
    AdminMetrics,
    AdminPlatformStatus,
    AdminUserRecord,
} from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Quản trị hệ thống',
                href: adminOverview(),
            },
        ],
    },
});

defineProps<{
    metrics: AdminMetrics;
    platform_status: AdminPlatformStatus;
    recent_users: AdminUserRecord[];
    recent_audits: AdminAuditRecord[];
}>();

const page = usePage();
const user = computed(() => page.props.auth.user);
</script>

<template>
    <div class="space-y-5">
        <Head title="Quản trị hệ thống" />

        <section class="rounded-xl border border-border bg-card p-6 shadow-xs">
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <div
                        class="flex items-center gap-2 text-xs font-semibold text-primary"
                    >
                        <Shield class="size-4" aria-hidden="true" />
                        <span
                            >Kiểm soát truy cập Policy, Middleware & Mandatory
                            Admin 2FA</span
                        >
                    </div>
                    <h1 class="mt-1.5 text-xl font-bold text-foreground">
                        Bảng điều hành Quản trị & Giám sát Bảo mật
                    </h1>
                    <p class="mt-1 text-sm text-muted-foreground">
                        Trang này được bảo vệ ở backend bởi `EnsureUserHasRole`,
                        `EnsureAdminHasTwoFactor` và
                        `AccountPolicy::accessAdmin`.
                    </p>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <Button
                        v-if="user?.can?.reviewAds"
                        size="sm"
                        variant="outline"
                        class="rounded-lg"
                        as-child
                    >
                        <Link :href="adminAds()">
                            <span>Duyệt quảng cáo</span>
                        </Link>
                    </Button>

                    <Button
                        v-if="user?.can?.managePolicies"
                        size="sm"
                        class="rounded-lg"
                        as-child
                    >
                        <Link :href="adminPolicies()">
                            <FileText class="size-4" />
                            <span>Quản lý Chính sách & Footer</span>
                        </Link>
                    </Button>
                </div>
            </div>
        </section>

        <section class="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
            <div class="rounded-xl border border-border bg-card p-4">
                <div
                    class="flex items-center justify-between text-xs text-muted-foreground"
                >
                    <span>Tổng người dùng</span>
                    <Users class="size-4 text-primary" aria-hidden="true" />
                </div>
                <p class="mt-2 text-2xl font-bold text-foreground">
                    {{ metrics.total_users }}
                </p>
            </div>

            <div class="rounded-xl border border-border bg-card p-4">
                <div
                    class="flex items-center justify-between text-xs text-muted-foreground"
                >
                    <span>Đang hoạt động</span>
                    <UserCheck class="size-4 text-success" aria-hidden="true" />
                </div>
                <p class="mt-2 text-2xl font-bold text-foreground">
                    {{ metrics.active_users }}
                </p>
            </div>

            <div class="rounded-xl border border-border bg-card p-4">
                <div
                    class="flex items-center justify-between text-xs text-muted-foreground"
                >
                    <span>Đã xác minh email</span>
                    <MailCheck class="size-4 text-primary" aria-hidden="true" />
                </div>
                <p class="mt-2 text-2xl font-bold text-foreground">
                    {{ metrics.verified_users }}
                </p>
            </div>

            <div class="rounded-xl border border-border bg-card p-4">
                <div
                    class="flex items-center justify-between text-xs text-muted-foreground"
                >
                    <span>Nhân sự quản trị</span>
                    <Shield class="size-4 text-primary" aria-hidden="true" />
                </div>
                <p class="mt-2 text-2xl font-bold text-foreground">
                    {{ metrics.staff_users }}
                </p>
            </div>

            <div class="rounded-xl border border-border bg-card p-4 shadow-xs">
                <div
                    class="flex items-center justify-between text-xs text-muted-foreground"
                >
                    <span>Tài khoản bị khóa</span>
                    <ShieldAlert
                        class="size-4 text-destructive"
                        aria-hidden="true"
                    />
                </div>
                <p class="mt-2 text-2xl font-bold text-foreground">
                    {{ metrics.suspended_users }}
                </p>
            </div>
        </section>

        <!-- Protected Platform & Security Status (Admin/Moderator only) -->
        <section class="rounded-xl border border-border bg-card p-5 shadow-xs">
            <div class="flex items-center gap-2">
                <Server class="size-4 text-primary" aria-hidden="true" />
                <h2 class="text-base font-semibold text-foreground">
                    Platform Status & Security Posture
                </h2>
            </div>
            <p class="mt-1 text-xs text-muted-foreground">
                Thông số vận hành và cấu hình bảo mật nội bộ (chỉ hiển thị cho
                nhân sự có thẩm quyền).
            </p>

            <dl class="mt-4 grid gap-3 text-xs sm:grid-cols-2 lg:grid-cols-4">
                <div class="rounded-lg border border-border/80 bg-muted/40 p-3">
                    <dt class="text-muted-foreground">Environment / Locale</dt>
                    <dd class="mt-1 font-mono font-semibold text-foreground">
                        {{ platform_status.app_env }} ·
                        {{ platform_status.locale }}
                    </dd>
                </div>
                <div class="rounded-lg border border-border/80 bg-muted/40 p-3">
                    <dt class="text-muted-foreground">Password Hashing</dt>
                    <dd class="mt-1 font-mono font-semibold text-foreground">
                        {{ platform_status.hashing_driver }}
                    </dd>
                </div>
                <div class="rounded-lg border border-border/80 bg-muted/40 p-3">
                    <dt class="text-muted-foreground">Admin 2FA Policy</dt>
                    <dd class="mt-1 font-semibold text-success">
                        {{
                            platform_status.admin_two_factor_enforced
                                ? 'Mandatory Enforced'
                                : 'Optional'
                        }}
                    </dd>
                </div>
                <div class="rounded-lg border border-border/80 bg-muted/40 p-3">
                    <dt class="text-muted-foreground">
                        Session / Cache / Queue
                    </dt>
                    <dd class="mt-1 font-mono font-semibold text-foreground">
                        {{ platform_status.session_driver }} /
                        {{ platform_status.cache_store }} /
                        {{ platform_status.queue_connection }}
                    </dd>
                </div>
                <div class="rounded-lg border border-border/80 bg-muted/40 p-3">
                    <dt class="text-muted-foreground">Media Storage Disk</dt>
                    <dd class="mt-1 font-mono font-semibold text-foreground">
                        {{ platform_status.media_disk }}
                    </dd>
                </div>
                <div class="rounded-lg border border-border/80 bg-muted/40 p-3">
                    <dt class="text-muted-foreground">Inertia SSR</dt>
                    <dd class="mt-1 font-mono font-semibold text-foreground">
                        {{
                            platform_status.ssr_enabled ? 'Enabled' : 'Disabled'
                        }}
                    </dd>
                </div>
                <div
                    class="rounded-lg border border-border/80 bg-muted/40 p-3 sm:col-span-2"
                >
                    <dt class="text-muted-foreground">
                        Trang chính sách & thông tin
                    </dt>
                    <dd class="mt-1 font-semibold text-foreground">
                        {{ platform_status.published_policies }} đã xuất bản ·
                        {{ platform_status.draft_policies }} bản nháp (ẩn công
                        khai)
                    </dd>
                </div>
            </dl>
        </section>

        <section class="rounded-xl border border-border bg-card p-5 shadow-xs">
            <h2 class="text-base font-semibold text-foreground">
                Tài khoản mới nhất trong hệ thống
            </h2>
            <p class="mt-1 text-xs text-muted-foreground">
                Hiển thị tối đa 8 tài khoản gần đây (select column tối ưu, không
                lộ trường nhạy cảm).
            </p>

            <div v-if="recent_users.length === 0" class="mt-4">
                <EmptyState
                    title="Chưa có dữ liệu tài khoản"
                    description="Hệ thống chưa ghi nhận tài khoản người dùng nào trong cơ sở dữ liệu."
                />
            </div>

            <div v-else class="mt-4 overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead
                        class="border-b border-border text-xs text-muted-foreground"
                    >
                        <tr>
                            <th class="py-2.5 pr-4 font-medium">Thành viên</th>
                            <th class="py-2.5 pr-4 font-medium">Vai trò</th>
                            <th class="py-2.5 pr-4 font-medium">Trạng thái</th>
                            <th class="py-2.5 font-medium">Xác minh</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        <tr v-for="account in recent_users" :key="account.id">
                            <td class="py-3 pr-4">
                                <p class="font-medium text-foreground">
                                    {{ account.name }}
                                </p>
                                <p class="text-xs text-muted-foreground">
                                    @{{ account.username }}
                                </p>
                            </td>
                            <td class="py-3 pr-4">
                                <RoleBadge
                                    :role="account.role"
                                    :label="account.role_label"
                                />
                            </td>
                            <td class="py-3 pr-4">
                                <Badge variant="secondary" class="text-xs">
                                    {{ account.status_label }}
                                </Badge>
                            </td>
                            <td class="py-3 text-xs">
                                <span
                                    :class="
                                        account.email_verified
                                            ? 'text-success'
                                            : 'text-warning'
                                    "
                                >
                                    {{
                                        account.email_verified
                                            ? 'Đã xác minh'
                                            : 'Chờ xác minh'
                                    }}
                                </span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- Audit Log Table -->
        <section class="rounded-xl border border-border bg-card p-5 shadow-xs">
            <div class="flex items-center gap-2">
                <Activity class="size-4 text-primary" aria-hidden="true" />
                <h2 class="text-base font-semibold text-foreground">
                    Nhật ký bảo mật & quản trị (Audit Log)
                </h2>
            </div>

            <div v-if="recent_audits.length === 0" class="mt-4">
                <EmptyState
                    title="Chưa có nhật ký sự kiện"
                    description="Các thao tác đăng nhập, bật 2FA, đăng xuất thiết bị khác và chỉnh sửa chính sách sẽ được ghi nhận tại đây."
                />
            </div>

            <div v-else class="mt-4 overflow-x-auto">
                <table class="w-full text-left text-xs">
                    <thead class="border-b border-border text-muted-foreground">
                        <tr>
                            <th class="py-2 pr-4 font-medium">Hành động</th>
                            <th class="py-2 pr-4 font-medium">Tài khoản</th>
                            <th class="py-2 pr-4 font-medium">IP</th>
                            <th class="py-2 font-medium">Thời gian</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        <tr v-for="audit in recent_audits" :key="audit.id">
                            <td class="py-2.5 pr-4 font-mono text-foreground">
                                {{ audit.action }}
                            </td>
                            <td class="py-2.5 pr-4 text-muted-foreground">
                                {{
                                    audit.actor_username
                                        ? `@${audit.actor_username}`
                                        : 'system'
                                }}
                            </td>
                            <td class="py-2.5 pr-4 text-muted-foreground">
                                {{ audit.ip_address || '-' }}
                            </td>
                            <td class="py-2.5 text-muted-foreground">
                                {{
                                    new Date(audit.created_at).toLocaleString()
                                }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</template>
