<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { login } from '@/routes';
import { store } from '@/routes/register';

defineProps<{
    passwordRules: string;
}>();

defineOptions({
    layout: {
        title: 'Tạo tài khoản mới',
        description: 'Điền thông tin cá nhân để tham gia cộng đồng',
    },
});
</script>

<template>
    <div>
        <Head title="Đăng ký" />

        <!-- Google OAuth Button -->
        <a
            href="/auth/google/redirect"
            class="mb-5 flex w-full items-center justify-center gap-3 rounded-lg border border-border bg-card px-4 py-2.5 text-sm font-medium text-foreground transition hover:bg-muted"
        >
            <svg class="h-5 w-5" viewBox="0 0 24 24" aria-hidden="true">
                <path d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z" fill="#4285F4"/>
                <path d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z" fill="#34A853"/>
                <path d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l3.66-2.84z" fill="#FBBC05"/>
                <path d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z" fill="#EA4335"/>
            </svg>
            Đăng ký với Google
        </a>

        <div class="relative mb-5 flex items-center gap-3">
            <div class="h-px flex-1 bg-border" />
            <span class="text-xs text-muted-foreground">hoặc tạo tài khoản</span>
            <div class="h-px flex-1 bg-border" />
        </div>

        <Form
            v-bind="store.form()"
            :reset-on-success="['password', 'password_confirmation']"
            v-slot="{ errors, processing }"
            class="flex flex-col gap-5"
        >
            <div class="grid gap-4">
                <div class="grid gap-2">
                    <Label for="name">Họ và tên</Label>
                    <Input
                        id="name"
                        type="text"
                        required
                        v-focus
                        :tabindex="1"
                        autocomplete="name"
                        name="name"
                        placeholder="Nguyễn Minh Anh"
                    />
                    <InputError :message="errors.name" />
                </div>

                <div class="grid gap-2">
                    <Label for="username">Tên người dùng (tùy chọn)</Label>
                    <Input
                        id="username"
                        type="text"
                        :tabindex="2"
                        autocomplete="username"
                        name="username"
                        placeholder="minh_anh (chỉ gồm a-z, 0-9, _)"
                    />
                    <InputError :message="errors.username" />
                </div>

                <div class="grid gap-2">
                    <Label for="email">Địa chỉ email</Label>
                    <Input
                        id="email"
                        type="email"
                        required
                        :tabindex="3"
                        autocomplete="email"
                        name="email"
                        placeholder="ban@example.com"
                    />
                    <InputError :message="errors.email" />
                </div>

                <div class="grid gap-2">
                    <Label for="password">Mật khẩu</Label>
                    <PasswordInput
                        id="password"
                        required
                        :tabindex="4"
                        autocomplete="new-password"
                        name="password"
                        placeholder="Tối thiểu 8–12 ký tự bảo mật"
                        :passwordrules="passwordRules"
                    />
                    <InputError :message="errors.password" />
                </div>

                <div class="grid gap-2">
                    <Label for="password_confirmation">Xác nhận mật khẩu</Label>
                    <PasswordInput
                        id="password_confirmation"
                        required
                        :tabindex="5"
                        autocomplete="new-password"
                        name="password_confirmation"
                        placeholder="Nhập lại mật khẩu"
                        :passwordrules="passwordRules"
                    />
                    <InputError :message="errors.password_confirmation" />
                </div>

                <Button
                    type="submit"
                    class="mt-2 w-full rounded-lg"
                    tabindex="6"
                    :disabled="processing"
                    data-test="register-user-button"
                >
                    <Spinner v-if="processing" />
                    Tạo tài khoản
                </Button>
            </div>

            <div class="text-center text-sm text-muted-foreground">
                Đã có tài khoản?
                <TextLink
                    :href="login()"
                    class="font-medium text-primary underline underline-offset-4"
                    :tabindex="7"
                >
                    Đăng nhập
                </TextLink>
            </div>
        </Form>
    </div>
</template>
