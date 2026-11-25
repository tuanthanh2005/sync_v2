<script setup lang="ts">
import { ShieldCheck } from '@lucide/vue';
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
        <DialogContent class="max-w-md rounded-2xl">
            <DialogHeader>
                <DialogTitle class="flex items-center gap-2 text-base">
                    <ShieldCheck class="size-5 text-primary" />
                    <span>{{ t('monetization.confirmModal.title') }}</span>
                </DialogTitle>
                <DialogDescription class="text-xs leading-relaxed">
                    {{ t('monetization.confirmModal.subtitle') }}
                </DialogDescription>
            </DialogHeader>

            <dl
                class="space-y-2 rounded-xl border border-border bg-muted/30 p-3.5 text-xs"
            >
                <div class="flex items-center justify-between gap-2">
                    <dt class="text-muted-foreground">
                        {{ t('monetization.confirmModal.service') }}
                    </dt>
                    <dd class="font-semibold text-foreground">
                        {{ serviceName }}
                    </dd>
                </div>
                <div class="flex items-center justify-between gap-2">
                    <dt class="text-muted-foreground">
                        {{ t('monetization.confirmModal.package') }}
                    </dt>
                    <dd class="font-semibold text-foreground">
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
                    <dd class="font-medium text-foreground">
                        {{ durationLabel }}
                    </dd>
                </div>
                <div class="flex items-center justify-between gap-2">
                    <dt class="text-muted-foreground">
                        {{ t('monetization.confirmModal.method') }}
                    </dt>
                    <dd class="font-medium text-foreground">
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
                    <dd class="font-medium text-foreground">
                        {{ formatMoney(feeAmount, currency, locale) }}
                    </dd>
                </div>
                <div
                    class="flex items-center justify-between gap-2 border-t border-border pt-2 text-sm"
                >
                    <dt class="font-semibold text-foreground">
                        {{ t('monetization.confirmModal.total') }}
                    </dt>
                    <dd class="font-bold text-primary">
                        {{ formatMoney(amount + feeAmount, currency, locale) }}
                    </dd>
                </div>
            </dl>

            <p class="text-[11px] leading-relaxed text-muted-foreground">
                {{ t('monetization.confirmModal.termsNotice') }}
            </p>

            <DialogFooter class="flex-col-reverse gap-2 sm:flex-row">
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
