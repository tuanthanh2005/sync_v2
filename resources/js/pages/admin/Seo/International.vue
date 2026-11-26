<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { CheckCircle2, Globe2, Languages, ShieldAlert } from '@lucide/vue';
import { useI18n } from 'vue-i18n';
import SeoInternationalAndAuditController from '@/actions/App/Http/Controllers/Admin/Seo/SeoInternationalAndAuditController';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

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
    </div>
</template>
