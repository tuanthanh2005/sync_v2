<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import {
    CheckCircle2,
    Cloud,
    Gauge,
    KeyRound,
    Plug,
    RefreshCw,
    Send,
    ShieldCheck,
    Unplug,
} from '@lucide/vue';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import SeoIntegrationsController from '@/actions/App/Http/Controllers/Admin/Seo/SeoIntegrationsController';
import ConfirmActionDialog from '@/components/common/ConfirmActionDialog.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

const props = defineProps<{
    canonicalOrigin: string;
    canonicalHost: string;
    oauthCallbackUrl: string;
    verification: {
        property_identifier: string;
        method: string;
        token: string;
        status: string;
        verified_at: string | null;
        last_error: string | null;
        bing_meta_verification: string;
    };
    gsc: {
        status: string;
        auth_type: string;
        property_identifier: string | null;
        has_credentials: boolean;
        has_access_token: boolean;
        last_synced_at: string | null;
        last_error: string | null;
        inspection_quota_used_today: number;
        inspection_quota_limit_daily: number;
        setup_required: boolean;
        indexing_api_note: string;
    };
    indexNow: {
        enabled: boolean;
        key: string;
        key_url: string | null;
        queued_count: number;
        recent: Array<{
            id: number;
            url: string;
            status: string;
            http_status: number | null;
            attempts: number;
            response_excerpt: string | null;
            submitted_at: string | null;
        }>;
    };
    ga4: {
        enabled: boolean;
        measurement_id: string;
        require_consent: boolean;
    };
    cloudflare: {
        enabled: boolean;
        zone_id: string;
        has_token: boolean;
        status: string;
        last_synced_at: string | null;
        last_error: string | null;
    };
    pageSpeedConfigured: boolean;
    apiUsage: Array<{
        provider: string;
        endpoint: string;
        usage_date: string;
        requests_count: number;
        errors_count: number;
        quota_limit: number;
    }>;
}>();

const { t } = useI18n();
const disconnectDialogOpen = ref(false);
const gscAuthTab = ref<'service_account' | 'oauth2'>('service_account');

const verifyForm = useForm({
    method: props.verification.method,
    verification_token: props.verification.token,
    property_identifier: props.verification.property_identifier,
    bing_meta_verification: props.verification.bing_meta_verification,
});

const saForm = useForm({
    property_identifier:
        props.gsc.property_identifier || `sc-domain:${props.canonicalHost}`,
    service_account_json: '',
});

const oauthForm = useForm({
    property_identifier:
        props.gsc.property_identifier || `sc-domain:${props.canonicalHost}`,
    client_id: '',
    client_secret: '',
});

const ga4Form = useForm({
    enabled: props.ga4.enabled,
    measurement_id: props.ga4.measurement_id,
    require_consent: props.ga4.require_consent,
});

const cfForm = useForm({
    enabled: props.cloudflare.enabled,
    zone_id: props.cloudflare.zone_id,
    api_token: '',
});

const purgeForm = useForm({
    urls: '',
});

function saveVerification(): void {
    verifyForm.post(SeoIntegrationsController.saveVerification.url(), {
        preserveScroll: true,
    });
}

function checkVerification(): void {
    router.post(
        SeoIntegrationsController.checkVerification.url(),
        {},
        { preserveScroll: true },
    );
}

function saveServiceAccount(): void {
    saForm.post(SeoIntegrationsController.saveGscServiceAccount.url(), {
        preserveScroll: true,
        onSuccess: () => {
            saForm.service_account_json = '';
        },
    });
}

function saveOAuthClient(): void {
    oauthForm.post(SeoIntegrationsController.saveGscOAuthClient.url(), {
        preserveScroll: true,
        onSuccess: () => {
            oauthForm.client_secret = '';
        },
    });
}

function testGsc(): void {
    router.post(
        SeoIntegrationsController.testGscConnection.url(),
        {},
        { preserveScroll: true },
    );
}

function syncGscAnalytics(): void {
    router.post(
        SeoIntegrationsController.syncGscAnalytics.url(),
        {},
        { preserveScroll: true },
    );
}

function submitGscSitemap(): void {
    router.post(
        SeoIntegrationsController.submitGscSitemap.url(),
        {},
        { preserveScroll: true },
    );
}

function confirmDisconnectGsc(): void {
    router.post(
        SeoIntegrationsController.disconnectGsc.url(),
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                disconnectDialogOpen.value = false;
            },
        },
    );
}

function toggleIndexNow(): void {
    router.post(
        SeoIntegrationsController.toggleIndexNow.url(),
        { enabled: !props.indexNow.enabled },
        { preserveScroll: true },
    );
}

function regenerateIndexNowKey(): void {
    router.post(
        SeoIntegrationsController.regenerateIndexNowKey.url(),
        {},
        { preserveScroll: true },
    );
}

function submitIndexNowBatch(): void {
    router.post(
        SeoIntegrationsController.submitIndexNowBatch.url(),
        {},
        { preserveScroll: true },
    );
}

function saveGa4(): void {
    ga4Form.post(SeoIntegrationsController.saveGa4.url(), {
        preserveScroll: true,
    });
}

function saveCloudflare(): void {
    cfForm.post(SeoIntegrationsController.saveCloudflare.url(), {
        preserveScroll: true,
        onSuccess: () => {
            cfForm.api_token = '';
        },
    });
}

function purgeCloudflare(): void {
    purgeForm.post(SeoIntegrationsController.purgeCloudflare.url(), {
        preserveScroll: true,
    });
}
</script>

<template>
    <div class="space-y-6">
        <Head :title="t('admin.seo.integrationsTitle')" />

        <div>
            <h1
                class="text-xl font-bold tracking-tight text-foreground sm:text-2xl"
            >
                {{ t('admin.seo.integrationsTitle') }}
            </h1>
            <p class="mt-1 text-xs text-muted-foreground">
                {{ t('admin.seo.integrationsSubtitle') }}
            </p>
        </div>

        <!-- 1. Google Site Ownership Verification & Bing Meta -->
        <section
            class="space-y-4 rounded-xl border border-border bg-card p-5 shadow-xs"
        >
            <div class="flex flex-wrap items-center justify-between gap-2">
                <div>
                    <h2
                        class="flex items-center gap-2 text-sm font-semibold text-foreground"
                    >
                        <ShieldCheck class="size-4 text-primary" />
                        <span>{{
                            t('admin.seo.webmasterVerificationTitle')
                        }}</span>
                    </h2>
                    <p class="mt-0.5 text-xs text-muted-foreground">
                        {{ t('admin.seo.webmasterVerificationDesc') }}
                    </p>
                </div>
                <Badge
                    :variant="
                        verification.status === 'verified'
                            ? 'default'
                            : 'secondary'
                    "
                >
                    {{ verification.status }}
                </Badge>
            </div>

            <form
                @submit.prevent="saveVerification"
                class="grid gap-4 md:grid-cols-2"
            >
                <div class="space-y-1.5">
                    <Label for="verify-method">{{
                        t('admin.seoCenter.googleVerificationMethod')
                    }}</Label>
                    <select
                        id="verify-method"
                        v-model="verifyForm.method"
                        class="flex h-10 w-full rounded-lg border border-input bg-background px-3 text-sm text-foreground"
                    >
                        <option value="dns_txt">
                            {{ t('admin.seoCenter.methodDnsTxt') }}
                        </option>
                        <option value="dns_cname">
                            {{ t('admin.seoCenter.methodDnsCname') }}
                        </option>
                        <option value="html_file">
                            {{ t('admin.seoCenter.methodHtmlFile') }}
                        </option>
                        <option value="html_meta">
                            {{ t('admin.seoCenter.methodHtmlMeta') }}
                        </option>
                    </select>
                </div>
                <div class="space-y-1.5">
                    <Label for="verify-property">{{
                        t('admin.seoCenter.searchConsoleProperty')
                    }}</Label>
                    <Input
                        id="verify-property"
                        v-model="verifyForm.property_identifier"
                        :placeholder="
                            t('admin.seoCenter.propertyPlaceholder', {
                                host: canonicalHost,
                            })
                        "
                    />
                </div>
                <div class="space-y-1.5">
                    <Label for="verify-token">{{
                        t('admin.seoCenter.googleVerificationToken')
                    }}</Label>
                    <Input
                        id="verify-token"
                        v-model="verifyForm.verification_token"
                        :placeholder="
                            t(
                                'admin.seoCenter.googleVerificationTokenPlaceholder',
                            )
                        "
                    />
                </div>
                <div class="space-y-1.5">
                    <Label for="bing-meta">{{
                        t('admin.seoCenter.bingMetaToken')
                    }}</Label>
                    <Input
                        id="bing-meta"
                        v-model="verifyForm.bing_meta_verification"
                        :placeholder="
                            t('admin.seoCenter.bingMetaTokenPlaceholder')
                        "
                    />
                </div>
                <div class="flex flex-wrap items-center gap-2 md:col-span-2">
                    <Button
                        type="submit"
                        size="sm"
                        :disabled="verifyForm.processing"
                    >
                        {{ t('admin.seo.saveVerification') }}
                    </Button>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        @click="checkVerification"
                    >
                        <CheckCircle2 class="size-3.5" />
                        <span>{{ t('admin.seo.verifyNow') }}</span>
                    </Button>
                </div>
            </form>

            <p
                v-if="verification.last_error"
                class="rounded-lg bg-muted/40 p-3 text-xs text-muted-foreground"
            >
                {{ verification.last_error }}
            </p>
        </section>

        <!-- 2. Google Search Console Official API Integration -->
        <section
            class="space-y-4 rounded-xl border border-border bg-card p-5 shadow-xs"
        >
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2
                        class="flex items-center gap-2 text-sm font-semibold text-foreground"
                    >
                        <Plug class="size-4 text-primary" />
                        <span>{{ t('admin.seoCenter.gscApiTitle') }}</span>
                    </h2>
                    <p class="mt-0.5 text-xs text-muted-foreground">
                        {{ gsc.indexing_api_note }}
                    </p>
                </div>
                <div class="flex items-center gap-2">
                    <Badge
                        :variant="
                            gsc.status === 'connected'
                                ? 'default'
                                : gsc.status === 'error'
                                  ? 'destructive'
                                  : 'secondary'
                        "
                    >
                        {{ gsc.status }}
                    </Badge>
                    <Badge variant="outline">
                        {{
                            t('admin.seoCenter.quotaUsage', {
                                used: gsc.inspection_quota_used_today,
                                limit: gsc.inspection_quota_limit_daily,
                            })
                        }}
                    </Badge>
                </div>
            </div>

            <div class="flex flex-wrap gap-2">
                <Button
                    type="button"
                    size="sm"
                    :variant="
                        gscAuthTab === 'service_account' ? 'default' : 'outline'
                    "
                    @click="gscAuthTab = 'service_account'"
                >
                    {{ t('admin.seoCenter.serviceAccountJson') }}
                </Button>
                <Button
                    type="button"
                    size="sm"
                    :variant="gscAuthTab === 'oauth2' ? 'default' : 'outline'"
                    @click="gscAuthTab = 'oauth2'"
                >
                    {{ t('admin.seoCenter.oauthClient') }}
                </Button>
            </div>

            <form
                v-if="gscAuthTab === 'service_account'"
                @submit.prevent="saveServiceAccount"
                class="space-y-3"
            >
                <div class="space-y-1.5">
                    <Label for="sa-prop">{{
                        t('admin.seoCenter.propertyIdentifier')
                    }}</Label>
                    <Input
                        id="sa-prop"
                        v-model="saForm.property_identifier"
                        :placeholder="
                            t('admin.seoCenter.propertyPlaceholder', {
                                host: canonicalHost,
                            })
                        "
                    />
                </div>
                <div class="space-y-1.5">
                    <Label for="sa-json">{{
                        t('admin.seoCenter.serviceAccountJsonLabel')
                    }}</Label>
                    <textarea
                        id="sa-json"
                        v-model="saForm.service_account_json"
                        rows="4"
                        class="w-full rounded-lg border border-input bg-background p-3 font-mono text-xs text-foreground"
                        :placeholder="
                            t('admin.seoCenter.serviceAccountJsonPlaceholder')
                        "
                    />
                </div>
                <Button type="submit" size="sm" :disabled="saForm.processing">
                    {{ t('admin.seoCenter.saveEncryptedServiceAccount') }}
                </Button>
            </form>

            <form
                v-else
                @submit.prevent="saveOAuthClient"
                class="grid gap-3 md:grid-cols-2"
            >
                <div class="space-y-1.5 md:col-span-2">
                    <p class="text-xs text-muted-foreground">
                        {{ t('admin.seoCenter.authorizedRedirectUri') }}:
                        <code
                            class="rounded bg-muted px-1.5 py-0.5 font-mono text-[11px]"
                            >{{ oauthCallbackUrl }}</code
                        >
                    </p>
                </div>
                <div class="space-y-1.5 md:col-span-2">
                    <Label for="oauth-prop">{{
                        t('admin.seoCenter.propertyIdentifier')
                    }}</Label>
                    <Input
                        id="oauth-prop"
                        v-model="oauthForm.property_identifier"
                    />
                </div>
                <div class="space-y-1.5">
                    <Label for="oauth-id">{{
                        t('admin.seoCenter.oauthClientId')
                    }}</Label>
                    <Input id="oauth-id" v-model="oauthForm.client_id" />
                </div>
                <div class="space-y-1.5">
                    <Label for="oauth-secret">{{
                        t('admin.seoCenter.oauthClientSecret')
                    }}</Label>
                    <Input
                        id="oauth-secret"
                        v-model="oauthForm.client_secret"
                        type="password"
                    />
                </div>
                <div class="flex flex-wrap gap-2 md:col-span-2">
                    <Button
                        type="submit"
                        size="sm"
                        :disabled="oauthForm.processing"
                    >
                        {{ t('admin.seoCenter.saveOAuthClient') }}
                    </Button>
                    <Button
                        v-if="gsc.has_credentials && gsc.auth_type === 'oauth2'"
                        variant="outline"
                        size="sm"
                        as-child
                    >
                        <a
                            :href="
                                SeoIntegrationsController.redirectGscOAuth.url()
                            "
                        >
                            {{ t('admin.seoCenter.authorizeWithGoogle') }}
                        </a>
                    </Button>
                </div>
            </form>

            <div
                class="flex flex-wrap items-center gap-2 border-t border-border pt-3"
            >
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    @click="testGsc"
                >
                    <RefreshCw class="size-3.5" />
                    <span>{{ t('admin.seoCenter.testConnection') }}</span>
                </Button>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    @click="syncGscAnalytics"
                >
                    {{ t('admin.seoCenter.syncSearchAnalytics28d') }}
                </Button>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    @click="submitGscSitemap"
                >
                    {{ t('admin.seoCenter.submitSitemapViaApi') }}
                </Button>
                <Button
                    v-if="gsc.has_credentials || gsc.has_access_token"
                    type="button"
                    variant="destructive"
                    size="sm"
                    @click="disconnectDialogOpen = true"
                >
                    <Unplug class="size-3.5" />
                    <span>{{ t('admin.seoCenter.disconnect') }}</span>
                </Button>
            </div>

            <p
                v-if="gsc.last_error"
                class="rounded-lg bg-destructive/10 p-3 text-xs text-destructive"
            >
                {{ gsc.last_error }}
            </p>
        </section>

        <!-- 3. Bing IndexNow -->
        <section
            class="space-y-4 rounded-xl border border-border bg-card p-5 shadow-xs"
        >
            <div class="flex flex-wrap items-center justify-between gap-2">
                <div>
                    <h2
                        class="flex items-center gap-2 text-sm font-semibold text-foreground"
                    >
                        <Send class="size-4 text-primary" />
                        <span>{{
                            t('admin.seoCenter.bingIndexNowTitle')
                        }}</span>
                    </h2>
                    <p class="mt-0.5 text-xs text-muted-foreground">
                        {{ t('admin.seoCenter.verificationKeyFile') }}:
                        <code
                            v-if="indexNow.key_url"
                            class="rounded bg-muted px-1.5 py-0.5 font-mono text-[11px]"
                            >{{ indexNow.key_url }}</code
                        >
                    </p>
                </div>
                <div class="flex items-center gap-2">
                    <Badge
                        :variant="indexNow.enabled ? 'default' : 'secondary'"
                    >
                        {{
                            indexNow.enabled
                                ? t('admin.seoCenter.enabled')
                                : t('admin.seoCenter.disabled')
                        }}
                    </Badge>
                    <Badge variant="outline">{{
                        t('admin.seoCenter.queuedCount', {
                            count: indexNow.queued_count,
                        })
                    }}</Badge>
                </div>
            </div>

            <div class="flex flex-wrap gap-2">
                <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    @click="toggleIndexNow"
                >
                    {{
                        indexNow.enabled
                            ? t('admin.seoCenter.disableIndexNow')
                            : t('admin.seoCenter.enableIndexNow')
                    }}
                </Button>
                <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    @click="regenerateIndexNowKey"
                >
                    <KeyRound class="size-3.5" />
                    <span>{{ t('admin.seoCenter.regenerateKey') }}</span>
                </Button>
                <Button type="button" size="sm" @click="submitIndexNowBatch">
                    {{ t('admin.seoCenter.submitQueuedBatchNow') }}
                </Button>
            </div>
        </section>

        <!-- 4. Controlled GA4 & Cloudflare Cache Purge -->
        <div class="grid gap-6 lg:grid-cols-2">
            <section
                class="space-y-4 rounded-xl border border-border bg-card p-5 shadow-xs"
            >
                <div>
                    <h2
                        class="flex items-center gap-2 text-sm font-semibold text-foreground"
                    >
                        <Gauge class="size-4 text-primary" />
                        <span>{{ t('admin.seoCenter.ga4Title') }}</span>
                    </h2>
                    <p class="mt-0.5 text-xs text-muted-foreground">
                        {{ t('admin.seoCenter.ga4Desc') }}
                    </p>
                </div>
                <form @submit.prevent="saveGa4" class="space-y-3">
                    <label
                        class="flex items-center gap-2 text-xs font-medium text-foreground"
                    >
                        <input
                            v-model="ga4Form.enabled"
                            type="checkbox"
                            class="rounded border-input"
                        />
                        <span>{{
                            t('admin.seoCenter.enableGa4Tracking')
                        }}</span>
                    </label>
                    <div class="space-y-1.5">
                        <Label for="ga4-id">{{
                            t('admin.seoCenter.measurementId')
                        }}</Label>
                        <Input
                            id="ga4-id"
                            v-model="ga4Form.measurement_id"
                            :placeholder="
                                t('admin.seoCenter.measurementIdPlaceholder')
                            "
                        />
                    </div>
                    <label
                        class="flex items-center gap-2 text-xs text-muted-foreground"
                    >
                        <input
                            v-model="ga4Form.require_consent"
                            type="checkbox"
                            class="rounded border-input"
                        />
                        <span>{{
                            t('admin.seoCenter.respectAnalyticsConsent')
                        }}</span>
                    </label>
                    <Button
                        type="submit"
                        size="sm"
                        :disabled="ga4Form.processing"
                        >{{ t('admin.seoCenter.saveGa4Settings') }}</Button
                    >
                </form>
            </section>

            <section
                class="space-y-4 rounded-xl border border-border bg-card p-5 shadow-xs"
            >
                <div class="flex items-center justify-between gap-2">
                    <div>
                        <h2
                            class="flex items-center gap-2 text-sm font-semibold text-foreground"
                        >
                            <Cloud class="size-4 text-primary" />
                            <span>{{
                                t('admin.seoCenter.cloudflareTitle')
                            }}</span>
                        </h2>
                        <p class="mt-0.5 text-xs text-muted-foreground">
                            {{ t('admin.seoCenter.cloudflareDesc') }}
                        </p>
                    </div>
                    <Badge variant="secondary">{{ cloudflare.status }}</Badge>
                </div>

                <form @submit.prevent="saveCloudflare" class="space-y-3">
                    <label
                        class="flex items-center gap-2 text-xs font-medium text-foreground"
                    >
                        <input
                            v-model="cfForm.enabled"
                            type="checkbox"
                            class="rounded border-input"
                        />
                        <span>{{
                            t('admin.seoCenter.enableCloudflareAdapter')
                        }}</span>
                    </label>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div class="space-y-1.5">
                            <Label for="cf-zone">{{
                                t('admin.seoCenter.zoneId')
                            }}</Label>
                            <Input id="cf-zone" v-model="cfForm.zone_id" />
                        </div>
                        <div class="space-y-1.5">
                            <Label for="cf-token">{{
                                t('admin.seoCenter.apiTokenCachePurge')
                            }}</Label>
                            <Input
                                id="cf-token"
                                v-model="cfForm.api_token"
                                type="password"
                                :placeholder="
                                    cloudflare.has_token
                                        ? t(
                                              'admin.seoCenter.tokenStoredEncrypted',
                                          )
                                        : t('admin.seoCenter.pasteToken')
                                "
                            />
                        </div>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <Button
                            type="submit"
                            size="sm"
                            :disabled="cfForm.processing"
                            >{{ t('admin.seoCenter.saveCloudflare') }}</Button
                        >
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            @click="purgeCloudflare"
                        >
                            {{ t('admin.seoCenter.purgeSitemapRobotsCache') }}
                        </Button>
                    </div>
                </form>
            </section>
        </div>

        <!-- 5. API Usage & Quota Log -->
        <section class="rounded-xl border border-border bg-card p-5 shadow-xs">
            <h2 class="text-sm font-semibold text-foreground">
                {{ t('admin.seoCenter.apiUsageTitle') }}
            </h2>
            <p
                v-if="apiUsage.length === 0"
                class="mt-2 text-xs text-muted-foreground"
            >
                {{ t('admin.seoCenter.noApiUsageYet') }}
            </p>
            <div v-else class="mt-3 overflow-x-auto">
                <table class="w-full text-left text-xs">
                    <thead class="border-b border-border text-muted-foreground">
                        <tr>
                            <th class="py-2 pr-3 font-medium">
                                {{ t('admin.seoCenter.date') }}
                            </th>
                            <th class="px-2 py-2 font-medium">
                                {{ t('admin.seoCenter.provider') }}
                            </th>
                            <th class="px-2 py-2 font-medium">
                                {{ t('admin.seoCenter.endpoint') }}
                            </th>
                            <th class="px-2 py-2 text-right font-medium">
                                {{ t('admin.seoCenter.requests') }}
                            </th>
                            <th class="px-2 py-2 text-right font-medium">
                                {{ t('admin.seoCenter.errors') }}
                            </th>
                            <th class="py-2 pl-2 text-right font-medium">
                                {{ t('admin.seoCenter.quota') }}
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border/60">
                        <tr v-for="(u, idx) in apiUsage" :key="idx">
                            <td class="py-2 pr-3 font-mono">
                                {{ u.usage_date }}
                            </td>
                            <td class="px-2 py-2">{{ u.provider }}</td>
                            <td class="px-2 py-2 font-mono">
                                {{ u.endpoint }}
                            </td>
                            <td class="px-2 py-2 text-right">
                                {{ u.requests_count }}
                            </td>
                            <td class="px-2 py-2 text-right">
                                {{ u.errors_count }}
                            </td>
                            <td class="py-2 pl-2 text-right">
                                {{ u.quota_limit }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <ConfirmActionDialog
            :open="disconnectDialogOpen"
            :title="t('admin.seoCenter.disconnectGscTitle')"
            :description="t('admin.seoCenter.disconnectGscDesc')"
            :confirm-label="t('admin.seoCenter.disconnect')"
            destructive
            @update:open="(v) => (disconnectDialogOpen = v)"
            @confirm="confirmDisconnectGsc"
        />
    </div>
</template>
