<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { useTemplateRef } from 'vue';
import { useI18n } from 'vue-i18n';
import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
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

const { t } = useI18n();
const passwordInput = useTemplateRef('passwordInput');
</script>

<template>
    <div class="space-y-6">
        <Heading
            variant="small"
            :title="t('settings.deleteAccount.headingTitle')"
            :description="t('settings.deleteAccount.headingDescription')"
        />
        <div
            class="space-y-4 rounded-xl border border-red-200 bg-red-50 p-4 dark:border-red-900/50 dark:bg-red-950/30"
        >
            <div class="relative space-y-0.5 text-red-700 dark:text-red-200">
                <p class="font-medium">
                    {{ t('settings.deleteAccount.warningTitle') }}
                </p>
                <p class="text-sm">
                    {{ t('settings.deleteAccount.warningDescription') }}
                </p>
            </div>
            <Dialog>
                <DialogTrigger as-child>
                    <Button
                        variant="destructive"
                        class="rounded-lg"
                        data-test="delete-user-button"
                    >
                        {{ t('settings.deleteAccount.deleteButton') }}
                    </Button>
                </DialogTrigger>
                <DialogContent>
                    <Form
                        v-bind="ProfileController.destroy.form()"
                        reset-on-success
                        @error="() => passwordInput?.focus()"
                        :options="{
                            preserveScroll: true,
                        }"
                        class="space-y-6"
                        v-slot="{ errors, processing, reset, clearErrors }"
                    >
                        <DialogHeader class="space-y-3">
                            <DialogTitle>
                                {{ t('settings.deleteAccount.confirmTitle') }}
                            </DialogTitle>
                            <DialogDescription>
                                {{
                                    t(
                                        'settings.deleteAccount.confirmDescription',
                                    )
                                }}
                            </DialogDescription>
                        </DialogHeader>

                        <div class="grid gap-2">
                            <Label for="password" class="sr-only">
                                {{ t('settings.deleteAccount.passwordLabel') }}
                            </Label>
                            <PasswordInput
                                id="password"
                                name="password"
                                ref="passwordInput"
                                :placeholder="
                                    t(
                                        'settings.deleteAccount.passwordPlaceholder',
                                    )
                                "
                            />
                            <InputError :message="errors.password" />
                        </div>

                        <DialogFooter class="gap-2">
                            <DialogClose as-child>
                                <Button
                                    variant="secondary"
                                    class="rounded-lg"
                                    @click="
                                        () => {
                                            clearErrors();
                                            reset();
                                        }
                                    "
                                >
                                    {{ t('common.cancel') }}
                                </Button>
                            </DialogClose>

                            <Button
                                type="submit"
                                variant="destructive"
                                class="rounded-lg"
                                :disabled="processing"
                                data-test="confirm-delete-user-button"
                            >
                                {{ t('settings.deleteAccount.confirmButton') }}
                            </Button>
                        </DialogFooter>
                    </Form>
                </DialogContent>
            </Dialog>
        </div>
    </div>
</template>
