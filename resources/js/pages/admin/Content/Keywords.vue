<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { Plus, ShieldBan, Trash2 } from '@lucide/vue';
import { useI18n } from 'vue-i18n';
import EmptyState from '@/components/common/EmptyState.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    destroy as destroyKeywordRoute,
    store as storeKeywordRoute,
} from '@/routes/admin/content/keywords';

defineProps<{
    keywords: {
        data: Array<{
            id: number;
            keyword: string;
            match_type: string;
            severity: string;
            is_active: boolean;
            creator_username: string | null;
        }>;
    };
}>();

const { t } = useI18n();
const form = useForm({
    keyword: '',
    match_type: 'contains',
    severity: 'block',
});

function addKeyword(): void {
    form.post(storeKeywordRoute.url(), {
        preserveScroll: true,
        onSuccess: () => form.reset('keyword'),
    });
}

function removeKeyword(id: number): void {
    router.delete(destroyKeywordRoute.url(id), { preserveScroll: true });
}
</script>

<template>
    <div class="space-y-5">
        <Head :title="t('admin.content.keywordsTitle')" />
        <div class="rounded-xl border border-border bg-card p-5">
            <h1
                class="flex items-center gap-2 text-xl font-bold text-foreground"
            >
                <ShieldBan class="size-5 text-primary" />
                <span>{{ t('admin.content.keywordsTitle') }}</span>
            </h1>
            <p class="mt-1 text-sm text-muted-foreground">
                {{ t('admin.content.keywordsSubtitle') }}
            </p>
        </div>

        <form
            @submit.prevent="addKeyword"
            class="flex flex-wrap items-end gap-3 rounded-xl border border-border bg-card p-4 text-xs"
        >
            <div class="min-w-[220px] flex-1 space-y-1">
                <label class="font-medium text-foreground"
                    >Keyword / Domain</label
                >
                <Input
                    v-model="form.keyword"
                    placeholder="scam-domain.example"
                    class="h-9 text-xs"
                    required
                />
            </div>
            <div class="space-y-1">
                <label class="font-medium text-foreground">{{
                    t('admin.content.matchType')
                }}</label>
                <select
                    v-model="form.match_type"
                    class="h-9 rounded-lg border border-input bg-background px-3 text-xs"
                >
                    <option value="contains">Contains</option>
                    <option value="exact">Exact Word</option>
                    <option value="domain">Domain</option>
                </select>
            </div>
            <div class="space-y-1">
                <label class="font-medium text-foreground">{{
                    t('admin.content.severity')
                }}</label>
                <select
                    v-model="form.severity"
                    class="h-9 rounded-lg border border-input bg-background px-3 text-xs"
                >
                    <option value="block">Block Submission</option>
                    <option value="flag">Flag for Review</option>
                    <option value="mask">Auto-Mask (***)</option>
                </select>
            </div>
            <Button
                type="submit"
                size="sm"
                class="h-9 gap-1.5 rounded-lg text-xs"
                :disabled="form.processing"
            >
                <Plus class="size-3.5" />
                <span>{{ t('admin.content.addKeyword') }}</span>
            </Button>
        </form>

        <div class="rounded-xl border border-border bg-card p-4">
            <EmptyState
                v-if="keywords.data.length === 0"
                :title="t('admin.common.emptyTitle')"
                :description="t('admin.common.emptyDescription')"
            />
            <div v-else class="divide-y divide-border text-xs">
                <div
                    v-for="kw in keywords.data"
                    :key="kw.id"
                    class="flex items-center justify-between py-2.5"
                >
                    <div class="flex items-center gap-2">
                        <span class="font-mono font-semibold text-foreground">{{
                            kw.keyword
                        }}</span>
                        <Badge variant="outline" class="text-[10px]">{{
                            kw.match_type
                        }}</Badge>
                        <Badge
                            :variant="
                                kw.severity === 'block'
                                    ? 'destructive'
                                    : 'secondary'
                            "
                            class="text-[10px]"
                            >{{ kw.severity }}</Badge
                        >
                    </div>
                    <Button
                        size="sm"
                        variant="ghost"
                        class="h-7 px-2 text-destructive"
                        @click="removeKeyword(kw.id)"
                    >
                        <Trash2 class="size-3.5" />
                    </Button>
                </div>
            </div>
        </div>
    </div>
</template>
