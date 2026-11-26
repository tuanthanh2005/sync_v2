<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { watch } from 'vue';
import { useI18n } from 'vue-i18n';
import ReportController from '@/actions/App/Http/Controllers/Moderation/ReportController';
import AutoHideScrollArea from '@/components/common/AutoHideScrollArea.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';

const props = defineProps<{
    targetType: 'post' | 'comment' | 'user';
    targetId: number;
    defaultReason?:
        | 'spam'
        | 'harassment'
        | 'misinformation'
        | 'copyright'
        | 'other';
}>();

const open = defineModel<boolean>('open', { default: false });

const { t } = useI18n();

const reportForm = useForm({
    target_type: props.targetType,
    target_id: props.targetId,
    reason: props.defaultReason ?? 'spam',
    details: '',
});

watch(
    () => props.targetId,
    (id) => {
        reportForm.target_id = id;
    },
);

function submitReport(): void {
    reportForm.target_type = props.targetType;
    reportForm.target_id = props.targetId;
    reportForm.post(ReportController.store.url(), {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
            reportForm.reset('details');
        },
    });
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent structured class="max-w-md rounded-2xl">
            <div
                class="shrink-0 border-b border-border/70 px-5 pe-12 pt-4 pb-3.5 sm:px-6 sm:pt-5 sm:pb-4"
            >
                <div
                    class="mx-auto mb-3 h-1 w-10 rounded-full bg-border sm:hidden"
                    aria-hidden="true"
                />
                <DialogHeader class="text-start">
                    <DialogTitle>{{ t('report.title') }}</DialogTitle>
                </DialogHeader>
            </div>

            <form
                @submit.prevent="submitReport"
                class="flex min-h-0 flex-1 flex-col"
            >
                <AutoHideScrollArea class="flex-1 space-y-4 px-5 py-4 sm:px-6">
                    <div class="grid gap-1.5">
                        <Label :for="`report-reason-${targetType}-${targetId}`">
                            {{ t('report.reason') }}
                        </Label>
                        <select
                            :id="`report-reason-${targetType}-${targetId}`"
                            v-model="reportForm.reason"
                            class="h-9 rounded-lg border border-input bg-background px-3 text-sm"
                        >
                            <option value="spam">
                                {{ t('report.reasons.spam') }}
                            </option>
                            <option value="harassment">
                                {{ t('report.reasons.harassment') }}
                            </option>
                            <option value="misinformation">
                                {{ t('report.reasons.misinformation') }}
                            </option>
                            <option value="copyright">
                                {{ t('report.reasons.copyright') }}
                            </option>
                            <option value="other">
                                {{ t('report.reasons.other') }}
                            </option>
                        </select>
                        <InputError :message="reportForm.errors.reason" />
                    </div>

                    <div class="grid gap-1.5">
                        <Label
                            :for="`report-details-${targetType}-${targetId}`"
                        >
                            {{ t('report.details') }}
                        </Label>
                        <textarea
                            :id="`report-details-${targetType}-${targetId}`"
                            v-model="reportForm.details"
                            rows="3"
                            maxlength="1000"
                            class="sv-scrollbar w-full rounded-lg border border-input bg-background px-3 py-2 text-sm"
                        />
                        <InputError :message="reportForm.errors.details" />
                    </div>
                </AutoHideScrollArea>

                <DialogFooter
                    class="shrink-0 gap-2 border-t border-border/70 bg-card/95 px-5 py-3.5 pb-[calc(0.875rem+env(safe-area-inset-bottom,0px))] sm:px-6 sm:py-4"
                >
                    <DialogClose as-child>
                        <Button type="button" variant="secondary">
                            {{ t('post.cancel') }}
                        </Button>
                    </DialogClose>
                    <Button type="submit" :disabled="reportForm.processing">
                        {{ t('report.submit') }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
