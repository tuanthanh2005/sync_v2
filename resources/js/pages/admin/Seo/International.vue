<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import {
    CheckCircle2,
    Copy,
    Eye,
    Globe2,
    Languages,
    Share2,
    ShieldAlert,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import SeoInternationalAndAuditController from '@/actions/App/Http/Controllers/Admin/Seo/SeoInternationalAndAuditController';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { copyToClipboard } from '@/lib/share';
import { appToast } from '@/lib/toast';

type SocialPreviewResult = {
    share_url: string;
    canonical: string;
    robots: string;
    og_title: string;
    og_description: string;
    og_type: string;
    og_url: string;
    og_site_name: string;
    og_locale: string;
    og_image: string;
    og_image_type: string | null;
    og_image_width: number | null;
    og_image_height: number | null;
    twitter_card: string;
    twitter_site: string | null;
    privacy_redacted: boolean;
};

const props = defineProps<{
    languages: Array<{
        code: string;
        name: string;
        native_name: string;
        direction: string;
        is_enabled: boolean;
        is_default: boolean;
    }>;
    translations: Array<{
        id: number;
        entity_type: string;
        entity_id: number;
        original_language: string;
        target_locale: string;
        translated_title: string | null;
        localized_slug: string;
        provider: string;
        version: number;
        review_status: string;
        publication_status: string;
        is_stale: boolean;
        published_at: string | null;
    }>;
    candidatePosts: Array<{
        id: number;
        title: string;
        language_code: string;
    }>;
    hreflangValidation: {
        checked_groups: number;
        valid_groups: number;
        issues: Array<{
            severity: string;
            code: string;
            locale: string;
            url: string;
            message: string;
        }>;
    };
    templates: {
        title_template_home: string;
        title_template_post: string;
        title_template_community: string;
        title_template_profile: string;
        title_template_topic: string;
        description_template_home: string;
    };
    socialSharing: {
        twitter_site_handle: string;
        default_og_image: {
            url: string;
            secure_url: string | null;
            type: string;
            width: number | null;
            height: number | null;
        };
        locales: Array<{
            code: string;
            native_name: string;
            og_locale: string;
            custom_title: string;
            custom_description: string;
            default_title: string;
            default_description: string;
            resolved_title: string;
            resolved_description: string;
        }>;
        preview_entities: {
            posts: Array<{ id: number; label: string }>;
            communities: Array<{ id: number; label: string }>;
            profiles: Array<{ id: number; label: string }>;
        };
    };
}>();

const { t } = useI18n();

const trForm = useForm({
    post_id: props.candidatePosts[0]?.id ?? 0,
    target_locale: 'vi',
    translated_title: '',
    translated_body: '',
    translated_description: '',
    localized_slug: '',
});

const tplForm = useForm({ ...props.templates });

function saveTranslation(): void {
    trForm.post(SeoInternationalAndAuditController.storeTranslation.url(), {
        preserveScroll: true,
        onSuccess: () => {
            trForm.translated_title = '';
            trForm.translated_body = '';
            trForm.translated_description = '';
            trForm.localized_slug = '';
        },
    });
}

function publishTranslation(id: number): void {
    router.post(
        SeoInternationalAndAuditController.publishTranslation.url(id),
        {},
        { preserveScroll: true },
    );
}

function unpublishTranslation(id: number): void {
    router.post(
        SeoInternationalAndAuditController.unpublishTranslation.url(id),
        {},
        { preserveScroll: true },
    );
}

function saveTemplates(): void {
    tplForm.post(SeoInternationalAndAuditController.updateTemplates.url(), {
        preserveScroll: true,
    });
}

const socialForm = useForm({
    twitter_site_handle: props.socialSharing.twitter_site_handle ?? '',
    home_overrides: props.socialSharing.locales.map((item) => ({
        code: item.code,
        native_name: item.native_name,
        default_title: item.default_title,
        default_description: item.default_description,
        title: item.custom_title,
        description: item.custom_description,
    })),
});

function saveSocialSharing(): void {
    socialForm
        .transform((data) => ({
            twitter_site_handle: data.twitter_site_handle,
            home_overrides: data.home_overrides.map((row) => ({
                code: row.code,
                title: row.title,
                description: row.description,
            })),
        }))
        .post(SeoInternationalAndAuditController.updateSocialSharing.url(), {
            preserveScroll: true,
        });
}

const previewLocale = ref<string>('en');
const previewPageType = ref<
    'home' | 'post' | 'community' | 'profile' | 'premium' | 'register'
>('home');
const previewEntityId = ref<number | null>(null);
const previewLoading = ref(false);
const previewError = ref<string | null>(null);
const previewResult = ref<SocialPreviewResult | null>(null);

const selectableEntities = computed<Array<{ id: number; label: string }>>(
    () => {
        if (previewPageType.value === 'post') {
            return props.socialSharing.preview_entities.posts;
        }
        if (previewPageType.value === 'community') {
            return props.socialSharing.preview_entities.communities;
        }
        if (previewPageType.value === 'profile') {
            return props.socialSharing.preview_entities.profiles;
        }
        return [];
    },
);

function readXsrfToken(): string | null {
    if (typeof document === 'undefined') {
        return null;
    }

    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
    return match ? decodeURIComponent(match[1]) : null;
}

async function runSocialPreview(): Promise<void> {
    previewLoading.value = true;
    previewError.value = null;

    try {
        const xsrf = readXsrfToken();
        const response = await fetch(
            SeoInternationalAndAuditController.previewSocialCard.url(),
            {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    ...(xsrf ? { 'X-XSRF-TOKEN': xsrf } : {}),
                },
                body: JSON.stringify({
                    page_type: previewPageType.value,
                    locale: previewLocale.value,
                    entity_id:
                        selectableEntities.value.length > 0
                            ? previewEntityId.value
                            : null,
                }),
            },
        );

        const data = (await response.json()) as
            | SocialPreviewResult
            | { error?: string };

        if (!response.ok || !('og_title' in data)) {
            previewError.value =
                ('error' in data && data.error) ||
                t('admin.seoCenter.previewFailed');
            return;
        }

        previewResult.value = data;
    } catch {
        previewError.value = t('admin.seoCenter.previewFailed');
    } finally {
        previewLoading.value = false;
    }
}

async function copyPreviewUrl(): Promise<void> {
    if (!previewResult.value?.share_url) {
        return;
    }
    const ok = await copyToClipboard(previewResult.value.share_url);
    if (ok) {
        appToast.success(t('post.shareCopied'));
    }
}
</script>

<template>
    <div class="space-y-6">
        <Head :title="t('admin.seo.internationalTitle')" />

        <div>
            <h1
                class="text-xl font-bold tracking-tight text-foreground sm:text-2xl"
            >
                {{ t('admin.seo.internationalTitle') }}
            </h1>
            <p class="mt-1 text-xs text-muted-foreground">
                {{ t('admin.seo.internationalSubtitle') }}
            </p>
        </div>

        <!-- 15-Language Matrix & Hreflang Validator -->
        <div class="grid gap-6 lg:grid-cols-2">
            <section
                class="space-y-3 rounded-xl border border-border bg-card p-5 shadow-xs"
            >
                <h2
                    class="flex items-center gap-2 text-sm font-semibold text-foreground"
                >
                    <Globe2 class="size-4 text-primary" />
                    <span>{{
                        t('admin.seoCenter.platformLocalesCount', {
                            count: languages.length,
                        })
                    }}</span>
                </h2>
                <div class="flex flex-wrap gap-2">
                    <Badge
                        v-for="lang in languages"
                        :key="lang.code"
                        :variant="lang.is_enabled ? 'default' : 'outline'"
                    >
                        {{ lang.code }} · {{ lang.native_name }}
                        <span v-if="lang.direction === 'rtl'">
                            {{ t('admin.seoCenter.rtlTag') }}
                        </span>
                    </Badge>
                </div>
            </section>

            <section
                class="space-y-3 rounded-xl border border-border bg-card p-5 shadow-xs"
            >
                <div class="flex items-center justify-between">
                    <h2
                        class="flex items-center gap-2 text-sm font-semibold text-foreground"
                    >
                        <CheckCircle2 class="size-4 text-primary" />
                        <span>{{
                            t('admin.seoCenter.hreflangValidator')
                        }}</span>
                    </h2>
                    <Badge
                        :variant="
                            hreflangValidation.issues.length === 0
                                ? 'default'
                                : 'destructive'
                        "
                    >
                        {{
                            t('admin.seoCenter.validGroupsRatio', {
                                valid: hreflangValidation.valid_groups,
                                total: hreflangValidation.checked_groups,
                            })
                        }}
                    </Badge>
                </div>
                <p
                    v-if="hreflangValidation.issues.length === 0"
                    class="text-xs text-muted-foreground"
                >
                    {{ t('admin.seoCenter.hreflangAllValid') }}
                </p>
                <ul v-else class="space-y-2 text-xs">
                    <li
                        v-for="(issue, idx) in hreflangValidation.issues"
                        :key="idx"
                        class="flex items-start gap-2 rounded-lg bg-destructive/10 p-2.5 text-destructive"
                    >
                        <ShieldAlert class="mt-0.5 size-3.5 shrink-0" />
                        <div>
                            <p class="font-semibold">
                                {{ issue.code }} ({{ issue.locale }})
                            </p>
                            <p>{{ issue.message }}</p>
                        </div>
                    </li>
                </ul>
            </section>
        </div>

        <!-- Content Translation Workflow -->
        <section
            class="space-y-4 rounded-xl border border-border bg-card p-5 shadow-xs"
        >
            <h2
                class="flex items-center gap-2 text-sm font-semibold text-foreground"
            >
                <Languages class="size-4 text-primary" />
                <span>{{ t('admin.seoCenter.translationWorkflowTitle') }}</span>
            </h2>

            <form
                @submit.prevent="saveTranslation"
                class="grid gap-3 md:grid-cols-2"
            >
                <div class="space-y-1">
                    <Label for="tr-post">{{
                        t('admin.seoCenter.sourcePublicPost')
                    }}</Label>
                    <select
                        id="tr-post"
                        v-model.number="trForm.post_id"
                        class="h-10 w-full rounded-lg border border-input bg-background px-3 text-xs"
                    >
                        <option
                            v-for="p in candidatePosts"
                            :key="p.id"
                            :value="p.id"
                        >
                            #{{ p.id }} [{{ p.language_code }}] {{ p.title }}
                        </option>
                    </select>
                </div>
                <div class="space-y-1">
                    <Label for="tr-locale">{{
                        t('admin.seoCenter.targetLocale')
                    }}</Label>
                    <select
                        id="tr-locale"
                        v-model="trForm.target_locale"
                        class="h-10 w-full rounded-lg border border-input bg-background px-3 text-xs"
                    >
                        <option
                            v-for="lang in languages.filter(
                                (l) => l.is_enabled,
                            )"
                            :key="lang.code"
                            :value="lang.code"
                        >
                            {{ lang.code }} — {{ lang.name }}
                        </option>
                    </select>
                </div>
                <div class="space-y-1">
                    <Label for="tr-title">{{
                        t('admin.seoCenter.translatedTitle')
                    }}</Label>
                    <Input id="tr-title" v-model="trForm.translated_title" />
                </div>
                <div class="space-y-1">
                    <Label for="tr-slug">{{
                        t('admin.seoCenter.localizedSlugOptional')
                    }}</Label>
                    <Input
                        id="tr-slug"
                        v-model="trForm.localized_slug"
                        :placeholder="
                            t('admin.seoCenter.localizedSlugPlaceholder')
                        "
                    />
                </div>
                <div class="space-y-1 md:col-span-2">
                    <Label for="tr-body">{{
                        t('admin.seoCenter.translatedBodyLabel')
                    }}</Label>
                    <textarea
                        id="tr-body"
                        v-model="trForm.translated_body"
                        rows="4"
                        class="w-full rounded-lg border border-input bg-background p-3 text-xs"
                    />
                </div>
                <div class="md:col-span-2">
                    <Button
                        type="submit"
                        size="sm"
                        :disabled="
                            trForm.processing || candidatePosts.length === 0
                        "
                    >
                        {{ t('admin.seoCenter.saveTranslationDraft') }}
                    </Button>
                </div>
            </form>

            <div v-if="translations.length > 0" class="overflow-x-auto pt-2">
                <table class="w-full text-left text-xs">
                    <thead class="border-b border-border text-muted-foreground">
                        <tr>
                            <th class="py-2 pr-2 font-medium">
                                {{ t('admin.seoCenter.source') }}
                            </th>
                            <th class="px-2 py-2 font-medium">
                                {{ t('admin.seoCenter.target') }}
                            </th>
                            <th class="px-2 py-2 font-medium">
                                {{ t('admin.seoCenter.slug') }}
                            </th>
                            <th class="px-2 py-2 font-medium">
                                {{ t('admin.seoCenter.status') }}
                            </th>
                            <th class="py-2 pl-2 text-right font-medium">
                                {{ t('admin.seoCenter.actions') }}
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border/60">
                        <tr v-for="tr in translations" :key="tr.id">
                            <td class="py-2 pr-2 font-mono">
                                {{ tr.entity_type }} #{{ tr.entity_id }} ({{
                                    tr.original_language
                                }})
                            </td>
                            <td class="px-2 py-2 font-mono uppercase">
                                {{ tr.target_locale }}
                            </td>
                            <td class="px-2 py-2 font-mono">
                                {{ tr.localized_slug }}
                            </td>
                            <td class="px-2 py-2">
                                <Badge
                                    :variant="
                                        tr.publication_status === 'published'
                                            ? 'default'
                                            : 'secondary'
                                    "
                                >
                                    {{ tr.publication_status }}
                                </Badge>
                                <Badge
                                    v-if="tr.is_stale"
                                    variant="destructive"
                                    class="ml-1"
                                    >{{ t('admin.seoCenter.stale') }}</Badge
                                >
                            </td>
                            <td class="py-2 pl-2 text-right">
                                <Button
                                    v-if="tr.publication_status !== 'published'"
                                    type="button"
                                    size="sm"
                                    variant="outline"
                                    @click="publishTranslation(tr.id)"
                                >
                                    {{ t('admin.seoCenter.approveAndPublish') }}
                                </Button>
                                <Button
                                    v-else
                                    type="button"
                                    size="sm"
                                    variant="ghost"
                                    @click="unpublishTranslation(tr.id)"
                                >
                                    {{ t('admin.seoCenter.unpublish') }}
                                </Button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- Metadata Templates -->
        <section
            class="space-y-4 rounded-xl border border-border bg-card p-5 shadow-xs"
        >
            <h2 class="text-sm font-semibold text-foreground">
                {{ t('admin.seoCenter.globalMetadataTemplates') }}
            </h2>
            <form
                @submit.prevent="saveTemplates"
                class="grid gap-3 md:grid-cols-2"
            >
                <div class="space-y-1">
                    <Label>{{ t('admin.seoCenter.homeTitleTemplate') }}</Label>
                    <Input v-model="tplForm.title_template_home" />
                </div>
                <div class="space-y-1">
                    <Label>{{ t('admin.seoCenter.postTitleTemplate') }}</Label>
                    <Input v-model="tplForm.title_template_post" />
                </div>
                <div class="space-y-1">
                    <Label>{{
                        t('admin.seoCenter.communityTitleTemplate')
                    }}</Label>
                    <Input v-model="tplForm.title_template_community" />
                </div>
                <div class="space-y-1">
                    <Label>{{
                        t('admin.seoCenter.profileTitleTemplate')
                    }}</Label>
                    <Input v-model="tplForm.title_template_profile" />
                </div>
                <div class="space-y-1">
                    <Label>{{ t('admin.seoCenter.topicTitleTemplate') }}</Label>
                    <Input v-model="tplForm.title_template_topic" />
                </div>
                <div class="space-y-1 md:col-span-2">
                    <Label>{{
                        t('admin.seoCenter.homeMetaDescriptionTemplate')
                    }}</Label>
                    <Input v-model="tplForm.description_template_home" />
                </div>
                <div class="md:col-span-2">
                    <Button
                        type="submit"
                        size="sm"
                        :disabled="tplForm.processing"
                        >{{
                            t('admin.seoCenter.saveMetadataTemplates')
                        }}</Button
                    >
                </div>
            </form>
        </section>

        <!-- Multilingual Social Sharing Defaults & Internal Open Graph Tester -->
        <section
            class="space-y-5 rounded-xl border border-border bg-card p-5 shadow-xs"
        >
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2
                        class="flex items-center gap-2 text-sm font-semibold text-foreground"
                    >
                        <Share2 class="size-4 text-primary" />
                        <span>{{
                            t('admin.seoCenter.socialSharingTitle')
                        }}</span>
                    </h2>
                    <p class="mt-1 text-xs text-muted-foreground">
                        {{ t('admin.seoCenter.socialSharingDesc') }}
                    </p>
                </div>
                <Badge variant="outline" class="font-mono text-[11px]">
                    {{ socialSharing.default_og_image.type }}
                    <span
                        v-if="
                            socialSharing.default_og_image.width &&
                            socialSharing.default_og_image.height
                        "
                    >
                        · {{ socialSharing.default_og_image.width }}×{{
                            socialSharing.default_og_image.height
                        }}
                    </span>
                </Badge>
            </div>

            <!-- Interactive Social Card Preview Tester -->
            <div
                class="space-y-4 rounded-xl border border-border bg-muted/25 p-4"
            >
                <div class="flex items-center gap-2 text-xs font-semibold">
                    <Eye class="size-4 text-primary" />
                    <span>{{ t('admin.seoCenter.socialPreviewTester') }}</span>
                </div>

                <div class="grid gap-3 md:grid-cols-4">
                    <div class="space-y-1">
                        <Label for="preview-page-type">{{
                            t('admin.seoCenter.previewPageType')
                        }}</Label>
                        <select
                            id="preview-page-type"
                            v-model="previewPageType"
                            class="h-10 w-full rounded-lg border border-input bg-background px-3 text-xs"
                            @change="previewEntityId = null"
                        >
                            <option value="home">
                                {{ t('admin.seoCenter.pageTypeHome') }}
                            </option>
                            <option value="post">
                                {{ t('admin.seoCenter.pageTypePost') }}
                            </option>
                            <option value="community">
                                {{ t('admin.seoCenter.pageTypeCommunity') }}
                            </option>
                            <option value="profile">
                                {{ t('admin.seoCenter.pageTypeProfile') }}
                            </option>
                            <option value="premium">
                                {{ t('admin.seoCenter.pageTypePremium') }}
                            </option>
                            <option value="register">
                                {{ t('admin.seoCenter.pageTypeReferral') }}
                            </option>
                        </select>
                    </div>

                    <div class="space-y-1">
                        <Label for="preview-locale">{{
                            t('admin.seoCenter.targetLocale')
                        }}</Label>
                        <select
                            id="preview-locale"
                            v-model="previewLocale"
                            class="h-10 w-full rounded-lg border border-input bg-background px-3 text-xs"
                        >
                            <option
                                v-for="lang in languages"
                                :key="lang.code"
                                :value="lang.code"
                            >
                                {{ lang.code }} · {{ lang.native_name }}
                            </option>
                        </select>
                    </div>

                    <div
                        v-if="selectableEntities.length > 0"
                        class="space-y-1 md:col-span-2"
                    >
                        <Label for="preview-entity">{{
                            t('admin.seoCenter.previewEntity')
                        }}</Label>
                        <select
                            id="preview-entity"
                            v-model="previewEntityId"
                            class="h-10 w-full rounded-lg border border-input bg-background px-3 text-xs"
                        >
                            <option :value="null">
                                {{ t('admin.seoCenter.previewLatestPublic') }}
                            </option>
                            <option
                                v-for="item in selectableEntities"
                                :key="item.id"
                                :value="item.id"
                            >
                                {{ item.label }}
                            </option>
                        </select>
                    </div>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <Button
                        type="button"
                        size="sm"
                        :disabled="previewLoading"
                        @click="runSocialPreview"
                    >
                        {{
                            previewLoading
                                ? t('common.loading')
                                : t('admin.seoCenter.runSocialPreview')
                        }}
                    </Button>
                    <p
                        v-if="previewError"
                        class="text-xs font-medium text-destructive"
                    >
                        {{ previewError }}
                    </p>
                </div>

                <div
                    v-if="previewResult"
                    class="grid gap-4 rounded-xl border border-border bg-card p-4 lg:grid-cols-2"
                >
                    <div
                        class="overflow-hidden rounded-xl border border-border bg-muted/20"
                    >
                        <img
                            :src="previewResult.og_image"
                            :alt="previewResult.og_title"
                            class="aspect-[1200/630] w-full bg-background object-cover"
                        />
                        <div class="space-y-1 border-t border-border p-3">
                            <p
                                class="truncate text-[11px] font-medium text-muted-foreground uppercase"
                            >
                                {{ previewResult.og_site_name }} ·
                                {{ previewResult.og_locale }}
                            </p>
                            <p class="truncate text-sm font-semibold">
                                {{ previewResult.og_title }}
                            </p>
                            <p
                                class="line-clamp-2 text-xs text-muted-foreground"
                            >
                                {{ previewResult.og_description }}
                            </p>
                        </div>
                    </div>

                    <div class="space-y-2 text-xs">
                        <div class="flex items-center justify-between gap-2">
                            <span class="font-semibold text-muted-foreground">{{
                                t('admin.seoCenter.shareUrlLabel')
                            }}</span>
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                class="h-7 gap-1 px-2 text-[11px]"
                                @click="copyPreviewUrl"
                            >
                                <Copy class="size-3" />
                                <span>{{ t('growth.referral.copyLink') }}</span>
                            </Button>
                        </div>
                        <p
                            class="rounded-md bg-muted px-2.5 py-1.5 font-mono text-[11px] break-all"
                        >
                            {{ previewResult.share_url }}
                        </p>
                        <div class="grid grid-cols-2 gap-2 pt-1 text-[11px]">
                            <div>
                                <span class="text-muted-foreground">{{
                                    t('admin.seoCenter.metaOgTypeLabel')
                                }}</span>
                                <span class="ms-1 font-mono">{{
                                    previewResult.og_type
                                }}</span>
                            </div>
                            <div>
                                <span class="text-muted-foreground">{{
                                    t('admin.seoCenter.metaTwitterCardLabel')
                                }}</span>
                                <span class="ms-1 font-mono">{{
                                    previewResult.twitter_card
                                }}</span>
                            </div>
                            <div>
                                <span class="text-muted-foreground">{{
                                    t('admin.seoCenter.metaRobotsLabel')
                                }}</span>
                                <span class="ms-1 font-mono">{{
                                    previewResult.robots
                                }}</span>
                            </div>
                            <div v-if="previewResult.privacy_redacted">
                                <Badge variant="secondary">{{
                                    t('admin.seoCenter.privacyProtected')
                                }}</Badge>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Per-Locale Homepage Social Metadata & X Handle -->
            <form @submit.prevent="saveSocialSharing" class="space-y-4">
                <div class="max-w-xs space-y-1">
                    <Label for="twitter-site-handle">{{
                        t('admin.seoCenter.twitterHandleLabel')
                    }}</Label>
                    <Input
                        id="twitter-site-handle"
                        v-model="socialForm.twitter_site_handle"
                        placeholder="@sysnoxis"
                    />
                </div>

                <div class="grid gap-3 md:grid-cols-2">
                    <div
                        v-for="row in socialForm.home_overrides"
                        :key="row.code"
                        class="space-y-2 rounded-lg border border-border/80 bg-muted/15 p-3"
                    >
                        <div class="flex items-center justify-between">
                            <span class="text-xs font-semibold">
                                {{ row.code }} · {{ row.native_name }}
                            </span>
                        </div>
                        <Input
                            v-model="row.title"
                            :placeholder="row.default_title"
                            class="h-8 text-xs"
                        />
                        <Input
                            v-model="row.description"
                            :placeholder="row.default_description"
                            class="h-8 text-xs"
                        />
                    </div>
                </div>

                <Button
                    type="submit"
                    size="sm"
                    :disabled="socialForm.processing"
                >
                    {{ t('admin.seoCenter.saveSocialSharing') }}
                </Button>
            </form>
        </section>
    </div>
</template>
