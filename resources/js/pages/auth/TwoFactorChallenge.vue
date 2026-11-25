<script setup lang="ts">
import { Form, Head, setLayoutProps } from '@inertiajs/vue3';
import { computed, ref, watchEffect } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    InputOTP,
    InputOTPGroup,
    InputOTPSlot,
} from '@/components/ui/input-otp';
import { store } from '@/routes/two-factor/login';
import type { TwoFactorConfigContent } from '@/types';

const showRecoveryInput = ref<boolean>(false);
const code = ref<string>('');

const authConfigContent = computed<TwoFactorConfigContent>(() => {
    if (showRecoveryInput.value) {
        return {
            title: 'Mã khôi phục 2FA',
            description:
                'Nhập một trong các mã khôi phục dự phòng để xác nhận quyền truy cập tài khoản.',
            buttonText: 'đăng nhập bằng mã xác thực OTP',
        };
    }

    return {
        title: 'Mã xác thực 2 lớp',
        description:
            'Nhập mã 6 chữ số từ ứng dụng xác thực (Authenticator) trên thiết bị của bạn.',
        buttonText: 'đăng nhập bằng mã khôi phục',
    };
});

watchEffect(() => {
    setLayoutProps({
        title: authConfigContent.value.title,
        description: authConfigContent.value.description,
    });
});

const toggleRecoveryMode = (clearErrors: () => void): void => {
    showRecoveryInput.value = !showRecoveryInput.value;
    clearErrors();
    code.value = '';
};
</script>

<template>
    <div>
        <Head title="Xác thực 2 lớp" />

        <div class="space-y-6">
            <template v-if="!showRecoveryInput">
                <Form
                    v-bind="store.form()"
                    class="space-y-4"
                    reset-on-error
                    @error="code = ''"
                    #default="{ errors, processing, clearErrors }"
                >
                    <input type="hidden" name="code" :value="code" />
                    <div
                        class="flex flex-col items-center justify-center space-y-3 text-center"
                    >
                        <div class="flex w-full items-center justify-center">
                            <InputOTP
                                id="otp"
                                v-model="code"
                                :maxlength="6"
                                :disabled="processing"
                                autofocus
                            >
                                <InputOTPGroup>
                                    <InputOTPSlot
                                        v-for="index in 6"
                                        :key="index"
                                        :index="index - 1"
                                    />
                                </InputOTPGroup>
                            </InputOTP>
                        </div>
                        <InputError :message="errors.code" />
                    </div>
                    <Button
                        type="submit"
                        class="w-full rounded-lg"
                        :disabled="processing"
                    >
                        Tiếp tục
                    </Button>
                    <div class="text-center text-sm text-muted-foreground">
                        <span>Hoặc bạn có thể </span>
                        <button
                            type="button"
                            class="font-medium text-primary underline underline-offset-4"
                            @click="() => toggleRecoveryMode(clearErrors)"
                        >
                            {{ authConfigContent.buttonText }}
                        </button>
                    </div>
                </Form>
            </template>

            <template v-else>
                <Form
                    v-bind="store.form()"
                    class="space-y-4"
                    reset-on-error
                    #default="{ errors, processing, clearErrors }"
                >
                    <Input
                        name="recovery_code"
                        type="text"
                        placeholder="Nhập mã khôi phục"
                        v-focus
                        required
                    />
                    <InputError :message="errors.recovery_code" />
                    <Button
                        type="submit"
                        class="w-full rounded-lg"
                        :disabled="processing"
                    >
                        Tiếp tục
                    </Button>

                    <div class="text-center text-sm text-muted-foreground">
                        <span>Hoặc bạn có thể </span>
                        <button
                            type="button"
                            class="font-medium text-primary underline underline-offset-4"
                            @click="() => toggleRecoveryMode(clearErrors)"
                        >
                            {{ authConfigContent.buttonText }}
                        </button>
                    </div>
                </Form>
            </template>
        </div>
    </div>
</template>
