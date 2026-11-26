<script setup lang="ts">
import { Form, Head, setLayoutProps } from '@inertiajs/vue3';
import { ref, watchEffect } from 'vue';
import { useI18n } from 'vue-i18n';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { update } from '@/routes/password';

const props = defineProps<{
    token: string;
    email: string;
    passwordRules: string;
}>();

const { t } = useI18n();
const inputEmail = ref(props.email);

watchEffect(() => {
    setLayoutProps({
        title: t('auth.resetPassword.layoutTitle'),
        description: t('auth.resetPassword.layoutDescription'),
    });
});
</script>

<template>
    <div>
        <Head :title="t('auth.resetPassword.pageTitle')" />

        <Form
            v-bind="update.form()"
            :transform="(data) => ({ ...data, token, email })"
            :reset-on-success="['password', 'password_confirmation']"
            v-slot="{ errors, processing }"
        >
            <div class="grid gap-5">
                <div class="grid gap-2">
                    <Label for="email">{{ t('auth.fields.email') }}</Label>
                    <Input
                        id="email"
                        type="email"
                        name="email"
                        autocomplete="email"
                        v-model="inputEmail"
                        class="mt-1 block w-full"
                        readonly
                    />
                    <InputError :message="errors.email" class="mt-2" />
                </div>

                <div class="grid gap-2">
                    <Label for="password">{{
                        t('auth.fields.newPassword')
                    }}</Label>
                    <PasswordInput
                        id="password"
                        name="password"
                        autocomplete="new-password"
                        class="mt-1 block w-full"
                        autofocus
                        :placeholder="
                            t('auth.fields.enterNewPasswordPlaceholder')
                        "
                        :passwordrules="passwordRules"
                    />
                    <InputError :message="errors.password" />
                </div>

                <div class="grid gap-2">
                    <Label for="password_confirmation">{{
                        t('auth.fields.confirmNewPassword')
                    }}</Label>
                    <PasswordInput
                        id="password_confirmation"
                        name="password_confirmation"
                        autocomplete="new-password"
                        class="mt-1 block w-full"
                        :placeholder="
                            t('auth.fields.confirmNewPasswordPlaceholder')
                        "
                        :passwordrules="passwordRules"
                    />
                    <InputError :message="errors.password_confirmation" />
                </div>

                <Button
                    type="submit"
                    class="mt-2 w-full rounded-lg"
                    :disabled="processing"
                    data-test="reset-password-button"
                >
                    <Spinner v-if="processing" />
                    {{ t('auth.resetPassword.submit') }}
                </Button>
            </div>
        </Form>
    </div>
</template>
