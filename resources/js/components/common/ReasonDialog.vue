<script setup lang="ts">
import { ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import ConfirmActionDialog from '@/components/common/ConfirmActionDialog.vue';
import { Label } from '@/components/ui/label';

const open = defineModel<boolean>('open', { required: true });

const props = withDefaults(
    defineProps<{
        title: string;
        description: string;
        consequence?: string;
        confirmLabel?: string;
        reasonLabel?: string;
        reasonPlaceholder?: string;
        required?: boolean;
        destructive?: boolean;
        processing?: boolean;
    }>(),
    {
        required: true,
        destructive: true,
        processing: false,
    },
);

const emit = defineEmits<{
    submit: [reason: string];
}>();

const { t } = useI18n();
const reason = ref('');
const error = ref('');

watch(open, (isOpen) => {
    if (isOpen) {
        reason.value = '';
        error.value = '';
    }
});

function handleConfirm(): void {
    const clean = reason.value.trim();

    if (props.required && clean.length < 3) {
        error.value = t('dialog.reasonRequired');
        return;
    }

    error.value = '';
    emit('submit', clean);
}
</script>

<template>
    <ConfirmActionDialog
        v-model:open="open"
        :title="props.title"
        :description="props.description"
        :consequence="props.consequence"
        :confirm-label="props.confirmLabel"
        :destructive="props.destructive"
        :processing="props.processing"
        @confirm="handleConfirm"
    >
        <div class="space-y-2">
            <Label class="text-xs font-medium text-foreground">
                {{ props.reasonLabel || t('dialog.reasonLabel') }}
                <span v-if="props.required" class="text-destructive">*</span>
            </Label>
            <textarea
                v-model="reason"
                rows="3"
                :placeholder="
                    props.reasonPlaceholder || t('dialog.reasonPlaceholder')
                "
                class="w-full rounded-lg border border-input bg-background px-3 py-2 text-sm text-foreground placeholder:text-muted-foreground focus:border-primary focus:ring-2 focus:ring-primary/20 focus:outline-none"
            />
            <p v-if="error" class="text-xs text-destructive">
                {{ error }}
            </p>
            <slot />
        </div>
    </ConfirmActionDialog>
</template>
