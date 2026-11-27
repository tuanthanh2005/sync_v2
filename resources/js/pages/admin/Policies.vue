<script setup lang="ts">
import {
    Head,
    Link,
    router,
    setLayoutProps,
    useForm,
    usePage,
} from '@inertiajs/vue3';
import {
    Bold,
    CheckCircle2,
    ChevronDown,
    Eye,
    FilePlus2,
    FileText,
    Globe,
    Heading2,
    Heading3,
    History,
    ImagePlus,
    Italic,
    Link2,
    List,
    Mail,
    Plus,
    Quote,
    RotateCcw,
    Save,
    Search,
    Shield,
    Sparkles,
    Trash2,
} from '@lucide/vue';
import { computed, ref, watch, watchEffect } from 'vue';
import { useI18n } from 'vue-i18n';
import PolicyManagementController from '@/actions/App/Http/Controllers/Admin/PolicyManagementController';
import AutoHideScrollArea from '@/components/common/AutoHideScrollArea.vue';
import ConfirmActionDialog from '@/components/common/ConfirmActionDialog.vue';
import InputError from '@/components/InputError.vue';
import ImageUploader from '@/components/upload/ImageUploader.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { appToast } from '@/lib/toast';
import { overview as adminOverview } from '@/routes/admin';
import { index as adminPolicies } from '@/routes/admin/policies';
import { show as showPolicy } from '@/routes/policies';
import type {
    AdminFooterLinkRecord,
    AdminPolicyRecord,
    AdminPolicyRevisionRecord,
    FooterGroupValue,
    PublicLanguageItem,
} from '@/types';

const props = defineProps<{
    policies: AdminPolicyRecord[];
    footerLinks: AdminFooterLinkRecord[];
    footerGroups: Array<{ value: string; label: string }>;
    policyStatuses: Array<{ value: string; label: string }>;
    pageTypes: string[];
    availableLanguages: PublicLanguageItem[];
    footerSettings: {
        contact_email: string;
        support_contact_info: string;
        footer_copyright_text: string;
    };
}>();

const { t, locale } = useI18n();
const page = usePage();
const brandName = computed(() => page.props.name);

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

// ─── Workspace Tabs & Filters ────────────────────────────────────────────────
const activeSection = ref<'pages' | 'footer'>('pages');
const editorSubTab = ref<'content' | 'seo' | 'revisions'>('content');
const activeEditorLocale = ref<string>('en');
const showHtmlPreview = ref(false);
const pageSearch = ref('');
const pageTypeFilter = ref<string>('all');
const pageStatusFilter = ref<string>('all');

const selectedPolicySlug = ref<string>(props.policies[0]?.slug ?? '');

const selectedPolicy = computed<AdminPolicyRecord | null>(
    () =>
        props.policies.find((p) => p.slug === selectedPolicySlug.value) ??
        props.policies[0] ??
        null,
);

const filteredPolicies = computed(() => {
    const q = pageSearch.value.trim().toLowerCase();

    return props.policies.filter((item) => {
        if (
            pageTypeFilter.value !== 'all' &&
            item.page_type !== pageTypeFilter.value
        ) {
            return false;
        }
        if (
            pageStatusFilter.value !== 'all' &&
            item.status !== pageStatusFilter.value
        ) {
            return false;
        }
        if (q === '') {
            return true;
        }

        return (
            item.slug.toLowerCase().includes(q) ||
            item.title_en.toLowerCase().includes(q) ||
            item.title_vi.toLowerCase().includes(q)
        );
    });
});

function policyStatusLabel(status: string): string {
    if (status === 'published') {
        return t('admin.policiesCenter.statusPublished');
    }
    if (status === 'archived') {
        return t('admin.cms.statusArchived');
    }
    if (status === 'draft') {
        return t('admin.policiesCenter.statusDraft');
    }

    return status;
}

function pageTypeLabel(type: string): string {
    return t(`admin.cms.pageTypes.${type}`);
}

function translationBadgeCount(policy: AdminPolicyRecord): number {
    const published = new Set<string>();
    if (policy.content_en && policy.content_en.trim() !== '') {
        published.add('en');
    }
    if (policy.content_vi && policy.content_vi.trim() !== '') {
        published.add('vi');
    }
    for (const [code, tr] of Object.entries(policy.translations ?? {})) {
        if (
            tr.status === 'published' &&
            tr.content &&
            tr.content.trim() !== ''
        ) {
            published.add(code);
        }
    }

    return published.size;
}

function localeHasPublishedCopy(
    policy: AdminPolicyRecord,
    code: string,
): boolean {
    const tr = policy.translations?.[code];
    if (
        tr &&
        tr.status === 'published' &&
        tr.content &&
        tr.content.trim() !== ''
    ) {
        return true;
    }
    if (code === 'en') {
        return Boolean(policy.content_en && policy.content_en.trim() !== '');
    }
    if (code === 'vi') {
        return Boolean(policy.content_vi && policy.content_vi.trim() !== '');
    }

    return false;
}

// ─── Primary Policy Edit Form ────────────────────────────────────────────────
const policyForm = useForm<{
    page_type: 'company' | 'support' | 'legal' | 'business' | 'general';
    title_vi: string;
    title_en: string;
    summary_vi: string;
    summary_en: string;
    content_vi: string;
    content_en: string;
    seo_title: string;
    meta_description: string;
    canonical_url: string;
    og_image: File | null;
    remove_og_image: boolean;
    index_eligible: boolean;
    status: 'draft' | 'published' | 'archived';
    is_visible: boolean;
    footer_group: FooterGroupValue;
    placement: 'main' | 'more';
    sort_order: number;
    effective_at: string;
    change_note: string;
}>({
    page_type: props.policies[0]?.page_type ?? 'legal',
    title_vi: props.policies[0]?.title_vi ?? '',
    title_en: props.policies[0]?.title_en ?? '',
    summary_vi: props.policies[0]?.summary_vi ?? '',
    summary_en: props.policies[0]?.summary_en ?? '',
    content_vi: props.policies[0]?.content_vi ?? '',
    content_en: props.policies[0]?.content_en ?? '',
    seo_title: props.policies[0]?.seo_title ?? '',
    meta_description: props.policies[0]?.meta_description ?? '',
    canonical_url: props.policies[0]?.canonical_url ?? '',
    og_image: null,
    remove_og_image: false,
    index_eligible: props.policies[0]?.index_eligible ?? true,
    status: props.policies[0]?.status ?? 'draft',
    is_visible: props.policies[0]?.is_visible ?? false,
    footer_group: props.policies[0]?.footer_group ?? 'legal',
    placement: props.policies[0]?.placement ?? 'more',
    sort_order: props.policies[0]?.sort_order ?? 10,
    effective_at: props.policies[0]?.effective_at ?? '',
    change_note: '',
});

const translationForm = useForm<{
    locale: string;
    title: string;
    summary: string;
    content: string;
    seo_title: string;
    meta_description: string;
    status: 'draft' | 'published';
    change_note: string;
}>({
    locale: 'es',
    title: '',
    summary: '',
    content: '',
    seo_title: '',
    meta_description: '',
    status: 'draft',
    change_note: '',
});

function loadPolicyIntoForms(policy: AdminPolicyRecord): void {
    policyForm.page_type = policy.page_type ?? 'legal';
    policyForm.title_vi = policy.title_vi;
    policyForm.title_en = policy.title_en;
    policyForm.summary_vi = policy.summary_vi ?? '';
    policyForm.summary_en = policy.summary_en ?? '';
    policyForm.content_vi = policy.content_vi ?? '';
    policyForm.content_en = policy.content_en ?? '';
    policyForm.seo_title = policy.seo_title ?? '';
    policyForm.meta_description = policy.meta_description ?? '';
    policyForm.canonical_url = policy.canonical_url ?? '';
    policyForm.og_image = null;
    policyForm.remove_og_image = false;
    policyForm.index_eligible = policy.index_eligible ?? true;
    policyForm.status = policy.status;
    policyForm.is_visible = policy.is_visible;
    policyForm.footer_group = policy.footer_group;
    policyForm.placement = policy.placement ?? 'more';
    policyForm.sort_order = policy.sort_order;
    policyForm.effective_at = policy.effective_at ?? '';
    policyForm.change_note = '';
    policyForm.clearErrors();

    loadTranslationForLocale(policy, activeEditorLocale.value);
}

function loadTranslationForLocale(
    policy: AdminPolicyRecord,
    code: string,
): void {
    const existing = policy.translations?.[code];
    translationForm.locale = code;
    translationForm.title = existing?.title ?? '';
    translationForm.summary = existing?.summary ?? '';
    translationForm.content = existing?.content ?? '';
    translationForm.seo_title = existing?.seo_title ?? '';
    translationForm.meta_description = existing?.meta_description ?? '';
    translationForm.status = existing?.status ?? 'draft';
    translationForm.change_note = '';
    translationForm.clearErrors();
}

function selectPolicy(policy: AdminPolicyRecord): void {
    selectedPolicySlug.value = policy.slug;
    showHtmlPreview.value = false;
    loadPolicyIntoForms(policy);
}

function switchEditorLocale(code: string): void {
    activeEditorLocale.value = code;
    showHtmlPreview.value = false;
    if (selectedPolicy.value && code !== 'en' && code !== 'vi') {
        loadTranslationForLocale(selectedPolicy.value, code);
    }
}

watch(
    () => props.policies,
    (next) => {
        const current = next.find((p) => p.slug === selectedPolicySlug.value);
        if (current) {
            loadPolicyIntoForms(current);
        } else if (next[0]) {
            selectedPolicySlug.value = next[0].slug;
            loadPolicyIntoForms(next[0]);
        }
    },
);

function submitPolicy(): void {
    if (!selectedPolicySlug.value) {
        return;
    }

    policyForm
        .transform((data) => ({
            ...data,
            _method: 'patch',
        }))
        .post(
            PolicyManagementController.updatePolicy.url(
                selectedPolicySlug.value,
            ),
            {
                forceFormData: true,
                preserveScroll: true,
                onSuccess: () => {
                    policyForm.og_image = null;
                    policyForm.change_note = '';
                },
            },
        );
}

function submitTranslation(): void {
    if (!selectedPolicySlug.value) {
        return;
    }

    translationForm.post(
        PolicyManagementController.saveTranslation.url(
            selectedPolicySlug.value,
        ),
        {
            preserveScroll: true,
            onSuccess: () => {
                translationForm.change_note = '';
            },
        },
    );
}

// ─── Rich Formatting Helpers & Inline Device Image Upload ────────────────────
const inlineImageModalOpen = ref(false);
const inlineImageFile = ref<File | null>(null);
const inlineImageAlt = ref('');
const inlineImageUploading = ref(false);

function activeContentValue(): string {
    if (activeEditorLocale.value === 'en') {
        return policyForm.content_en;
    }
    if (activeEditorLocale.value === 'vi') {
        return policyForm.content_vi;
    }

    return translationForm.content;
}

function setActiveContentValue(next: string): void {
    if (activeEditorLocale.value === 'en') {
        policyForm.content_en = next;
    } else if (activeEditorLocale.value === 'vi') {
        policyForm.content_vi = next;
    } else {
        translationForm.content = next;
    }
}

function appendMarkup(snippet: string): void {
    const current = activeContentValue();
    const sep = current.trim() === '' ? '' : '\n';
    setActiveContentValue(`${current}${sep}${snippet}`);
}

function insertFormat(
    kind: 'h2' | 'h3' | 'bold' | 'italic' | 'list' | 'quote',
): void {
    const map: Record<typeof kind, string> = {
        h2: '<h2>Heading</h2>',
        h3: '<h3>Subheading</h3>',
        bold: '<p><strong>Bold</strong></p>',
        italic: '<p><em>Italic</em></p>',
        list: '<ul>\n  <li>Item 1</li>\n  <li>Item 2</li>\n</ul>',
        quote: '<blockquote>Notice</blockquote>',
    };
    appendMarkup(map[kind]);
}

function readCsrfToken(): string {
    const meta = document.querySelector<HTMLMetaElement>(
        'meta[name="csrf-token"]',
    );
    if (meta?.content) {
        return meta.content;
    }
    const match = document.cookie.match(/XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
}

async function uploadAndInsertInlineImage(): Promise<void> {
    if (!inlineImageFile.value) {
        return;
    }

    inlineImageUploading.value = true;

    try {
        const formData = new FormData();
        formData.append('image', inlineImageFile.value);

        const response = await fetch(
            PolicyManagementController.uploadImage.url(),
            {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': readCsrfToken(),
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
                body: formData,
            },
        );

        if (!response.ok) {
            const payload = (await response.json().catch(() => null)) as {
                message?: string;
            } | null;
            appToast.error(payload?.message || t('upload.errors.uploadFailed'));

            return;
        }

        const data = (await response.json()) as {
            url: string;
            width: number;
            height: number;
        };

        const safeAlt = inlineImageAlt.value.replace(/"/g, '&quot;').trim();
        appendMarkup(
            `<p><img src="${data.url}" alt="${safeAlt}" width="${data.width}" height="${data.height}" /></p>`,
        );

        inlineImageFile.value = null;
        inlineImageAlt.value = '';
        inlineImageModalOpen.value = false;
        appToast.success(t('admin.cms.inlineImageInserted'));
    } catch {
        appToast.error(t('upload.errors.uploadFailed'));
    } finally {
        inlineImageUploading.value = false;
    }
}

// ─── Create New CMS Page Modal ───────────────────────────────────────────────
const createModalOpen = ref(false);
const createForm = useForm<{
    slug: string;
    page_type: 'company' | 'support' | 'legal' | 'business' | 'general';
    title_en: string;
    title_vi: string;
    summary_en: string;
    summary_vi: string;
    status: 'draft' | 'published';
    is_visible: boolean;
    footer_group: FooterGroupValue;
    placement: 'main' | 'more';
    sort_order: number;
}>({
    slug: '',
    page_type: 'general',
    title_en: '',
    title_vi: '',
    summary_en: '',
    summary_vi: '',
    status: 'draft',
    is_visible: false,
    footer_group: 'legal',
    placement: 'more',
    sort_order: 210,
});

function autoSlugFromTitle(): void {
    if (createForm.slug.trim() !== '') {
        return;
    }
    createForm.slug = createForm.title_en
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '')
        .slice(0, 80);
}

function submitCreatePolicy(): void {
    const nextSlug = createForm.slug.trim();
    createForm.post(PolicyManagementController.storePolicy.url(), {
        preserveScroll: true,
        onSuccess: () => {
            createModalOpen.value = false;
            if (nextSlug) {
                selectedPolicySlug.value = nextSlug;
            }
            createForm.reset();
        },
    });
}

// ─── Revision Restore Dialog ─────────────────────────────────────────────────
const pendingRestoreRevision = ref<AdminPolicyRevisionRecord | null>(null);
const restoreConfirmOpen = ref(false);
const restoringRevision = ref(false);

function promptRestoreRevision(rev: AdminPolicyRevisionRecord): void {
    pendingRestoreRevision.value = rev;
    restoreConfirmOpen.value = true;
}

function confirmRestoreRevision(): void {
    if (!selectedPolicySlug.value || !pendingRestoreRevision.value) {
        return;
    }
    restoringRevision.value = true;
    router.post(
        PolicyManagementController.restoreRevision.url({
            policyPage: selectedPolicySlug.value,
            revision: pendingRestoreRevision.value.id,
        }),
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                restoringRevision.value = false;
                restoreConfirmOpen.value = false;
                pendingRestoreRevision.value = null;
            },
        },
    );
}

// ─── Footer Links & Branding Settings ────────────────────────────────────────
const footerSettingsForm = useForm({
    contact_email: props.footerSettings.contact_email ?? '',
    support_contact_info: props.footerSettings.support_contact_info ?? '',
    footer_copyright_text: props.footerSettings.footer_copyright_text ?? '',
});

function saveFooterSettings(): void {
    footerSettingsForm.post(
        PolicyManagementController.updateFooterSettings.url(),
        {
            preserveScroll: true,
        },
    );
}

const newLinkForm = useForm({
    label_vi: '',
    label_en: '',
    url: '',
    footer_group: 'support' as FooterGroupValue,
    placement: 'more' as 'main' | 'more',
    sort_order: 220,
    is_visible: true,
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
            placement: link.placement,
            sort_order: Number(link.sort_order),
            is_visible: link.is_visible,
            open_in_new_tab: link.open_in_new_tab,
        },
        { preserveScroll: true },
    );
}

const pendingDeleteLink = ref<AdminFooterLinkRecord | null>(null);
const deleteLinkDialogOpen = ref(false);
const deletingLink = ref(false);

function promptDeleteLink(link: AdminFooterLinkRecord): void {
    pendingDeleteLink.value = link;
    deleteLinkDialogOpen.value = true;
}

function confirmDeleteLink(): void {
    if (!pendingDeleteLink.value) {
        return;
    }
    deletingLink.value = true;
    router.delete(
        PolicyManagementController.destroyLink.url(pendingDeleteLink.value.id),
        {
            preserveScroll: true,
            onFinish: () => {
                deletingLink.value = false;
                deleteLinkDialogOpen.value = false;
                pendingDeleteLink.value = null;
            },
        },
    );
}

// Live Footer Preview computed from current links
const previewActiveLinks = computed(() =>
    props.footerLinks.filter(
        (item) =>
            item.is_visible &&
            (!item.policy_page_id || item.policy_status === 'published'),
    ),
);
const previewMainLinks = computed(() =>
    previewActiveLinks.value.filter((item) => item.placement === 'main'),
);
const previewMoreLinks = computed(() =>
    previewActiveLinks.value.filter((item) => item.placement === 'more'),
);
</script>

<template>
    <div class="space-y-6">
        <Head :title="t('admin.policiesCenter.headTitle')" />

        <!-- Page Header -->
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

                <div class="flex flex-wrap items-center gap-2">
                    <Button
                        type="button"
                        size="sm"
                        class="rounded-lg"
                        @click="createModalOpen = true"
                    >
                        <FilePlus2 class="size-4" aria-hidden="true" />
                        <span>{{ t('admin.cms.createPage') }}</span>
                    </Button>
                </div>
            </div>

            <!-- Section Tabs -->
            <div class="mt-5 flex flex-wrap gap-2 border-t border-border pt-4">
                <Button
                    type="button"
                    size="sm"
                    :variant="activeSection === 'pages' ? 'secondary' : 'ghost'"
                    class="rounded-lg text-xs"
                    @click="activeSection = 'pages'"
                >
                    <FileText class="size-3.5" aria-hidden="true" />
                    <span>
                        {{
                            t('admin.cms.tabs.pages', {
                                count: policies.length,
                            })
                        }}
                    </span>
                </Button>
                <Button
                    type="button"
                    size="sm"
                    :variant="
                        activeSection === 'footer' ? 'secondary' : 'ghost'
                    "
                    class="rounded-lg text-xs"
                    @click="activeSection = 'footer'"
                >
                    <Link2 class="size-3.5" aria-hidden="true" />
                    <span>
                        {{
                            t('admin.cms.tabs.footer', {
                                count: previewActiveLinks.length,
                            })
                        }}
                    </span>
                </Button>
            </div>
        </section>

        <!-- TAB 1: CMS PAGES MANAGER -->
        <section
            v-if="activeSection === 'pages'"
            class="grid gap-5 lg:grid-cols-12"
        >
            <!-- Left: Searchable & Filterable Page List -->
            <div
                class="space-y-3 rounded-xl border border-border bg-card p-4 shadow-xs lg:col-span-4"
            >
                <div class="flex items-center justify-between gap-2 px-1">
                    <h2 class="text-sm font-semibold text-foreground">
                        {{
                            t('admin.policiesCenter.policyListHeading', {
                                count: filteredPolicies.length,
                            })
                        }}
                    </h2>
                </div>

                <div class="space-y-2">
                    <div
                        class="flex items-center gap-2 rounded-lg border border-input bg-background px-2.5 py-1.5"
                    >
                        <Search
                            class="size-3.5 shrink-0 text-muted-foreground"
                            aria-hidden="true"
                        />
                        <input
                            v-model="pageSearch"
                            type="search"
                            :placeholder="t('admin.cms.searchPagesPlaceholder')"
                            class="w-full bg-transparent text-xs outline-none"
                        />
                    </div>

                    <div class="grid grid-cols-2 gap-2">
                        <select
                            v-model="pageTypeFilter"
                            class="h-8 rounded-md border border-input bg-background px-2 text-xs"
                        >
                            <option value="all">
                                {{ t('admin.cms.allTypes') }}
                            </option>
                            <option
                                v-for="pt in pageTypes"
                                :key="pt"
                                :value="pt"
                            >
                                {{ pageTypeLabel(pt) }}
                            </option>
                        </select>
                        <select
                            v-model="pageStatusFilter"
                            class="h-8 rounded-md border border-input bg-background px-2 text-xs"
                        >
                            <option value="all">
                                {{ t('admin.cms.allStatuses') }}
                            </option>
                            <option
                                v-for="st in policyStatuses"
                                :key="st.value"
                                :value="st.value"
                            >
                                {{ st.label }}
                            </option>
                        </select>
                    </div>
                </div>

                <AutoHideScrollArea class="max-h-[680px] space-y-1.5 pe-1 pt-1">
                    <button
                        v-for="policy in filteredPolicies"
                        :key="policy.id"
                        type="button"
                        :class="[
                            'flex w-full items-start justify-between gap-2 rounded-lg border px-3 py-2.5 text-start text-xs transition-colors',
                            selectedPolicySlug === policy.slug
                                ? 'border-primary/40 bg-primary-soft/70 text-accent-foreground'
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
                            <div
                                class="mt-1 flex flex-wrap items-center gap-1.5 text-[10px] text-muted-foreground"
                            >
                                <span>{{
                                    pageTypeLabel(policy.page_type)
                                }}</span>
                                <span>·</span>
                                <span>
                                    {{
                                        t('admin.cms.localesCount', {
                                            count: translationBadgeCount(
                                                policy,
                                            ),
                                        })
                                    }}
                                </span>
                            </div>
                        </div>
                        <Badge
                            :variant="
                                policy.status === 'published'
                                    ? 'secondary'
                                    : policy.status === 'archived'
                                      ? 'destructive'
                                      : 'outline'
                            "
                            class="shrink-0 text-[10px]"
                        >
                            {{ policy.status_label }}
                        </Badge>
                    </button>
                </AutoHideScrollArea>
            </div>

            <!-- Right: Full CMS Editor -->
            <div
                v-if="selectedPolicy"
                class="rounded-xl border border-border bg-card p-5 shadow-xs lg:col-span-8"
            >
                <div
                    class="mb-4 flex flex-wrap items-center justify-between gap-2 border-b border-border pb-3"
                >
                    <div class="flex min-w-0 items-center gap-2">
                        <FileText
                            class="size-4 shrink-0 text-primary"
                            aria-hidden="true"
                        />
                        <h2
                            class="truncate text-sm font-semibold text-foreground"
                        >
                            {{
                                t('admin.policiesCenter.editingPath', {
                                    slug: selectedPolicy.slug,
                                })
                            }}
                        </h2>
                    </div>

                    <div class="flex items-center gap-2">
                        <Button
                            variant="outline"
                            size="sm"
                            class="h-8 rounded-lg text-xs"
                            as-child
                        >
                            <Link :href="showPolicy(selectedPolicy.slug)">
                                <Eye class="size-3.5" aria-hidden="true" />
                                <span>{{
                                    t('admin.policiesCenter.viewPage')
                                }}</span>
                            </Link>
                        </Button>
                    </div>
                </div>

                <!-- Sub-tabs: Content & Languages | SEO & OG | Version History -->
                <div class="mb-4 flex flex-wrap gap-1.5">
                    <Button
                        type="button"
                        size="sm"
                        :variant="
                            editorSubTab === 'content' ? 'secondary' : 'ghost'
                        "
                        class="h-8 rounded-lg text-xs"
                        @click="editorSubTab = 'content'"
                    >
                        <Globe class="size-3.5" aria-hidden="true" />
                        <span>{{ t('admin.cms.subTabs.content') }}</span>
                    </Button>
                    <Button
                        type="button"
                        size="sm"
                        :variant="
                            editorSubTab === 'seo' ? 'secondary' : 'ghost'
                        "
                        class="h-8 rounded-lg text-xs"
                        @click="editorSubTab = 'seo'"
                    >
                        <Sparkles class="size-3.5" aria-hidden="true" />
                        <span>{{ t('admin.cms.subTabs.seo') }}</span>
                    </Button>
                    <Button
                        type="button"
                        size="sm"
                        :variant="
                            editorSubTab === 'revisions' ? 'secondary' : 'ghost'
                        "
                        class="h-8 rounded-lg text-xs"
                        @click="editorSubTab = 'revisions'"
                    >
                        <History class="size-3.5" aria-hidden="true" />
                        <span>
                            {{
                                t('admin.cms.subTabs.revisions', {
                                    count: selectedPolicy.revisions.length,
                                })
                            }}
                        </span>
                    </Button>
                </div>

                <!-- SUB-TAB: CONTENT & 15-LOCALE TRANSLATIONS -->
                <div v-if="editorSubTab === 'content'" class="space-y-4">
                    <!-- 15-Locale Pill Strip -->
                    <div
                        class="rounded-xl border border-border/80 bg-muted/30 p-3"
                    >
                        <p
                            class="mb-2 text-[11px] font-semibold text-muted-foreground"
                        >
                            {{ t('admin.cms.languageSelectorLabel') }}
                        </p>
                        <div class="flex flex-wrap gap-1.5">
                            <button
                                v-for="lang in availableLanguages"
                                :key="lang.code"
                                type="button"
                                :class="[
                                    'inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-[11px] font-medium transition-colors',
                                    activeEditorLocale === lang.code
                                        ? 'border-primary bg-primary-soft text-accent-foreground'
                                        : 'border-border bg-card text-muted-foreground hover:text-foreground',
                                ]"
                                @click="switchEditorLocale(lang.code)"
                            >
                                <span
                                    v-if="
                                        localeHasPublishedCopy(
                                            selectedPolicy,
                                            lang.code,
                                        )
                                    "
                                    class="size-1.5 rounded-full bg-emerald-500"
                                    aria-hidden="true"
                                />
                                <span>{{ lang.code.toUpperCase() }}</span>
                                <span class="hidden sm:inline"
                                    >· {{ lang.native_name }}</span
                                >
                            </button>
                        </div>
                    </div>

                    <!-- Primary English / Vietnamese Form -->
                    <form
                        v-if="
                            activeEditorLocale === 'en' ||
                            activeEditorLocale === 'vi'
                        "
                        class="space-y-4"
                        @submit.prevent="submitPolicy"
                    >
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div class="grid gap-1.5">
                                <Label for="title_en">{{
                                    t('admin.policiesCenter.titleEnLabel')
                                }}</Label>
                                <Input
                                    id="title_en"
                                    v-model="policyForm.title_en"
                                    required
                                />
                                <InputError
                                    :message="policyForm.errors.title_en"
                                />
                            </div>
                            <div class="grid gap-1.5">
                                <Label for="title_vi">{{
                                    t('admin.policiesCenter.titleViLabel')
                                }}</Label>
                                <Input
                                    id="title_vi"
                                    v-model="policyForm.title_vi"
                                    required
                                />
                                <InputError
                                    :message="policyForm.errors.title_vi"
                                />
                            </div>
                        </div>

                        <div class="grid gap-4 sm:grid-cols-2">
                            <div class="grid gap-1.5">
                                <Label for="summary_en">{{
                                    t('admin.policiesCenter.summaryEnLabel')
                                }}</Label>
                                <Input
                                    id="summary_en"
                                    v-model="policyForm.summary_en"
                                />
                            </div>
                            <div class="grid gap-1.5">
                                <Label for="summary_vi">{{
                                    t('admin.policiesCenter.summaryViLabel')
                                }}</Label>
                                <Input
                                    id="summary_vi"
                                    v-model="policyForm.summary_vi"
                                />
                            </div>
                        </div>

                        <!-- Rich Toolbar + Preview Toggle -->
                        <div class="space-y-2">
                            <div
                                class="flex flex-wrap items-center justify-between gap-2"
                            >
                                <Label
                                    :for="
                                        activeEditorLocale === 'en'
                                            ? 'content_en'
                                            : 'content_vi'
                                    "
                                >
                                    {{
                                        activeEditorLocale === 'en'
                                            ? t(
                                                  'admin.policiesCenter.contentEnLabel',
                                              )
                                            : t(
                                                  'admin.policiesCenter.contentViLabel',
                                              )
                                    }}
                                </Label>

                                <div class="flex flex-wrap items-center gap-1">
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="icon"
                                        class="size-7 rounded-md"
                                        :title="'H2'"
                                        :aria-label="'H2'"
                                        @click="insertFormat('h2')"
                                    >
                                        <Heading2 class="size-3.5" />
                                    </Button>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="icon"
                                        class="size-7 rounded-md"
                                        :title="'H3'"
                                        :aria-label="'H3'"
                                        @click="insertFormat('h3')"
                                    >
                                        <Heading3 class="size-3.5" />
                                    </Button>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="icon"
                                        class="size-7 rounded-md"
                                        :title="'B'"
                                        :aria-label="'B'"
                                        @click="insertFormat('bold')"
                                    >
                                        <Bold class="size-3.5" />
                                    </Button>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="icon"
                                        class="size-7 rounded-md"
                                        :title="'I'"
                                        :aria-label="'I'"
                                        @click="insertFormat('italic')"
                                    >
                                        <Italic class="size-3.5" />
                                    </Button>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="icon"
                                        class="size-7 rounded-md"
                                        :title="'UL'"
                                        :aria-label="'UL'"
                                        @click="insertFormat('list')"
                                    >
                                        <List class="size-3.5" />
                                    </Button>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="icon"
                                        class="size-7 rounded-md"
                                        :title="'Q'"
                                        :aria-label="'Q'"
                                        @click="insertFormat('quote')"
                                    >
                                        <Quote class="size-3.5" />
                                    </Button>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        class="h-7 gap-1 rounded-md px-2 text-[11px]"
                                        @click="inlineImageModalOpen = true"
                                    >
                                        <ImagePlus
                                            class="size-3.5"
                                            aria-hidden="true"
                                        />
                                        <span>{{
                                            t('admin.cms.insertImage')
                                        }}</span>
                                    </Button>
                                    <Button
                                        type="button"
                                        :variant="
                                            showHtmlPreview
                                                ? 'secondary'
                                                : 'outline'
                                        "
                                        size="sm"
                                        class="h-7 gap-1 rounded-md px-2 text-[11px]"
                                        @click="
                                            showHtmlPreview = !showHtmlPreview
                                        "
                                    >
                                        <Eye
                                            class="size-3.5"
                                            aria-hidden="true"
                                        />
                                        <span>{{
                                            t('admin.cms.previewToggle')
                                        }}</span>
                                    </Button>
                                </div>
                            </div>

                            <div
                                v-if="showHtmlPreview"
                                class="min-h-48 rounded-lg border border-border bg-muted/20 p-4 text-sm leading-7 text-foreground [&_a]:text-primary [&_a]:underline [&_h2]:mt-4 [&_h2]:text-base [&_h2]:font-semibold [&_h3]:mt-3 [&_h3]:font-semibold [&_img]:my-3 [&_img]:max-w-full [&_img]:rounded-lg [&_li]:ms-5 [&_li]:list-disc"
                                v-html="activeContentValue()"
                            />
                            <textarea
                                v-else-if="activeEditorLocale === 'en'"
                                id="content_en"
                                v-model="policyForm.content_en"
                                rows="10"
                                class="w-full rounded-lg border border-input bg-transparent px-3 py-2 font-mono text-xs leading-relaxed outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                            />
                            <textarea
                                v-else
                                id="content_vi"
                                v-model="policyForm.content_vi"
                                rows="10"
                                class="w-full rounded-lg border border-input bg-transparent px-3 py-2 font-mono text-xs leading-relaxed outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                            />
                        </div>

                        <!-- Status, Type, Footer Placement, Visibility & Effective Date -->
                        <div class="grid gap-3 sm:grid-cols-3">
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
                                <Label for="policy_type">{{
                                    t('admin.cms.pageTypeLabel')
                                }}</Label>
                                <select
                                    id="policy_type"
                                    v-model="policyForm.page_type"
                                    class="h-9 rounded-md border border-input bg-transparent px-3 text-sm dark:bg-card"
                                >
                                    <option
                                        v-for="pt in pageTypes"
                                        :key="pt"
                                        :value="pt"
                                    >
                                        {{ pageTypeLabel(pt) }}
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
                        </div>

                        <div class="grid gap-3 sm:grid-cols-4">
                            <div class="grid gap-1.5">
                                <Label for="policy_placement">{{
                                    t('admin.cms.placementLabel')
                                }}</Label>
                                <select
                                    id="policy_placement"
                                    v-model="policyForm.placement"
                                    class="h-9 rounded-md border border-input bg-transparent px-3 text-sm dark:bg-card"
                                >
                                    <option value="main">
                                        {{ t('admin.cms.placementMain') }}
                                    </option>
                                    <option value="more">
                                        {{ t('admin.cms.placementMore') }}
                                    </option>
                                </select>
                            </div>
                            <div class="grid gap-1.5">
                                <Label for="policy_effective_at">{{
                                    t('admin.cms.effectiveAtLabel')
                                }}</Label>
                                <Input
                                    id="policy_effective_at"
                                    v-model="policyForm.effective_at"
                                    type="date"
                                />
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

                        <div class="grid gap-1.5">
                            <Label for="policy_change_note">{{
                                t('admin.cms.changeNoteLabel')
                            }}</Label>
                            <Input
                                id="policy_change_note"
                                v-model="policyForm.change_note"
                                :placeholder="
                                    t('admin.cms.changeNotePlaceholder')
                                "
                            />
                        </div>

                        <Button
                            type="submit"
                            class="rounded-lg"
                            :disabled="policyForm.processing"
                            data-test="save-policy-button"
                        >
                            <Save class="size-4" aria-hidden="true" />
                            <span>{{
                                t('admin.policiesCenter.savePolicy')
                            }}</span>
                        </Button>
                    </form>

                    <!-- Other 13 Languages Translation Editor -->
                    <form
                        v-else
                        class="space-y-4"
                        @submit.prevent="submitTranslation"
                    >
                        <div
                            class="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-primary/20 bg-primary-soft/40 px-3 py-2 text-xs text-accent-foreground"
                        >
                            <span>
                                {{
                                    t('admin.cms.translationHint', {
                                        locale: activeEditorLocale.toUpperCase(),
                                        path: `/${activeEditorLocale}/policies/${selectedPolicy.slug}`,
                                    })
                                }}
                            </span>
                        </div>

                        <div class="grid gap-4 sm:grid-cols-2">
                            <div class="grid gap-1.5">
                                <Label for="tr_title">{{
                                    t('admin.cms.translationTitleLabel')
                                }}</Label>
                                <Input
                                    id="tr_title"
                                    v-model="translationForm.title"
                                    required
                                />
                                <InputError
                                    :message="translationForm.errors.title"
                                />
                            </div>
                            <div class="grid gap-1.5">
                                <Label for="tr_status">{{
                                    t('admin.policiesCenter.statusLabel')
                                }}</Label>
                                <select
                                    id="tr_status"
                                    v-model="translationForm.status"
                                    class="h-9 rounded-md border border-input bg-transparent px-3 text-sm dark:bg-card"
                                >
                                    <option value="draft">
                                        {{
                                            t(
                                                'admin.policiesCenter.statusDraft',
                                            )
                                        }}
                                    </option>
                                    <option value="published">
                                        {{
                                            t(
                                                'admin.policiesCenter.statusPublished',
                                            )
                                        }}
                                    </option>
                                </select>
                            </div>
                        </div>

                        <div class="grid gap-1.5">
                            <Label for="tr_summary">{{
                                t('admin.cms.translationSummaryLabel')
                            }}</Label>
                            <Input
                                id="tr_summary"
                                v-model="translationForm.summary"
                            />
                        </div>

                        <div class="grid gap-1.5">
                            <div class="flex items-center justify-between">
                                <Label for="tr_content">{{
                                    t('admin.cms.translationContentLabel')
                                }}</Label>
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    class="h-7 gap-1 rounded-md px-2 text-[11px]"
                                    @click="inlineImageModalOpen = true"
                                >
                                    <ImagePlus
                                        class="size-3.5"
                                        aria-hidden="true"
                                    />
                                    <span>{{
                                        t('admin.cms.insertImage')
                                    }}</span>
                                </Button>
                            </div>
                            <textarea
                                id="tr_content"
                                v-model="translationForm.content"
                                rows="9"
                                class="w-full rounded-lg border border-input bg-transparent px-3 py-2 font-mono text-xs leading-relaxed outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                            />
                        </div>

                        <div class="grid gap-4 sm:grid-cols-2">
                            <div class="grid gap-1.5">
                                <Label for="tr_seo_title">{{
                                    t('admin.cms.seoTitleLabel')
                                }}</Label>
                                <Input
                                    id="tr_seo_title"
                                    v-model="translationForm.seo_title"
                                />
                            </div>
                            <div class="grid gap-1.5">
                                <Label for="tr_meta_desc">{{
                                    t('admin.cms.metaDescriptionLabel')
                                }}</Label>
                                <Input
                                    id="tr_meta_desc"
                                    v-model="translationForm.meta_description"
                                />
                            </div>
                        </div>

                        <Button
                            type="submit"
                            class="rounded-lg"
                            :disabled="translationForm.processing"
                        >
                            <Save class="size-4" aria-hidden="true" />
                            <span>{{ t('admin.cms.saveTranslation') }}</span>
                        </Button>
                    </form>
                </div>

                <!-- SUB-TAB: SEO & OG IMAGE -->
                <form
                    v-else-if="editorSubTab === 'seo'"
                    class="space-y-4"
                    @submit.prevent="submitPolicy"
                >
                    <div
                        class="rounded-xl border border-border bg-muted/20 p-4"
                    >
                        <p
                            class="text-[11px] font-semibold text-muted-foreground uppercase"
                        >
                            {{ t('admin.cms.serpPreviewTitle') }}
                        </p>
                        <p class="mt-1.5 text-sm font-semibold text-primary">
                            {{
                                policyForm.seo_title ||
                                `${policyForm.title_en} | ${brandName}`
                            }}
                        </p>
                        <p
                            class="text-[11px] text-emerald-700 dark:text-emerald-400"
                        >
                            /policies/{{ selectedPolicy.slug }}
                        </p>
                        <p
                            class="mt-1 line-clamp-2 text-xs text-muted-foreground"
                        >
                            {{
                                policyForm.meta_description ||
                                policyForm.summary_en ||
                                policyForm.title_en
                            }}
                        </p>
                    </div>

                    <div class="grid gap-1.5">
                        <Label for="seo_title">{{
                            t('admin.cms.seoTitleLabel')
                        }}</Label>
                        <Input
                            id="seo_title"
                            v-model="policyForm.seo_title"
                            maxlength="160"
                        />
                    </div>

                    <div class="grid gap-1.5">
                        <Label for="meta_description">{{
                            t('admin.cms.metaDescriptionLabel')
                        }}</Label>
                        <textarea
                            id="meta_description"
                            v-model="policyForm.meta_description"
                            rows="3"
                            maxlength="320"
                            class="w-full rounded-lg border border-input bg-transparent px-3 py-2 text-sm outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                        />
                    </div>

                    <div class="grid gap-1.5">
                        <Label for="canonical_url">{{
                            t('admin.cms.canonicalUrlLabel')
                        }}</Label>
                        <Input
                            id="canonical_url"
                            v-model="policyForm.canonical_url"
                            :placeholder="`/policies/${selectedPolicy.slug}`"
                        />
                    </div>

                    <label class="flex items-center gap-2 text-sm">
                        <input
                            v-model="policyForm.index_eligible"
                            type="checkbox"
                            class="size-4 rounded border-input"
                        />
                        <span>{{ t('admin.cms.indexEligibleLabel') }}</span>
                    </label>

                    <div class="space-y-2">
                        <ImageUploader
                            :model-value="policyForm.og_image"
                            context="cms"
                            :existing-url="
                                policyForm.remove_og_image
                                    ? null
                                    : selectedPolicy.og_image
                            "
                            :label="t('admin.cms.ogImageLabel')"
                            :description="t('admin.cms.ogImageHint')"
                            compact
                            :external-error="policyForm.errors.og_image"
                            :disabled="policyForm.processing"
                            @update:model-value="
                                (val) =>
                                    (policyForm.og_image = Array.isArray(val)
                                        ? (val[0] ?? null)
                                        : val)
                            "
                        />
                        <label
                            v-if="selectedPolicy.og_image"
                            class="flex items-center gap-2 text-xs text-muted-foreground"
                        >
                            <input
                                v-model="policyForm.remove_og_image"
                                type="checkbox"
                                class="size-3.5 rounded border-input"
                            />
                            <span>{{ t('admin.cms.removeOgImage') }}</span>
                        </label>
                    </div>

                    <Button
                        type="submit"
                        class="rounded-lg"
                        :disabled="policyForm.processing"
                    >
                        <Save class="size-4" aria-hidden="true" />
                        <span>{{ t('admin.cms.saveSeoSettings') }}</span>
                    </Button>
                </form>

                <!-- SUB-TAB: VERSION HISTORY -->
                <div v-else class="space-y-3">
                    <div
                        v-if="selectedPolicy.revisions.length === 0"
                        class="rounded-xl border border-dashed border-border p-8 text-center text-xs text-muted-foreground"
                    >
                        {{ t('admin.cms.noRevisions') }}
                    </div>

                    <div
                        v-for="rev in selectedPolicy.revisions"
                        :key="rev.id"
                        class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-border p-3.5"
                    >
                        <div class="min-w-0 space-y-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <Badge variant="secondary" class="text-[10px]">
                                    v{{ rev.version_number }}
                                </Badge>
                                <Badge variant="outline" class="text-[10px]">
                                    {{ rev.locale.toUpperCase() }}
                                </Badge>
                                <p
                                    class="truncate text-xs font-semibold text-foreground"
                                >
                                    {{ rev.title }}
                                </p>
                            </div>
                            <p
                                v-if="rev.change_note"
                                class="text-xs text-muted-foreground"
                            >
                                {{ rev.change_note }}
                            </p>
                            <p class="text-[11px] text-muted-foreground">
                                {{ rev.author_name || 'Admin' }}
                                <span v-if="rev.created_at">
                                    ·
                                    {{
                                        new Date(
                                            rev.created_at,
                                        ).toLocaleString()
                                    }}
                                </span>
                            </p>
                        </div>

                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            class="h-8 rounded-lg text-xs"
                            @click="promptRestoreRevision(rev)"
                        >
                            <RotateCcw class="size-3.5" aria-hidden="true" />
                            <span>{{ t('admin.cms.restoreVersion') }}</span>
                        </Button>
                    </div>
                </div>
            </div>
        </section>

        <!-- TAB 2: FOOTER & CONTACT MANAGER -->
        <section v-else class="space-y-5">
            <!-- Live MiniFooter Preview -->
            <div class="rounded-xl border border-border bg-card p-5 shadow-xs">
                <div class="flex items-center gap-2 text-sm font-semibold">
                    <CheckCircle2
                        class="size-4 text-primary"
                        aria-hidden="true"
                    />
                    <span>{{ t('admin.cms.liveFooterPreview') }}</span>
                </div>
                <div
                    class="mt-3 max-w-sm rounded-xl border border-dashed border-border bg-muted/20 p-4 text-[11px] leading-relaxed text-muted-foreground"
                >
                    <div
                        v-if="
                            previewMainLinks.length > 0 ||
                            previewMoreLinks.length > 0
                        "
                        class="flex flex-wrap items-center gap-x-1.5 gap-y-1"
                    >
                        <template
                            v-for="(item, idx) in previewMainLinks"
                            :key="item.id"
                        >
                            <span>{{
                                locale.startsWith('vi')
                                    ? item.label_vi
                                    : item.label_en
                            }}</span>
                            <span
                                v-if="
                                    idx < previewMainLinks.length - 1 ||
                                    previewMoreLinks.length > 0
                                "
                                >·</span
                            >
                        </template>
                        <span
                            v-if="previewMoreLinks.length > 0"
                            class="inline-flex items-center gap-0.5 font-medium text-foreground"
                        >
                            <span>{{ t('footer.more') }}</span>
                            <ChevronDown class="size-3" />
                            <span>({{ previewMoreLinks.length }})</span>
                        </span>
                    </div>
                    <p
                        v-if="footerSettingsForm.contact_email"
                        class="mt-1.5 text-primary"
                    >
                        {{ footerSettingsForm.contact_email }}
                    </p>
                    <p
                        v-if="footerSettingsForm.support_contact_info"
                        class="mt-0.5"
                    >
                        {{ footerSettingsForm.support_contact_info }}
                    </p>
                    <p class="mt-1.5">
                        {{
                            footerSettingsForm.footer_copyright_text ||
                            t('footer.copyright', {
                                year: new Date().getFullYear(),
                                brand: brandName,
                            })
                        }}
                    </p>
                </div>
            </div>

            <!-- Support Email & Footer Branding Config -->
            <form
                class="rounded-xl border border-border bg-card p-5 shadow-xs"
                @submit.prevent="saveFooterSettings"
            >
                <div class="flex items-center gap-2">
                    <Mail class="size-4 text-primary" aria-hidden="true" />
                    <h2 class="text-base font-semibold text-foreground">
                        {{ t('admin.cms.footerBrandingHeading') }}
                    </h2>
                </div>
                <p class="mt-1 text-xs text-muted-foreground">
                    {{ t('admin.cms.footerBrandingHint') }}
                </p>

                <div class="mt-4 grid gap-4 md:grid-cols-3">
                    <div class="grid gap-1.5">
                        <Label for="footer_contact_email">{{
                            t('admin.cms.supportEmailLabel')
                        }}</Label>
                        <Input
                            id="footer_contact_email"
                            v-model="footerSettingsForm.contact_email"
                            type="email"
                            :placeholder="'support@yourdomain.com'"
                        />
                        <InputError
                            :message="footerSettingsForm.errors.contact_email"
                        />
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="footer_contact_info">{{
                            t('admin.cms.contactInfoLabel')
                        }}</Label>
                        <Input
                            id="footer_contact_info"
                            v-model="footerSettingsForm.support_contact_info"
                            :placeholder="t('admin.cms.contactInfoPlaceholder')"
                        />
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="footer_copyright">{{
                            t('admin.cms.copyrightLabel')
                        }}</Label>
                        <Input
                            id="footer_copyright"
                            v-model="footerSettingsForm.footer_copyright_text"
                            :placeholder="
                                t('footer.copyright', {
                                    year: new Date().getFullYear(),
                                    brand: brandName,
                                })
                            "
                        />
                    </div>
                </div>

                <Button
                    type="submit"
                    size="sm"
                    class="mt-4 rounded-lg"
                    :disabled="footerSettingsForm.processing"
                >
                    <Save class="size-4" aria-hidden="true" />
                    <span>{{ t('admin.cms.saveFooterBranding') }}</span>
                </Button>
            </form>

            <!-- Footer Links Management -->
            <div class="rounded-xl border border-border bg-card p-5 shadow-xs">
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
                        <div class="md:col-span-2">
                            <Input
                                v-model="link.label_en"
                                :aria-label="
                                    t('admin.policiesCenter.labelEnAria')
                                "
                            />
                        </div>
                        <div class="md:col-span-2">
                            <Input
                                v-model="link.label_vi"
                                :aria-label="
                                    t('admin.policiesCenter.labelViAria')
                                "
                            />
                        </div>
                        <div class="md:col-span-2">
                            <select
                                v-model="link.footer_group"
                                :aria-label="
                                    t('admin.policiesCenter.groupAria')
                                "
                                class="h-9 w-full rounded-md border border-input bg-transparent px-2 text-xs dark:bg-card"
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
                        <div class="md:col-span-2">
                            <select
                                v-model="link.placement"
                                :aria-label="t('admin.cms.placementLabel')"
                                class="h-9 w-full rounded-md border border-input bg-transparent px-2 text-xs dark:bg-card"
                            >
                                <option value="main">
                                    {{ t('admin.cms.placementMain') }}
                                </option>
                                <option value="more">
                                    {{ t('admin.cms.placementMore') }}
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
                                :title="
                                    t('admin.policiesCenter.deleteLinkTitle')
                                "
                                :aria-label="
                                    t('admin.policiesCenter.deleteLinkTitle')
                                "
                                @click="promptDeleteLink(link)"
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
                    <div class="grid gap-1 md:col-span-2">
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
                    <div class="grid gap-1 md:col-span-2">
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
                            t('admin.policiesCenter.urlLabel')
                        }}</Label>
                        <Input
                            v-model="newLinkForm.url"
                            required
                            :placeholder="
                                t('admin.policiesCenter.urlPlaceholder')
                            "
                        />
                    </div>
                    <div class="grid gap-1 md:col-span-2">
                        <Label class="text-xs">{{
                            t('admin.cms.placementLabel')
                        }}</Label>
                        <select
                            v-model="newLinkForm.placement"
                            class="h-9 rounded-md border border-input bg-transparent px-2 text-xs dark:bg-card"
                        >
                            <option value="main">
                                {{ t('admin.cms.placementMain') }}
                            </option>
                            <option value="more">
                                {{ t('admin.cms.placementMore') }}
                            </option>
                        </select>
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
                            <Plus class="size-4" aria-hidden="true" />
                            <span>{{ t('admin.policiesCenter.addLink') }}</span>
                        </Button>
                    </div>
                </form>
            </div>
        </section>

        <!-- Create New CMS Page Modal -->
        <Dialog v-model:open="createModalOpen">
            <DialogContent structured class="rounded-2xl sm:max-w-lg">
                <div
                    class="shrink-0 border-b border-border/70 px-6 pe-12 pt-5 pb-4"
                >
                    <DialogHeader class="text-start">
                        <DialogTitle>{{
                            t('admin.cms.createPage')
                        }}</DialogTitle>
                        <DialogDescription>{{
                            t('admin.cms.createPageHint')
                        }}</DialogDescription>
                    </DialogHeader>
                </div>

                <AutoHideScrollArea class="flex-1 px-6 py-4">
                    <form
                        id="create-cms-page-form"
                        class="space-y-4"
                        @submit.prevent="submitCreatePolicy"
                    >
                        <div class="grid gap-3 sm:grid-cols-2">
                            <div class="grid gap-1.5">
                                <Label for="new_title_en">{{
                                    t('admin.policiesCenter.titleEnLabel')
                                }}</Label>
                                <Input
                                    id="new_title_en"
                                    v-model="createForm.title_en"
                                    required
                                    @blur="autoSlugFromTitle"
                                />
                                <InputError
                                    :message="createForm.errors.title_en"
                                />
                            </div>
                            <div class="grid gap-1.5">
                                <Label for="new_title_vi">{{
                                    t('admin.policiesCenter.titleViLabel')
                                }}</Label>
                                <Input
                                    id="new_title_vi"
                                    v-model="createForm.title_vi"
                                    required
                                />
                                <InputError
                                    :message="createForm.errors.title_vi"
                                />
                            </div>
                        </div>

                        <div class="grid gap-1.5">
                            <Label for="new_slug">{{
                                t('admin.cms.slugLabel')
                            }}</Label>
                            <Input
                                id="new_slug"
                                v-model="createForm.slug"
                                required
                                :placeholder="'my-new-page'"
                            />
                            <InputError :message="createForm.errors.slug" />
                        </div>

                        <div class="grid gap-3 sm:grid-cols-3">
                            <div class="grid gap-1.5">
                                <Label for="new_page_type">{{
                                    t('admin.cms.pageTypeLabel')
                                }}</Label>
                                <select
                                    id="new_page_type"
                                    v-model="createForm.page_type"
                                    class="h-9 rounded-md border border-input bg-background px-2 text-xs"
                                >
                                    <option
                                        v-for="pt in pageTypes"
                                        :key="pt"
                                        :value="pt"
                                    >
                                        {{ pageTypeLabel(pt) }}
                                    </option>
                                </select>
                            </div>
                            <div class="grid gap-1.5">
                                <Label for="new_group">{{
                                    t('admin.policiesCenter.footerGroupLabel')
                                }}</Label>
                                <select
                                    id="new_group"
                                    v-model="createForm.footer_group"
                                    class="h-9 rounded-md border border-input bg-background px-2 text-xs"
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
                                <Label for="new_placement">{{
                                    t('admin.cms.placementLabel')
                                }}</Label>
                                <select
                                    id="new_placement"
                                    v-model="createForm.placement"
                                    class="h-9 rounded-md border border-input bg-background px-2 text-xs"
                                >
                                    <option value="main">
                                        {{ t('admin.cms.placementMain') }}
                                    </option>
                                    <option value="more">
                                        {{ t('admin.cms.placementMore') }}
                                    </option>
                                </select>
                            </div>
                        </div>
                    </form>
                </AutoHideScrollArea>

                <DialogFooter
                    class="shrink-0 gap-2 border-t border-border/70 px-6 py-4"
                >
                    <Button
                        type="button"
                        variant="outline"
                        class="rounded-lg"
                        @click="createModalOpen = false"
                    >
                        {{ t('dialog.cancel') }}
                    </Button>
                    <Button
                        type="submit"
                        form="create-cms-page-form"
                        class="rounded-lg"
                        :disabled="createForm.processing"
                    >
                        <FilePlus2 class="size-4" aria-hidden="true" />
                        <span>{{ t('admin.cms.createPage') }}</span>
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <!-- Device-only Inline Image Upload Modal -->
        <Dialog v-model:open="inlineImageModalOpen">
            <DialogContent structured class="rounded-2xl sm:max-w-md">
                <div
                    class="shrink-0 border-b border-border/70 px-6 pe-12 pt-5 pb-4"
                >
                    <DialogHeader class="text-start">
                        <DialogTitle>{{
                            t('admin.cms.insertImage')
                        }}</DialogTitle>
                        <DialogDescription>{{
                            t('admin.cms.insertImageHint')
                        }}</DialogDescription>
                    </DialogHeader>
                </div>

                <AutoHideScrollArea class="flex-1 space-y-4 px-6 py-4">
                    <ImageUploader
                        :model-value="inlineImageFile"
                        context="cms"
                        compact
                        :disabled="inlineImageUploading"
                        @update:model-value="
                            (val) =>
                                (inlineImageFile = Array.isArray(val)
                                    ? (val[0] ?? null)
                                    : val)
                        "
                    />
                    <div class="grid gap-1.5">
                        <Label for="inline_img_alt">{{
                            t('upload.altTextLabel')
                        }}</Label>
                        <Input
                            id="inline_img_alt"
                            v-model="inlineImageAlt"
                            :placeholder="t('upload.altTextPlaceholder')"
                            maxlength="200"
                        />
                    </div>
                </AutoHideScrollArea>

                <DialogFooter
                    class="shrink-0 gap-2 border-t border-border/70 px-6 py-4"
                >
                    <Button
                        type="button"
                        variant="outline"
                        class="rounded-lg"
                        @click="inlineImageModalOpen = false"
                    >
                        {{ t('dialog.cancel') }}
                    </Button>
                    <Button
                        type="button"
                        class="rounded-lg"
                        :disabled="!inlineImageFile || inlineImageUploading"
                        @click="uploadAndInsertInlineImage"
                    >
                        <ImagePlus class="size-4" aria-hidden="true" />
                        <span>{{ t('admin.cms.uploadAndInsert') }}</span>
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <!-- Confirm Restore Revision Dialog -->
        <ConfirmActionDialog
            v-model:open="restoreConfirmOpen"
            :title="t('admin.cms.restoreConfirmTitle')"
            :description="
                t('admin.cms.restoreConfirmDesc', {
                    version: pendingRestoreRevision?.version_number ?? '',
                })
            "
            :confirm-label="t('admin.cms.restoreVersion')"
            :destructive="false"
            :processing="restoringRevision"
            @confirm="confirmRestoreRevision"
        />

        <!-- Confirm Delete Footer Link Dialog -->
        <ConfirmActionDialog
            v-model:open="deleteLinkDialogOpen"
            :title="t('admin.policiesCenter.deleteLinkTitle')"
            :description="
                t('admin.cms.deleteLinkConfirmDesc', {
                    label: pendingDeleteLink?.label_en ?? '',
                })
            "
            :processing="deletingLink"
            @confirm="confirmDeleteLink"
        />
    </div>
</template>
