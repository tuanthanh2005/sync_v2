<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    ExternalLink,
    FileText,
    Plus,
    Save,
    Shield,
    Trash2,
} from '@lucide/vue';
import { ref } from 'vue';
import PolicyManagementController from '@/actions/App/Http/Controllers/Admin/PolicyManagementController';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { overview as adminOverview } from '@/routes/admin';
import { index as adminPolicies } from '@/routes/admin/policies';
import { show as showPolicy } from '@/routes/policies';
import type { AdminFooterLinkRecord, AdminPolicyRecord } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Quản trị hệ thống', href: adminOverview() },
            { title: 'Chính sách & Footer', href: adminPolicies() },
        ],
    },
});

const props = defineProps<{
    policies: AdminPolicyRecord[];
    footerLinks: AdminFooterLinkRecord[];
    footerGroups: Array<{ value: string; label: string }>;
    policyStatuses: Array<{ value: string; label: string }>;
}>();

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
        <Head title="Quản lý Chính sách & Footer" />

        <section class="rounded-xl border border-border bg-card p-6 shadow-xs">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <div
                        class="flex items-center gap-2 text-xs font-semibold text-primary"
                    >
                        <Shield class="size-4" aria-hidden="true" />
                        <span>Admin Only · Yêu cầu xác thực 2FA</span>
                    </div>
                    <h1 class="mt-1.5 text-xl font-bold text-foreground">
                        Quản lý Trang Chính sách & Liên kết Footer
                    </h1>
                    <p class="mt-1 text-sm text-muted-foreground">
                        Các chính sách chưa được phê duyệt phải giữ ở trạng thái
                        Bản nháp (Draft). Chỉ các trang đã Xuất bản và bật hiển
                        thị mới xuất hiện trên Footer công khai.
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
                    Danh sách trang chính sách ({{ policies.length }})
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
                                {{ policy.title_vi }}
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
                            Chỉnh sửa: /policies/{{ selectedPolicySlug }}
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
                            <span>Xem trang</span>
                        </Link>
                    </Button>
                </div>

                <form class="space-y-4" @submit.prevent="submitPolicy">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="grid gap-1.5">
                            <Label for="title_vi">Tiêu đề (Tiếng Việt)</Label>
                            <Input
                                id="title_vi"
                                v-model="policyForm.title_vi"
                                required
                            />
                        </div>
                        <div class="grid gap-1.5">
                            <Label for="title_en">Title (English)</Label>
                            <Input
                                id="title_en"
                                v-model="policyForm.title_en"
                                required
                            />
                        </div>
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="grid gap-1.5">
                            <Label for="summary_vi">Tóm tắt (VI)</Label>
                            <Input
                                id="summary_vi"
                                v-model="policyForm.summary_vi"
                            />
                        </div>
                        <div class="grid gap-1.5">
                            <Label for="summary_en">Summary (EN)</Label>
                            <Input
                                id="summary_en"
                                v-model="policyForm.summary_en"
                            />
                        </div>
                    </div>

                    <div class="grid gap-1.5">
                        <Label for="content_vi"
                            >Nội dung (Tiếng Việt — tự động lọc XSS ở
                            backend)</Label
                        >
                        <textarea
                            id="content_vi"
                            v-model="policyForm.content_vi"
                            rows="4"
                            class="w-full rounded-lg border border-input bg-transparent px-3 py-2 text-sm outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                        />
                    </div>

                    <div class="grid gap-1.5">
                        <Label for="content_en"
                            >Content (English — sanitized server-side)</Label
                        >
                        <textarea
                            id="content_en"
                            v-model="policyForm.content_en"
                            rows="4"
                            class="w-full rounded-lg border border-input bg-transparent px-3 py-2 text-sm outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                        />
                    </div>

                    <div class="grid gap-4 sm:grid-cols-4">
                        <div class="grid gap-1.5">
                            <Label for="policy_status">Trạng thái</Label>
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
                            <Label for="policy_group">Nhóm Footer</Label>
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
                            <Label for="policy_order">Thứ tự</Label>
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
                                <span>Hiển thị</span>
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
                        <span>Lưu chính sách</span>
                    </Button>
                </form>
            </div>
        </section>

        <!-- Footer Links Management Section -->
        <section class="rounded-xl border border-border bg-card p-5 shadow-xs">
            <h2 class="text-base font-semibold text-foreground">
                Quản lý thứ tự & hiển thị liên kết Footer
            </h2>
            <p class="mt-1 text-xs text-muted-foreground">
                Liên kết gắn với trang chính sách ở trạng thái Bản nháp sẽ tự
                động bị ẩn khỏi Footer công khai để tránh liên kết chết.
            </p>

            <div class="mt-4 space-y-2.5">
                <div
                    v-for="link in footerLinks"
                    :key="link.id"
                    class="grid items-center gap-2 rounded-lg border border-border p-3 md:grid-cols-12"
                >
                    <div class="md:col-span-3">
                        <Input v-model="link.label_vi" aria-label="Nhãn VI" />
                    </div>
                    <div class="md:col-span-3">
                        <Input v-model="link.label_en" aria-label="Nhãn EN" />
                    </div>
                    <div class="md:col-span-2">
                        <select
                            v-model="link.footer_group"
                            aria-label="Nhóm"
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
                            aria-label="Thứ tự"
                        />
                    </div>
                    <div class="flex items-center gap-2 md:col-span-2">
                        <label class="flex items-center gap-1.5 text-xs">
                            <input
                                v-model="link.is_visible"
                                type="checkbox"
                                class="size-4 rounded"
                            />
                            <span>Bật</span>
                        </label>
                        <Badge
                            v-if="link.policy_status"
                            variant="outline"
                            class="text-[10px]"
                        >
                            {{ link.policy_status }}
                        </Badge>
                    </div>
                    <div class="flex justify-end gap-1 md:col-span-1">
                        <Button
                            type="button"
                            variant="outline"
                            size="icon"
                            class="size-8 rounded-lg"
                            title="Lưu liên kết"
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
                            title="Xóa liên kết"
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
                    <Label class="text-xs">Nhãn mới (VI)</Label>
                    <Input
                        v-model="newLinkForm.label_vi"
                        required
                        placeholder="Trợ giúp"
                    />
                </div>
                <div class="grid gap-1 md:col-span-3">
                    <Label class="text-xs">New Label (EN)</Label>
                    <Input
                        v-model="newLinkForm.label_en"
                        required
                        placeholder="Support"
                    />
                </div>
                <div class="grid gap-1 md:col-span-3">
                    <Label class="text-xs"
                        >Đường dẫn (/... hoặc https://...)</Label
                    >
                    <Input
                        v-model="newLinkForm.url"
                        required
                        placeholder="/policies/help-center"
                    />
                </div>
                <div class="grid gap-1 md:col-span-1">
                    <Label class="text-xs">Thứ tự</Label>
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
                        <span>Thêm</span>
                    </Button>
                </div>
            </form>
        </section>
    </div>
</template>
