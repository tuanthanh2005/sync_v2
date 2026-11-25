<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
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

defineOptions({
    layout: {
        title: 'Đăng nhập tài khoản',
        description: 'Nhập email và mật khẩu để truy cập tài khoản của bạn',
    },
});

defineProps<{
    status?: string;
    canResetPassword: boolean;
}>();
</script>

<template>
    <div>
        <Head title="Đăng nhập" />

        <div
            v-if="status"
            class="mb-4 rounded-lg border border-success/25 bg-success-soft px-3.5 py-2.5 text-center text-sm font-medium text-success-soft-foreground"
        >
            {{ status }}
        </div>

        <Form
            v-bind="store.form()"
            :reset-on-success="['password']"
            v-slot="{ errors, processing }"
            class="flex flex-col gap-5"
        >
            <div class="grid gap-5">
                <div class="grid gap-2">
                    <Label for="email">Địa chỉ email</Label>
                    <Input
                        id="email"
                        type="email"
                        name="email"
                        required
                        v-focus
                        :tabindex="1"
                        autocomplete="email"
                        placeholder="ban@example.com"
                    />
                    <InputError :message="errors.email" />
                </div>

                <div class="grid gap-2">
                    <div class="flex items-center justify-between">
                        <Label for="password">Mật khẩu</Label>
                        <TextLink
                            v-if="canResetPassword"
                            :href="request()"
                            class="text-sm text-primary"
                            :tabindex="5"
                        >
                            Quên mật khẩu?
                        </TextLink>
                    </div>
                    <PasswordInput
                        id="password"
                        name="password"
                        required
                        :tabindex="2"
                        autocomplete="current-password"
                        placeholder="Nhập mật khẩu"
                    />
                    <InputError :message="errors.password" />
                </div>

                <div class="flex items-center justify-between">
                    <Label for="remember" class="flex items-center space-x-2.5">
                        <Checkbox id="remember" name="remember" :tabindex="3" />
                        <span class="text-sm font-normal text-muted-foreground">
                            Ghi nhớ đăng nhập
                        </span>
                    </Label>
                </div>

                <Button
                    type="submit"
                    class="mt-2 w-full rounded-lg"
                    :tabindex="4"
                    :disabled="processing"
                    data-test="login-button"
                >
                    <Spinner v-if="processing" />
                    Đăng nhập
                </Button>
            </div>

            <div class="text-center text-sm text-muted-foreground">
                Chưa có tài khoản?
                <TextLink
                    :href="register()"
                    class="font-medium text-primary"
                    :tabindex="5"
                >
                    Đăng ký ngay
                </TextLink>
            </div>
        </Form>
    </div>
</template>
