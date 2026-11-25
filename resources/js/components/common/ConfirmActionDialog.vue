<script setup lang="ts">
import { AlertTriangle, ShieldAlert } from '@lucide/vue';
import { useI18n } from 'vue-i18n';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';

const open = defineModel<boolean>('open', { required: true });

const props = withDefaults(
    defineProps<{
        title: string;
        description: string;
        consequence?: string;
        confirmLabel?: string;
        cancelLabel?: string;
        destructive?: boolean;
        processing?: boolean;
    }>(),
    {
        destructive: true,
        processing: false,
    },
);

const emit = defineEmits<{
    confirm: [];
    cancel: [];
}>();

const { t } = useI18n();

function handleCancel(): void {
    open.value = false;
    emit('cancel');
}

function handleConfirm(): void {
    emit('confirm');
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="rounded-2xl sm:max-w-md">
            <DialogHeader>
                <div class="flex items-start gap-3">
                    <div
                        :class="[
                            'flex size-10 shrink-0 items-center justify-center rounded-xl',
                            props.destructive
                                ? 'bg-destructive-soft text-destructive'
                                : 'bg-warning-soft text-warning',
                        ]"
                    >
                        <ShieldAlert
                            v-if="props.destructive"
                            class="size-5"
                            aria-hidden="true"
                        />
                        <AlertTriangle
                            v-else
                            class="size-5"
                            aria-hidden="true"
                        />
                    </div>
                    <div class="space-y-1">
                        <DialogTitle
                            class="text-base font-semibold text-foreground"
                        >
                            {{ props.title }}
                        </DialogTitle>
                        <DialogDescription
                            class="text-sm text-muted-foreground"
                        >
                            {{ props.description }}
                        </DialogDescription>
                    </div>
                </div>
            </DialogHeader>

            <div
                v-if="props.consequence"
                class="rounded-xl border border-destructive/20 bg-destructive-soft/60 px-3.5 py-2.5 text-xs leading-relaxed text-destructive-soft-foreground"
            >
                {{ props.consequence }}
            </div>

            <slot />

            <DialogFooter class="gap-2 sm:justify-end">
                <Button
                    type="button"
                    variant="outline"
                    class="rounded-lg"
                    :disabled="props.processing"
                    @click="handleCancel"
                >
                    {{ props.cancelLabel || t('dialog.cancel') }}
                </Button>
                <Button
                    type="button"
                    :variant="props.destructive ? 'destructive' : 'default'"
                    class="rounded-lg"
                    :disabled="props.processing"
                    @click="handleConfirm"
                >
                    {{ props.confirmLabel || t('dialog.confirm') }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
