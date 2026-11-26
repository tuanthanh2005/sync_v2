<script setup lang="ts">
import { Head, Link, router, setLayoutProps, useForm } from '@inertiajs/vue3';
import {
    ExternalLink,
    FileText,
    Plus,
    Save,
    Shield,
    Trash2,
} from '@lucide/vue';
import { ref, watchEffect } from 'vue';
import { useI18n } from 'vue-i18n';
import PolicyManagementController from '@/actions/App/Http/Controllers/Admin/PolicyManagementController';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { overview as adminOverview } from '@/routes/admin';
import { index as adminPolicies } from '@/routes/admin/policies';
import { show as showPolicy } from '@/routes/policies';
import type { AdminFooterLinkRecord, AdminPolicyRecord } from '@/types';

const props = defineProps<{
    policies: AdminPolicyRecord[];
    footerLinks: AdminFooterLinkRecord[];
    footerGroups: Array<{ value: string; label: string }>;
    policyStatuses: Array<{ value: string; label: string }>;
}>();

const { t, locale } = useI18n();

watchEffect(() => {
    setLayoutProps({
        breadcrumbs: [
            {
                title: t('admin.policiesCenter.breadcrumbAdmin'),
                href: adminOverview(),
            },
            {
                title: t('admin.policiesCenter.breadcrumbPolicies'),
                href: adminPolicies(),
            },
        ],
    });
});

function policyStatusLabel(status: string): string {
    if (status === 'published') {
        return t('admin.policiesCenter.statusPublished');
    }

    if (status === 'draft') {
        return t('admin.policiesCenter.statusDraft');
    }

    return status;
}

const selectedPolicySlug = ref<string>(props.policies[0]?.slug ?? '');

const policyForm = useForm<{
    title_vi: string;
    title_en: string;
    summary_vi: string;
    summary_en: string;
    content_vi: string;
    content_en: string;
    status: 'draft' | 'published';
    is_visible: boolean;
    footer_group: 'platform' | 'legal' | 'support';
    sort_order: number;
}>({
    title_vi: props.policies[0]?.title_vi ?? '',
    title_en: props.policies[0]?.title_en ?? '',
    summary_vi: props.policies[0]?.summary_vi ?? '',
    summary_en: props.policies[0]?.summary_en ?? '',
    content_vi: props.policies[0]?.content_vi ?? '',
    content_en: props.policies[0]?.content_en ?? '',
    status: props.policies[0]?.status ?? 'draft',
    is_visible: props.policies[0]?.is_visible ?? false,
    footer_group: props.policies[0]?.footer_group ?? 'legal',
    sort_order: props.policies[0]?.sort_order ?? 10,
});

function selectPolicy(policy: AdminPolicyRecord): void {
    selectedPolicySlug.value = policy.slug;
    policyForm.title_vi = policy.title_vi;
    policyForm.title_en = policy.title_en;
    policyForm.summary_vi = policy.summary_vi ?? '';
    policyForm.summary_en = policy.summary_en ?? '';
    policyForm.content_vi = policy.content_vi ?? '';
    policyForm.content_en = policy.content_en ?? '';
    policyForm.status = policy.status;
    policyForm.is_visible = policy.is_visible;
    policyForm.footer_group = policy.footer_group;
    policyForm.sort_order = policy.sort_order;
    policyForm.clearErrors();
}

function submitPolicy(): void {
    if (!selectedPolicySlug.value) {
        return;
    }

    policyForm.patch(
        PolicyManagementController.updatePolicy.url(selectedPolicySlug.value),
        {
            preserveScroll: true,
        },
    );
}

const newLinkForm = useForm({
    label_vi: '',
    label_en: '',
    url: '',
    footer_group: 'support' as 'platform' | 'legal' | 'support',
    sort_order: 110,
    is_visible: false,
    open_in_new_tab: false,
});

function createFooterLink(): void {
    newLinkForm.post(PolicyManagementController.storeLink.url(), {
        preserveScroll: true,
        onSuccess: () => newLinkForm.reset('label_vi', 'label_en', 'url'),
    });
}

function updateLinkItem(link: AdminFooterLinkRecord): void {
    router.patch(
        PolicyManagementController.updateLink.url(link.id),
        {
            label_vi: link.label_vi,
            label_en: link.label_en,
            url: link.url,
            footer_group: link.footer_group,
            sort_order: Number(link.sort_order),
            is_visible: link.is_visible,
            open_in_new_tab: link.open_in_new_tab,
        },
        { preserveScroll: true },
    );
}

function deleteLinkItem(link: AdminFooterLinkRecord): void {
    router.delete(PolicyManagementController.destroyLink.url(link.id), {
        preserveScroll: true,
    });
}
</script>

<template>
    <div class="space-y-6">
        <Head :title="t('admin.policiesCenter.headTitle')" />

        <section class="rounded-xl border border-border bg-card p-6 shadow-xs">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <div
                        class="flex items-center gap-2 text-xs font-semibold text-primary"
                    >
                        <Shield class="size-4" aria-hidden="true" />
                        <span>{{
                            t('admin.policiesCenter.adminOnlyBadge')
                        }}</span>
                    </div>
                    <h1 class="mt-1.5 text-xl font-bold text-foreground">
                        {{ t('admin.policiesCenter.heading') }}
                    </h1>
                    <p class="mt-1 text-sm text-muted-foreground">
                        {{ t('admin.policiesCenter.subtitle') }}
                    </p>
                </div>
            </div>
        </section>

        <!-- Policy Editor Section -->
        <section class="grid gap-5 lg:grid-cols-12">
            <div
                class="space-y-2 rounded-xl border border-border bg-card p-4 shadow-xs lg:col-span-4"
            >
                <h2 class="px-1 text-sm font-semibold text-foreground">
                    {{
                        t('admin.policiesCenter.policyListHeading', {
                            count: policies.length,
                        })
                    }}
                </h2>
                <div class="space-y-1.5 pt-1">
                    <button
                        v-for="policy in policies"
                        :key="policy.id"
                        type="button"
                        :class="[
                            'flex w-full items-center justify-between gap-2 rounded-lg border px-3 py-2.5 text-left text-xs transition-colors',
                            selectedPolicySlug === policy.slug
                                ? 'border-primary/40 bg-accent text-accent-foreground'
                                : 'border-transparent hover:bg-muted',
                        ]"
                        @click="selectPolicy(policy)"
                    >
                        <div class="min-w-0">
                            <p class="truncate font-semibold">
                                {{
                                    locale.startsWith('vi')
                                        ? policy.title_vi
                                        : policy.title_en || policy.title_vi
                                }}
                            </p>
                            <p
                                class="truncate text-[11px] text-muted-foreground"
                            >
                                /policies/{{ policy.slug }}
                            </p>
                        </div>
                        <Badge
                            :variant="
                                policy.status === 'published'
                                    ? 'secondary'
                                    : 'outline'
                            "
                            class="shrink-0 text-[10px]"
                        >
                            {{ policy.status_label }}
                        </Badge>
                    </button>
                </div>
            </div>

            <div
                class="rounded-xl border border-border bg-card p-5 shadow-xs lg:col-span-8"
            >
                <div
                    class="mb-4 flex flex-wrap items-center justify-between gap-2 border-b border-border pb-3"
                >
                    <div class="flex items-center gap-2">
                        <FileText
                            class="size-4 text-primary"
                            aria-hidden="true"
                        />
                        <h2 class="text-sm font-semibold text-foreground">
                            {{
                                t('admin.policiesCenter.editingPath', {
                                    slug: selectedPolicySlug,
                                })
                            }}
                        </h2>
                    </div>
                    <Button
                        v-if="selectedPolicySlug"
                        variant="outline"
                        size="sm"
                        class="h-8 rounded-lg text-xs"
                        as-child
                    >
                        <Link :href="showPolicy(selectedPolicySlug)">
                            <ExternalLink class="size-3.5" />
                            <span>{{
                                t('admin.policiesCenter.viewPage')
                            }}</span>
                        </Link>
                    </Button>
                </div>

                <form class="space-y-4" @submit.prevent="submitPolicy">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="grid gap-1.5">
                            <Label for="title_vi">{{
                                t('admin.policiesCenter.titleViLabel')
                            }}</Label>
                            <Input
                                id="title_vi"
                                v-model="policyForm.title_vi"
                                required
                            />
                        </div>
                        <div class="grid gap-1.5">
                            <Label for="title_en">{{
                                t('admin.policiesCenter.titleEnLabel')
                            }}</Label>
                            <Input
                                id="title_en"
                                v-model="policyForm.title_en"
                                required
                            />
                        </div>
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="grid gap-1.5">
                            <Label for="summary_vi">{{
                                t('admin.policiesCenter.summaryViLabel')
                            }}</Label>
                            <Input
                                id="summary_vi"
                                v-model="policyForm.summary_vi"
                            />
                        </div>
                        <div class="grid gap-1.5">
                            <Label for="summary_en">{{
                                t('admin.policiesCenter.summaryEnLabel')
                            }}</Label>
                            <Input
                                id="summary_en"
                                v-model="policyForm.summary_en"
                            />
                        </div>
                    </div>

                    <div class="grid gap-1.5">
                        <Label for="content_vi">{{
                            t('admin.policiesCenter.contentViLabel')
                        }}</Label>
                        <textarea
                            id="content_vi"
                            v-model="policyForm.content_vi"
                            rows="4"
                            class="w-full rounded-lg border border-input bg-transparent px-3 py-2 text-sm outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                        />
                    </div>

                    <div class="grid gap-1.5">
                        <Label for="content_en">{{
                            t('admin.policiesCenter.contentEnLabel')
                        }}</Label>
                        <textarea
                            id="content_en"
                            v-model="policyForm.content_en"
                            rows="4"
                            class="w-full rounded-lg border border-input bg-transparent px-3 py-2 text-sm outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                        />
                    </div>

                    <div class="grid gap-4 sm:grid-cols-4">
                        <div class="grid gap-1.5">
                            <Label for="policy_status">{{
                                t('admin.policiesCenter.statusLabel')
                            }}</Label>
                            <select
                                id="policy_status"
                                v-model="policyForm.status"
                                class="h-9 rounded-md border border-input bg-transparent px-3 text-sm dark:bg-card"
                            >
                                <option
                                    v-for="st in policyStatuses"
                                    :key="st.value"
                                    :value="st.value"
                                >
                                    {{ st.label }}
                                </option>
                            </select>
                        </div>
                        <div class="grid gap-1.5">
                            <Label for="policy_group">{{
                                t('admin.policiesCenter.footerGroupLabel')
                            }}</Label>
                            <select
                                id="policy_group"
                                v-model="policyForm.footer_group"
                                class="h-9 rounded-md border border-input bg-transparent px-3 text-sm dark:bg-card"
                            >
                                <option
                                    v-for="grp in footerGroups"
                                    :key="grp.value"
                                    :value="grp.value"
                                >
                                    {{ grp.label }}
                                </option>
                            </select>
                        </div>
                        <div class="grid gap-1.5">
                            <Label for="policy_order">{{
                                t('admin.policiesCenter.sortOrderLabel')
                            }}</Label>
                            <Input
                                id="policy_order"
                                v-model.number="policyForm.sort_order"
                                type="number"
                                min="0"
                                max="9999"
                            />
                        </div>
                        <div class="flex items-end pb-1.5">
                            <label class="flex items-center gap-2 text-sm">
                                <input
                                    v-model="policyForm.is_visible"
                                    type="checkbox"
                                    class="size-4 rounded border-input"
                                />
                                <span>{{
                                    t('admin.policiesCenter.visibleLabel')
                                }}</span>
                            </label>
                        </div>
                    </div>

                    <Button
                        type="submit"
                        class="rounded-lg"
                        :disabled="policyForm.processing"
                        data-test="save-policy-button"
                    >
                        <Save class="size-4" />
                        <span>{{ t('admin.policiesCenter.savePolicy') }}</span>
                    </Button>
                </form>
            </div>
        </section>

        <!-- Footer Links Management Section -->
        <section class="rounded-xl border border-border bg-card p-5 shadow-xs">
            <h2 class="text-base font-semibold text-foreground">
                {{ t('admin.policiesCenter.footerLinksHeading') }}
            </h2>
            <p class="mt-1 text-xs text-muted-foreground">
                {{ t('admin.policiesCenter.footerLinksHint') }}
            </p>

            <div class="mt-4 space-y-2.5">
                <div
                    v-for="link in footerLinks"
                    :key="link.id"
                    class="grid items-center gap-2 rounded-lg border border-border p-3 md:grid-cols-12"
                >
                    <div class="md:col-span-3">
                        <Input
                            v-model="link.label_vi"
                            :aria-label="t('admin.policiesCenter.labelViAria')"
                        />
                    </div>
                    <div class="md:col-span-3">
                        <Input
                            v-model="link.label_en"
                            :aria-label="t('admin.policiesCenter.labelEnAria')"
                        />
                    </div>
                    <div class="md:col-span-2">
                        <select
                            v-model="link.footer_group"
                            :aria-label="t('admin.policiesCenter.groupAria')"
                            class="h-9 w-full rounded-md border border-input bg-transparent px-2.5 text-xs dark:bg-card"
                        >
                            <option
                                v-for="grp in footerGroups"
                                :key="grp.value"
                                :value="grp.value"
                            >
                                {{ grp.label }}
                            </option>
                        </select>
                    </div>
                    <div class="md:col-span-1">
                        <Input
                            v-model.number="link.sort_order"
                            type="number"
                            min="0"
                            :aria-label="
                                t('admin.policiesCenter.sortOrderAria')
                            "
                        />
                    </div>
                    <div class="flex items-center gap-2 md:col-span-2">
                        <label class="flex items-center gap-1.5 text-xs">
                            <input
                                v-model="link.is_visible"
                                type="checkbox"
                                class="size-4 rounded"
                            />
                            <span>{{
                                t('admin.policiesCenter.enabledToggle')
                            }}</span>
                        </label>
                        <Badge
                            v-if="link.policy_status"
                            variant="outline"
                            class="text-[10px]"
                        >
                            {{ policyStatusLabel(link.policy_status) }}
                        </Badge>
                    </div>
                    <div class="flex justify-end gap-1 md:col-span-1">
                        <Button
                            type="button"
                            variant="outline"
                            size="icon"
                            class="size-8 rounded-lg"
                            :title="t('admin.policiesCenter.saveLinkTitle')"
                            :aria-label="
                                t('admin.policiesCenter.saveLinkTitle')
                            "
                            @click="updateLinkItem(link)"
                        >
                            <Save class="size-3.5" />
                        </Button>
                        <Button
                            v-if="!link.policy_page_id"
                            type="button"
                            variant="ghost"
                            size="icon"
                            class="size-8 rounded-lg text-destructive"
                            :title="t('admin.policiesCenter.deleteLinkTitle')"
                            :aria-label="
                                t('admin.policiesCenter.deleteLinkTitle')
                            "
                            @click="deleteLinkItem(link)"
                        >
                            <Trash2 class="size-3.5" />
                        </Button>
                    </div>
                </div>
            </div>

            <!-- Add Custom Footer Link -->
            <form
                class="mt-6 grid items-end gap-3 rounded-lg border border-dashed border-border p-4 md:grid-cols-12"
                @submit.prevent="createFooterLink"
            >
                <div class="grid gap-1 md:col-span-3">
                    <Label class="text-xs">{{
                        t('admin.policiesCenter.newLabelVi')
                    }}</Label>
                    <Input
                        v-model="newLinkForm.label_vi"
                        required
                        :placeholder="
                            t('admin.policiesCenter.newLabelViPlaceholder')
                        "
                    />
                </div>
                <div class="grid gap-1 md:col-span-3">
                    <Label class="text-xs">{{
                        t('admin.policiesCenter.newLabelEn')
                    }}</Label>
                    <Input
                        v-model="newLinkForm.label_en"
                        required
                        :placeholder="
                            t('admin.policiesCenter.newLabelEnPlaceholder')
                        "
                    />
                </div>
                <div class="grid gap-1 md:col-span-3">
                    <Label class="text-xs">{{
                        t('admin.policiesCenter.urlLabel')
                    }}</Label>
                    <Input
                        v-model="newLinkForm.url"
                        required
                        :placeholder="t('admin.policiesCenter.urlPlaceholder')"
                    />
                </div>
                <div class="grid gap-1 md:col-span-1">
                    <Label class="text-xs">{{
                        t('admin.policiesCenter.sortOrderLabel')
                    }}</Label>
                    <Input
                        v-model.number="newLinkForm.sort_order"
                        type="number"
                        min="0"
                    />
                </div>
                <div class="md:col-span-2">
                    <Button
                        type="submit"
                        class="w-full rounded-lg"
                        :disabled="newLinkForm.processing"
                    >
                        <Plus class="size-4" />
                        <span>{{ t('admin.policiesCenter.addLink') }}</span>
                    </Button>
                </div>
            </form>
        </section>
    </div>
</template>
