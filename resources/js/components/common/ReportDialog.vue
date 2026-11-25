<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { watch } from 'vue';
import { useI18n } from 'vue-i18n';
import ReportController from '@/actions/App/Http/Controllers/Moderation/ReportController';
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
        <DialogContent class="max-w-md">
            <DialogHeader>
                <DialogTitle>{{ t('report.title') }}</DialogTitle>
            </DialogHeader>

            <form @submit.prevent="submitReport" class="space-y-4">
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
                    <Label :for="`report-details-${targetType}-${targetId}`">
                        {{ t('report.details') }}
                    </Label>
                    <textarea
                        :id="`report-details-${targetType}-${targetId}`"
                        v-model="reportForm.details"
                        rows="3"
                        maxlength="1000"
                        class="w-full rounded-lg border border-input bg-background px-3 py-2 text-sm"
                    />
                    <InputError :message="reportForm.errors.details" />
                </div>

                <DialogFooter class="gap-2">
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
