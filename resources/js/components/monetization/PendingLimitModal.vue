<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { AlertTriangle, ReceiptText } from '@lucide/vue';
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

const open = defineModel<boolean>('open', { default: false });

withDefaults(
    defineProps<{
        maxPending?: number;
    }>(),
    {
        maxPending: 5,
    },
);

const { t } = useI18n();
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
                        <AlertTriangle class="size-5 shrink-0 text-warning" />
                        <span>{{ t('monetization.pendingLimit.title') }}</span>
                    </DialogTitle>
                    <DialogDescription class="text-xs leading-relaxed">
                        {{
                            t('monetization.pendingLimit.description', {
                                max: maxPending,
                            })
                        }}
                    </DialogDescription>
                </DialogHeader>
            </div>

            <DialogFooter
                class="shrink-0 flex-col-reverse gap-2 bg-card/95 px-5 py-3.5 pb-[calc(0.875rem+env(safe-area-inset-bottom,0px))] sm:flex-row sm:px-6 sm:py-4"
            >
                <Button
                    type="button"
                    variant="outline"
                    class="min-h-11 rounded-xl sm:min-h-9"
                    @click="open = false"
                >
                    {{ t('common.close') }}
                </Button>
                <Button class="min-h-11 rounded-xl sm:min-h-9" as-child>
                    <Link href="/payments?status=pending" @click="open = false">
                        <ReceiptText class="size-4" />
                        <span>{{
                            t('monetization.pendingLimit.viewPending')
                        }}</span>
                    </Link>
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
