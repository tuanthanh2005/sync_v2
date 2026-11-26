<script setup lang="ts">
import { ShieldCheck } from '@lucide/vue';
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
import { Spinner } from '@/components/ui/spinner';
import { formatMoney } from '@/lib/currency';

const open = defineModel<boolean>('open', { default: false });

const props = withDefaults(
    defineProps<{
        serviceName: string;
        itemName: string;
        durationLabel?: string;
        amount: number;
        feeAmount?: number;
        currency: string;
        methodName: string;
        processing?: boolean;
    }>(),
    {
        durationLabel: '',
        feeAmount: 0,
        processing: false,
    },
);

const emit = defineEmits<{
    confirm: [];
    cancel: [];
}>();

const { t, locale } = useI18n();

function handleCancel(): void {
    open.value = false;
    emit('cancel');
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
                    <DialogTitle class="flex items-center gap-2 text-base">
                        <ShieldCheck class="size-5 shrink-0 text-primary" />
                        <span>{{ t('monetization.confirmModal.title') }}</span>
                    </DialogTitle>
                    <DialogDescription class="text-xs leading-relaxed">
                        {{ t('monetization.confirmModal.subtitle') }}
                    </DialogDescription>
                </DialogHeader>
            </div>

            <AutoHideScrollArea class="flex-1 space-y-3.5 px-5 py-4 sm:px-6">
                <dl
                    class="space-y-2 rounded-xl border border-border bg-muted/30 p-3.5 text-xs"
                >
                    <div class="flex items-center justify-between gap-2">
                        <dt class="text-muted-foreground">
                            {{ t('monetization.confirmModal.service') }}
                        </dt>
                        <dd class="text-end font-semibold text-foreground">
                            {{ serviceName }}
                        </dd>
                    </div>
                    <div class="flex items-center justify-between gap-2">
                        <dt class="text-muted-foreground">
                            {{ t('monetization.confirmModal.package') }}
                        </dt>
                        <dd class="text-end font-semibold text-foreground">
                            {{ itemName }}
                        </dd>
                    </div>
                    <div
                        v-if="durationLabel"
                        class="flex items-center justify-between gap-2"
                    >
                        <dt class="text-muted-foreground">
                            {{ t('monetization.confirmModal.duration') }}
                        </dt>
                        <dd class="text-end font-medium text-foreground">
                            {{ durationLabel }}
                        </dd>
                    </div>
                    <div class="flex items-center justify-between gap-2">
                        <dt class="text-muted-foreground">
                            {{ t('monetization.confirmModal.method') }}
                        </dt>
                        <dd class="text-end font-medium text-foreground">
                            {{ methodName }}
                        </dd>
                    </div>
                    <div
                        v-if="feeAmount > 0"
                        class="flex items-center justify-between gap-2"
                    >
                        <dt class="text-muted-foreground">
                            {{ t('monetization.confirmModal.fee') }}
                        </dt>
                        <dd
                            class="text-end font-medium text-foreground tabular-nums"
                        >
                            {{ formatMoney(feeAmount, currency, locale) }}
                        </dd>
                    </div>
                    <div
                        class="flex items-center justify-between gap-2 border-t border-border pt-2 text-sm"
                    >
                        <dt class="font-semibold text-foreground">
                            {{ t('monetization.confirmModal.total') }}
                        </dt>
                        <dd
                            class="text-end font-bold text-primary tabular-nums"
                        >
                            {{
                                formatMoney(
                                    amount + feeAmount,
                                    currency,
                                    locale,
                                )
                            }}
                        </dd>
                    </div>
                </dl>

                <p class="text-[11px] leading-relaxed text-muted-foreground">
                    {{ t('monetization.confirmModal.termsNotice') }}
                </p>
            </AutoHideScrollArea>

            <DialogFooter
                class="shrink-0 flex-col-reverse gap-2 border-t border-border/70 bg-card/95 px-5 py-3.5 pb-[calc(0.875rem+env(safe-area-inset-bottom,0px))] sm:flex-row sm:px-6 sm:py-4"
            >
                <Button
                    type="button"
                    variant="outline"
                    class="min-h-11 rounded-xl sm:min-h-9"
                    :disabled="processing"
                    @click="handleCancel"
                >
                    {{ t('monetization.confirmModal.noButton') }}
                </Button>
                <Button
                    type="button"
                    class="min-h-11 rounded-xl sm:min-h-9"
                    :disabled="processing"
                    @click="emit('confirm')"
                >
                    <Spinner v-if="processing" />
                    <span>{{ t('monetization.confirmModal.yesButton') }}</span>
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
