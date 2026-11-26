<script setup lang="ts">
import { Form, Head, setLayoutProps } from '@inertiajs/vue3';
import { watchEffect } from 'vue';
import { useI18n } from 'vue-i18n';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { store } from '@/routes/password/confirm';

const { t } = useI18n();

watchEffect(() => {
    setLayoutProps({
        title: t('auth.confirmPassword.layoutTitle'),
        description: t('auth.confirmPassword.layoutDescription'),
    });
});
</script>

<template>
    <div>
        <Head :title="t('auth.confirmPassword.pageTitle')" />

        <Form
            v-bind="store.form()"
            reset-on-success
            v-slot="{ errors, processing }"
        >
            <div class="space-y-6">
                <div class="grid gap-2">
                    <Label for="password">{{
                        t('auth.fields.password')
                    }}</Label>
                    <PasswordInput
                        id="password"
                        name="password"
                        class="mt-1 block w-full"
                        required
                        autocomplete="current-password"
                        autofocus
                    />

                    <InputError :message="errors.password" />
                </div>

                <div class="flex items-center">
                    <Button
                        class="w-full rounded-lg"
                        :disabled="processing"
                        data-test="confirm-password-button"
                    >
                        <Spinner v-if="processing" />
                        {{ t('auth.confirmPassword.submit') }}
                    </Button>
                </div>
            </div>
        </Form>
    </div>
</template>
