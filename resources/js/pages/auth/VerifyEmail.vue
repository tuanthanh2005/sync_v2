<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { logout } from '@/routes';
import { send } from '@/routes/verification';

defineOptions({
    layout: {
        title: 'Xác minh địa chỉ email',
        description:
            'Vui lòng kiểm tra hộp thư và nhấn vào liên kết xác minh chúng tôi vừa gửi.',
    },
});

defineProps<{
    status?: string;
}>();
</script>

<template>
    <div>
        <Head title="Xác minh email" />

        <div
            v-if="status === 'verification-link-sent'"
            class="mb-4 rounded-lg border border-success/25 bg-success-soft px-3.5 py-2.5 text-center text-sm font-medium text-success-soft-foreground"
        >
            Liên kết xác minh mới đã được gửi đến địa chỉ email của bạn.
        </div>

        <Form
            v-bind="send.form()"
            class="space-y-6 text-center"
            v-slot="{ processing }"
        >
            <Button
                :disabled="processing"
                variant="secondary"
                class="w-full rounded-lg"
            >
                <Spinner v-if="processing" />
                Gửi lại email xác minh
            </Button>

            <TextLink
                :href="logout()"
                as="button"
                class="mx-auto block text-sm"
            >
                Đăng xuất
            </TextLink>
        </Form>
    </div>
</template>
