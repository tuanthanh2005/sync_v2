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
        <DialogContent class="max-w-md rounded-2xl">
            <DialogHeader>
                <DialogTitle class="flex items-center gap-2 text-base">
                    <AlertTriangle class="size-5 text-warning" />
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

            <DialogFooter class="flex-col-reverse gap-2 sm:flex-row">
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
