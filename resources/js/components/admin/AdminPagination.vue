<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ChevronLeft, ChevronRight } from '@lucide/vue';
import { useI18n } from 'vue-i18n';
import { Button } from '@/components/ui/button';

const props = defineProps<{
    currentPage: number;
    lastPage: number;
    total: number;
    from?: number | null;
    to?: number | null;
    baseUrl: string;
    query?: Record<string, string | number | null | undefined>;
}>();

const { t } = useI18n();

function goToPage(nextPage: number): void {
    if (
        nextPage < 1 ||
        nextPage > props.lastPage ||
        nextPage === props.currentPage
    ) {
        return;
    }

    const params: Record<string, string | number> = { page: nextPage };
    if (props.query) {
        for (const [k, v] of Object.entries(props.query)) {
            if (v !== null && v !== undefined && v !== '') {
                params[k] = v;
            }
        }
    }

    router.get(props.baseUrl, params, {
        preserveScroll: true,
        preserveState: true,
    });
}
</script>

<template>
    <div
        v-if="lastPage > 1 || total > 0"
        class="flex flex-wrap items-center justify-between gap-3 border-t border-border pt-4 text-xs text-muted-foreground"
    >
        <p v-if="from && to">
            {{ t('admin.common.showing', { from, to, total }) }}
        </p>
        <p v-else>
            {{ total }}
        </p>

        <div v-if="lastPage > 1" class="flex items-center gap-2">
            <Button
                type="button"
                variant="outline"
                size="sm"
                class="h-8 gap-1 rounded-lg text-xs"
                :disabled="currentPage <= 1"
                @click="goToPage(currentPage - 1)"
            >
                <ChevronLeft class="size-3.5" />
                <span>{{ t('admin.common.previous') }}</span>
            </Button>
            <span class="px-2 font-medium text-foreground">
                {{ currentPage }} / {{ lastPage }}
            </span>
            <Button
                type="button"
                variant="outline"
                size="sm"
                class="h-8 gap-1 rounded-lg text-xs"
                :disabled="currentPage >= lastPage"
                @click="goToPage(currentPage + 1)"
            >
                <span>{{ t('admin.common.next') }}</span>
                <ChevronRight class="size-3.5" />
            </Button>
        </div>
    </div>
</template>
