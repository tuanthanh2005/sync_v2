<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { History, Laptop, LogOut, ShieldAlert } from '@lucide/vue';
import { ref } from 'vue';
import SecurityController from '@/actions/App/Http/Controllers/Settings/SecurityController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import type { Props as ManageTwoFactorProps } from '@/components/ManageTwoFactor.vue';
import ManageTwoFactor from '@/components/ManageTwoFactor.vue';
import PasswordInput from '@/components/PasswordInput.vue';
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

const logoutDialogOpen = ref(false);

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Bảo mật & 2FA',
                href: edit(),
            },
        ],
    },
});
</script>

<template>
    <div class="space-y-10">
        <Head title="Bảo mật & 2FA" />

        <h1 class="sr-only">Cài đặt bảo mật</h1>

        <div
            v-if="requiresAdminTwoFactor"
            role="alert"
            class="flex items-start gap-3 rounded-xl border border-warning/30 bg-warning-soft p-4 text-sm text-warning-soft-foreground"
        >
            <ShieldAlert class="mt-0.5 size-5 shrink-0" aria-hidden="true" />
            <div>
                <p class="font-semibold">
                    Quản trị viên bắt buộc bật xác thực 2 lớp (2FA)
                </p>
                <p class="mt-0.5 text-xs opacity-90">
                    Vui lòng kích hoạt 2FA bên dưới để mở khóa toàn bộ chức năng
                    quản trị hệ thống.
                </p>
            </div>
        </div>

        <div class="space-y-6">
            <Heading
                variant="small"
                :title="
                    hasPassword ? 'Cập nhật mật khẩu' : 'Đặt mật khẩu lần đầu'
                "
                :description="
                    hasPassword
                        ? 'Sử dụng mật khẩu mạnh và duy nhất để bảo vệ tài khoản của bạn'
                        : 'Tạo mật khẩu cho tài khoản Google của bạn để bật xác thực 2 lớp (2FA) và quản lý phiên đăng nhập'
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
                class="space-y-5"
                v-slot="{ errors, processing }"
            >
                <div v-if="hasPassword" class="grid gap-2">
                    <Label for="current_password">Mật khẩu hiện tại</Label>
                    <PasswordInput
                        id="current_password"
                        name="current_password"
                        class="mt-1 block w-full"
                        autocomplete="current-password"
                        placeholder="Nhập mật khẩu hiện tại"
                    />
                    <InputError :message="errors.current_password" />
                </div>

                <div class="grid gap-2">
                    <Label for="password">Mật khẩu mới</Label>
                    <PasswordInput
                        id="password"
                        name="password"
                        class="mt-1 block w-full"
                        autocomplete="new-password"
                        placeholder="Nhập mật khẩu mới"
                        :passwordrules="props.passwordRules"
                    />
                    <InputError :message="errors.password" />
                </div>

                <div class="grid gap-2">
                    <Label for="password_confirmation"
                        >Xác nhận mật khẩu mới</Label
                    >
                    <PasswordInput
                        id="password_confirmation"
                        name="password_confirmation"
                        class="mt-1 block w-full"
                        autocomplete="new-password"
                        placeholder="Nhập lại mật khẩu mới"
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
                        {{ hasPassword ? 'Cập nhật mật khẩu' : 'Đặt mật khẩu' }}
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
                title="Xác thực 2 lớp (2FA)"
                description="Bảo vệ tài khoản bằng mã TOTP từ ứng dụng xác thực"
            />
            <p class="text-sm text-muted-foreground">
                Vui lòng đặt mật khẩu ở mục bên trên trước, sau đó bạn có thể
                bật xác thực 2 lớp (2FA) ngay tại đây.
            </p>
        </div>

        <!-- Active Browser Sessions & Logout All Devices -->
        <div class="space-y-4">
            <Heading
                variant="small"
                title="Phiên đăng nhập & Thiết bị"
                description="Quản lý và thu hồi các phiên trình duyệt khác đang đăng nhập tài khoản của bạn"
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
                                    'Trình duyệt không xác định'
                                }}
                            </p>
                            <p class="text-muted-foreground">
                                IP: {{ session.ip_address || '-' }} ·
                                {{
                                    new Date(
                                        session.last_active_at,
                                    ).toLocaleString()
                                }}
                            </p>
                        </div>
                    </div>
                    <Badge
                        v-if="session.is_current_device"
                        variant="secondary"
                        class="shrink-0 text-[10px]"
                    >
                        Thiết bị hiện tại
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
                        <span>Đăng xuất khỏi tất cả thiết bị khác</span>
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
                                Đăng xuất tất cả thiết bị khác
                            </DialogTitle>
                            <DialogDescription>
                                Nhập mật khẩu hiện tại để xác nhận đăng xuất
                                khỏi mọi trình duyệt và thiết bị khác.
                            </DialogDescription>
                        </DialogHeader>

                        <div class="grid gap-2">
                            <Label for="logout_sessions_password"
                                >Mật khẩu hiện tại</Label
                            >
                            <PasswordInput
                                id="logout_sessions_password"
                                name="password"
                                required
                                autocomplete="current-password"
                                placeholder="Nhập mật khẩu"
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
                                    Hủy
                                </Button>
                            </DialogClose>
                            <Button
                                type="submit"
                                class="rounded-lg"
                                :disabled="processing"
                                data-test="confirm-logout-other-sessions-button"
                            >
                                Xác nhận đăng xuất
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
                    title="Lịch sử đăng nhập gần đây"
                    description="Theo dõi các lần đăng nhập thành công, thất bại hoặc đăng xuất"
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
                            IP: {{ entry.ip_address || '-' }}
                        </span>
                    </div>
                    <span class="text-muted-foreground">
                        {{ new Date(entry.created_at).toLocaleString() }}
                    </span>
                </div>
            </div>
            <p v-else class="text-xs text-muted-foreground">
                Chưa có bản ghi lịch sử đăng nhập nào cho phiên hiện tại.
            </p>
        </div>
    </div>
</template>
