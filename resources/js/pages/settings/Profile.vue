<script setup lang="ts">
import { Form, Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
import DeleteUser from '@/components/DeleteUser.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { edit } from '@/routes/profile';
import { send } from '@/routes/verification';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Hồ sơ cá nhân',
                href: edit(),
            },
        ],
    },
});

const page = usePage();
const user = computed(() => page.props.auth.user);

const timezoneOptions = [
    { value: 'Asia/Ho_Chi_Minh', label: 'Asia/Ho_Chi_Minh (GMT+7)' },
    { value: 'UTC', label: 'UTC (GMT+0)' },
    { value: 'Asia/Singapore', label: 'Asia/Singapore (GMT+8)' },
    { value: 'Asia/Tokyo', label: 'Asia/Tokyo (GMT+9)' },
];
</script>

<template>
    <div class="space-y-10">
        <Head title="Hồ sơ cá nhân" />

        <h1 class="sr-only">Cài đặt hồ sơ cá nhân</h1>

        <div v-if="user" class="flex flex-col space-y-6">
            <Heading
                variant="small"
                title="Thông tin hồ sơ"
                description="Cập nhật tên hiển thị, định danh @username, email và múi giờ của bạn"
            />

            <Form
                v-bind="ProfileController.update.form()"
                class="space-y-5"
                v-slot="{ errors, processing }"
            >
                <div class="grid gap-2">
                    <Label for="name">Họ và tên</Label>
                    <Input
                        id="name"
                        class="mt-1 block w-full"
                        name="name"
                        :default-value="user.name"
                        required
                        autocomplete="name"
                        placeholder="Nguyễn Minh Anh"
                    />
                    <InputError class="mt-1" :message="errors.name" />
                </div>

                <div class="grid gap-2">
                    <Label for="username">Tên người dùng (@username)</Label>
                    <Input
                        id="username"
                        class="mt-1 block w-full"
                        name="username"
                        :default-value="user.username"
                        required
                        autocomplete="username"
                        placeholder="minh_anh"
                    />
                    <InputError class="mt-1" :message="errors.username" />
                </div>

                <div class="grid gap-2">
                    <Label for="email">Địa chỉ email</Label>
                    <Input
                        id="email"
                        type="email"
                        class="mt-1 block w-full"
                        name="email"
                        :default-value="user.email"
                        required
                        autocomplete="email"
                        placeholder="ban@example.com"
                    />
                    <InputError class="mt-1" :message="errors.email" />
                </div>

                <div class="grid gap-2">
                    <Label for="timezone">Múi giờ hiển thị</Label>
                    <select
                        id="timezone"
                        name="timezone"
                        :value="user.timezone"
                        class="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-xs transition-[color,box-shadow] outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 dark:bg-card"
                    >
                        <option
                            v-for="tz in timezoneOptions"
                            :key="tz.value"
                            :value="tz.value"
                        >
                            {{ tz.label }}
                        </option>
                    </select>
                    <InputError class="mt-1" :message="errors.timezone" />
                </div>

                <div
                    v-if="page.props.mustVerifyEmail && !user.email_verified_at"
                >
                    <p class="-mt-2 text-sm text-muted-foreground">
                        Địa chỉ email của bạn chưa được xác minh.
                        <Link
                            :href="send()"
                            as="button"
                            class="font-medium text-primary underline underline-offset-4"
                        >
                            Nhấn vào đây để gửi lại email xác minh.
                        </Link>
                    </p>

                    <div
                        v-if="page.props.status === 'verification-link-sent'"
                        class="mt-2 text-sm font-medium text-emerald-600 dark:text-emerald-400"
                    >
                        <p class="-mt-2 text-sm text-muted-foreground">
                            Địa chỉ email của bạn chưa được xác minh.
                            <Link
                                :href="send()"
                                as="button"
                                class="font-medium text-primary underline underline-offset-4"
                            >
                                Nhấn vào đây để gửi lại email xác minh.
                            </Link>
                        </p>

                        <div
                            v-if="
                                page.props.status === 'verification-link-sent'
                            "
                            class="mt-2 text-sm font-medium text-success"
                        >
                            Liên kết xác minh mới đã được gửi đến email của bạn.
                        </div>
                    </div>
                </div>

                <div class="flex items-center gap-4">
                    <Button
                        class="rounded-lg"
                        :disabled="processing"
                        data-test="update-profile-button"
                    >
                        Lưu thay đổi
                    </Button>
                </div>
            </Form>
        </div>

        <DeleteUser />
    </div>
</template>
