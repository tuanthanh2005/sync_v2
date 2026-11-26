<script setup lang="ts">
import { AlertTriangle, ShieldAlert } from '@lucide/vue';
import { useI18n } from 'vue-i18n';
import AutoHideScrollArea from '@/components/common/AutoHideScrollArea.vue';
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
        <DialogContent structured class="rounded-2xl sm:max-w-md">
            <div
                class="shrink-0 border-b border-border/70 px-5 pe-12 pt-4 pb-3.5 sm:px-6 sm:pt-5 sm:pb-4"
            >
                <div
                    class="mx-auto mb-3 h-1 w-10 rounded-full bg-border sm:hidden"
                    aria-hidden="true"
                />
                <DialogHeader class="text-start">
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
                        <div class="min-w-0 space-y-1">
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
            </div>

            <AutoHideScrollArea
                v-if="props.consequence || $slots.default"
                class="flex-1 space-y-3.5 px-5 py-4 sm:px-6"
            >
                <div
                    v-if="props.consequence"
                    class="rounded-xl border border-destructive/20 bg-destructive-soft/60 px-3.5 py-2.5 text-xs leading-relaxed text-destructive-soft-foreground"
                >
                    {{ props.consequence }}
                </div>

                <slot />
            </AutoHideScrollArea>

            <DialogFooter
                class="shrink-0 gap-2 border-t border-border/70 bg-card/95 px-5 py-3.5 pb-[calc(0.875rem+env(safe-area-inset-bottom,0px))] sm:justify-end sm:px-6 sm:py-4"
            >
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
