<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import {
    Award,
    Bell,
    Check,
    Copy,
    ExternalLink,
    Gift,
    Mail,
    QrCode,
    Share2,
    Sparkles,
    Trophy,
    Users,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import ReferralController from '@/actions/App/Http/Controllers/Growth/ReferralController';
import MemberBadges from '@/components/growth/MemberBadges.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { formatDateTime } from '@/lib/formatters';
import { appToast } from '@/lib/toast';
import { premium as accountPremiumRoute } from '@/routes/account';
import type { MemberBadgeItem } from '@/types';

const props = defineProps<{
    referral: {
        enabled: boolean;
        verificationHours: number;
        attributionDays: number;
        code: string;
        isCodeActive: boolean;
        referralLink: string;
        stats: {
            visits: number;
            total_referred: number;
            pending: number;
            qualified: number;
            rejected: number;
            premium_days_earned: number;
        };
        nextMilestone: {
            name: string;
            required: number;
            remaining: number;
            cumulative_days: number;
            progress_percent: number;
        } | null;
        milestones: Array<{
            id: number;
            name: string;
            required_qualified_referrals: number;
            cumulative_premium_days: number;
            reached: boolean;
            claimed: boolean;
        }>;
        referrals: Array<{
            id: number;
            referee_display: string;
            status: string;
            status_label: string;
            status_reason: string | null;
            eligible_at: string | null;
            qualified_at: string | null;
            created_at: string | null;
        }>;
        rewardHistory: Array<{
            id: number;
            milestone_referrals: number;
            days_granted: number;
            cumulative_days_target: number;
            source: string;
            status: string;
            note: string | null;
            granted_at: string;
        }>;
    };
    earlyMember: {
        enabled: boolean;
        enrolled: boolean;
        member_number: number | null;
        premium_days_granted: number;
        total_claimed: number;
        max_slots: number;
    };
    badges: MemberBadgeItem[];
    achievements: Array<{
        id: number;
        slug: string;
        name: string;
        description: string;
        icon: string;
        color: string;
        unlocked: boolean;
        unlocked_at: string | null;
    }>;
    notificationPreferences: {
        weekly_digest: boolean;
        reengagement: boolean;
        referral_updates: boolean;
        community_updates: boolean;
        marketing_emails: boolean;
    };
}>();

const { t, locale } = useI18n();
const copied = ref(false);
const showQr = ref(false);

const prefForm = useForm({
    weekly_digest: props.notificationPreferences.weekly_digest,
    reengagement: props.notificationPreferences.reengagement,
    referral_updates: props.notificationPreferences.referral_updates,
    community_updates: props.notificationPreferences.community_updates,
    marketing_emails: props.notificationPreferences.marketing_emails,
});

const encodedLink = computed(() =>
    encodeURIComponent(props.referral.referralLink),
);
const shareMessage = computed(() =>
    encodeURIComponent(
        `${t('growth.referral.shareMessage')} ${props.referral.referralLink}`,
    ),
);

const shareLinks = computed(() => ({
    facebook: `https://www.facebook.com/sharer/sharer.php?u=${encodedLink.value}`,
    x: `https://twitter.com/intent/tweet?text=${shareMessage.value}`,
    telegram: `https://t.me/share/url?url=${encodedLink.value}&text=${encodeURIComponent(t('growth.referral.shareMessage'))}`,
    whatsapp: `https://wa.me/?text=${shareMessage.value}`,
    email: `mailto:?subject=${encodeURIComponent('SyncViet')}&body=${shareMessage.value}`,
}));

// Deterministic local visual matrix derived from referral code (zero external QR CDN tracking!)
const qrCells = computed(() => {
    const seed = props.referral.code + '|' + props.referral.referralLink;
    const size = 21;
    const cells: boolean[][] = [];
    let hash = 2166136261;
    for (let i = 0; i < seed.length; i++) {
        hash ^= seed.charCodeAt(i);
        hash = Math.imul(hash, 16777619);
    }
    for (let r = 0; r < size; r++) {
        const row: boolean[] = [];
        for (let c = 0; c < size; c++) {
            const inTopLeft = r < 7 && c < 7;
            const inTopRight = r < 7 && c >= size - 7;
            const inBottomLeft = r >= size - 7 && c < 7;
            if (inTopLeft || inTopRight || inBottomLeft) {
                const lr = r >= size - 7 ? r - (size - 7) : r;
                const lc = c >= size - 7 ? c - (size - 7) : c;
                const border = lr === 0 || lr === 6 || lc === 0 || lc === 6;
                const center = lr >= 2 && lr <= 4 && lc >= 2 && lc <= 4;
                row.push(border || center);
            } else {
                const bit =
                    ((hash >>> ((r * 3 + c * 5) % 24)) ^ (r * 7 + c * 11)) & 1;
                row.push(bit === 1);
            }
        }
        cells.push(row);
    }
    return cells;
});

async function copyReferralLink(): Promise<void> {
    try {
        await navigator.clipboard.writeText(props.referral.referralLink);
        copied.value = true;
        appToast.success(t('growth.referral.linkCopied'));
        setTimeout(() => {
            copied.value = false;
        }, 2000);
    } catch {
        appToast.error(t('common.copyFailed'));
    }
}

async function nativeShare(): Promise<void> {
    if (typeof navigator !== 'undefined' && 'share' in navigator) {
        try {
            await navigator.share({
                title: 'SyncViet',
                text: t('growth.referral.shareMessage'),
                url: props.referral.referralLink,
            });
            return;
        } catch {
            // User dismissed native share dialog
        }
    }
    await copyReferralLink();
}

function savePreferences(): void {
    prefForm.post(ReferralController.updateNotificationPreferences.url(), {
        preserveScroll: true,
    });
}
</script>

<template>
    <div class="space-y-6">
        <Head :title="t('growth.referral.pageTitle')" />

        <!-- Hero Referral Link Card -->
        <section class="rounded-2xl border border-border bg-card p-5 sm:p-6">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="space-y-1.5">
                    <div class="flex flex-wrap items-center gap-2">
                        <Gift class="size-5 text-primary" />
                        <h1 class="text-xl font-bold text-foreground">
                            {{ t('growth.referral.heading') }}
                        </h1>
                        <MemberBadges
                            v-if="badges.length > 0"
                            :badges="badges"
                            size="md"
                            :max="3"
                        />
                    </div>
                    <p class="max-w-2xl text-sm text-muted-foreground">
                        {{ t('growth.referral.subtitle') }}
                    </p>
                </div>

                <Button variant="outline" size="sm" as-child>
                    <Link :href="accountPremiumRoute.url()">
                        <Sparkles class="size-4" />
                        <span>{{ t('growth.referral.viewPremium') }}</span>
                    </Link>
                </Button>
            </div>

            <!-- Code & Copy Link Box -->
            <div
                class="mt-5 grid gap-4 rounded-xl border border-border bg-muted/30 p-4 md:grid-cols-[1fr_auto]"
            >
                <div class="min-w-0 space-y-2">
                    <div class="flex flex-wrap items-center gap-2 text-xs">
                        <span class="text-muted-foreground"
                            >{{ t('growth.referral.yourCode') }}:</span
                        >
                        <code
                            class="rounded-md border border-primary/30 bg-primary-soft px-2 py-0.5 font-mono text-sm font-bold text-primary"
                        >
                            {{ referral.code }}
                        </code>
                        <Badge
                            :variant="
                                referral.isCodeActive
                                    ? 'default'
                                    : 'destructive'
                            "
                        >
                            {{
                                referral.isCodeActive
                                    ? t('growth.referral.active')
                                    : t('growth.referral.disabled')
                            }}
                        </Badge>
                    </div>

                    <div
                        class="flex flex-wrap items-center gap-2 rounded-lg border border-input bg-background px-3 py-2"
                    >
                        <span
                            class="min-w-0 flex-1 truncate font-mono text-xs text-foreground"
                        >
                            {{ referral.referralLink }}
                        </span>
                        <Button
                            type="button"
                            size="sm"
                            @click="copyReferralLink"
                        >
                            <Check v-if="copied" class="size-3.5" />
                            <Copy v-else class="size-3.5" />
                            <span>{{
                                copied
                                    ? t('growth.referral.copied')
                                    : t('growth.referral.copyLink')
                            }}</span>
                        </Button>
                    </div>

                    <!-- Share Buttons (Zero contact harvesting / zero auto-spam!) -->
                    <div class="flex flex-wrap items-center gap-2 pt-1">
                        <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            @click="nativeShare"
                        >
                            <Share2 class="size-3.5" />
                            <span>{{ t('growth.referral.share') }}</span>
                        </Button>
                        <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            @click="showQr = !showQr"
                        >
                            <QrCode class="size-3.5" />
                            <span>{{ t('growth.extra.qrButton') }}</span>
                        </Button>
                        <a
                            :href="shareLinks.facebook"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="inline-flex h-8 items-center gap-1 rounded-lg border border-border bg-card px-2.5 text-xs font-medium hover:bg-muted"
                        >
                            Facebook <ExternalLink class="size-3" />
                        </a>
                        <a
                            :href="shareLinks.x"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="inline-flex h-8 items-center gap-1 rounded-lg border border-border bg-card px-2.5 text-xs font-medium hover:bg-muted"
                        >
                            X <ExternalLink class="size-3" />
                        </a>
                        <a
                            :href="shareLinks.telegram"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="inline-flex h-8 items-center gap-1 rounded-lg border border-border bg-card px-2.5 text-xs font-medium hover:bg-muted"
                        >
                            Telegram <ExternalLink class="size-3" />
                        </a>
                        <a
                            :href="shareLinks.whatsapp"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="inline-flex h-8 items-center gap-1 rounded-lg border border-border bg-card px-2.5 text-xs font-medium hover:bg-muted"
                        >
                            WhatsApp <ExternalLink class="size-3" />
                        </a>
                        <a
                            :href="shareLinks.email"
                            class="inline-flex h-8 items-center gap-1 rounded-lg border border-border bg-card px-2.5 text-xs font-medium hover:bg-muted"
                        >
                            <Mail class="size-3" />
                            {{ t('growth.extra.shareEmail') }}
                        </a>
                    </div>
                </div>

                <!-- Local Inline SVG QR Display -->
                <div
                    v-if="showQr"
                    class="flex flex-col items-center justify-center rounded-xl border border-border bg-white p-3 text-slate-900"
                >
                    <svg
                        viewBox="0 0 21 21"
                        class="size-28"
                        shape-rendering="crispEdges"
                        :aria-label="t('growth.extra.qrAriaLabel')"
                    >
                        <rect width="21" height="21" fill="#ffffff" />
                        <template v-for="(row, r) in qrCells" :key="r">
                            <template v-for="(on, c) in row" :key="`${r}-${c}`">
                                <rect
                                    v-if="on"
                                    :x="c"
                                    :y="r"
                                    width="1"
                                    height="1"
                                    fill="#0f172a"
                                />
                            </template>
                        </template>
                    </svg>
                    <span class="mt-1 font-mono text-[10px] font-semibold">
                        {{ referral.code }}
                    </span>
                </div>
            </div>
        </section>

        <!-- Stats Grid -->
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
            <div class="rounded-xl border border-border bg-card p-4">
                <p class="text-xs text-muted-foreground">
                    {{ t('growth.referral.statTotal') }}
                </p>
                <p class="mt-1 text-2xl font-bold">
                    {{ referral.stats.total_referred }}
                </p>
            </div>
            <div class="rounded-xl border border-border bg-card p-4">
                <p class="text-xs text-muted-foreground">
                    {{ t('growth.referral.statPending') }}
                </p>
                <p
                    class="mt-1 text-2xl font-bold text-amber-600 dark:text-amber-400"
                >
                    {{ referral.stats.pending }}
                </p>
            </div>
            <div class="rounded-xl border border-border bg-card p-4">
                <p class="text-xs text-muted-foreground">
                    {{ t('growth.referral.statQualified') }}
                </p>
                <p
                    class="mt-1 text-2xl font-bold text-emerald-600 dark:text-emerald-400"
                >
                    {{ referral.stats.qualified }}
                </p>
            </div>
            <div class="rounded-xl border border-border bg-card p-4">
                <p class="text-xs text-muted-foreground">
                    {{ t('growth.referral.statRejected') }}
                </p>
                <p class="mt-1 text-2xl font-bold text-muted-foreground">
                    {{ referral.stats.rejected }}
                </p>
            </div>
            <div class="rounded-xl border border-border bg-card p-4">
                <p class="text-xs text-muted-foreground">
                    {{ t('growth.referral.statDaysEarned') }}
                </p>
                <p class="mt-1 text-2xl font-bold text-primary">
                    {{
                        t('growth.extra.daysShort', {
                            count: referral.stats.premium_days_earned,
                        })
                    }}
                </p>
            </div>
        </div>

        <!-- Milestone Progress Bar & Cumulative Rewards Timeline -->
        <section class="space-y-4 rounded-2xl border border-border bg-card p-5">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <div>
                    <h2 class="text-base font-semibold text-foreground">
                        {{ t('growth.referral.progressTitle') }}
                    </h2>
                    <p
                        v-if="referral.nextMilestone"
                        class="text-xs text-muted-foreground"
                    >
                        {{
                            t('growth.referral.progressSummary', {
                                qualified: referral.stats.qualified,
                                target: referral.nextMilestone.required,
                                remaining: referral.nextMilestone.remaining,
                                days: referral.nextMilestone.cumulative_days,
                            })
                        }}
                    </p>
                </div>
                <Badge variant="outline">
                    {{
                        t('growth.referral.verificationWindow', {
                            hours: referral.verificationHours,
                        })
                    }}
                </Badge>
            </div>

            <div
                v-if="referral.nextMilestone"
                class="h-2.5 w-full overflow-hidden rounded-full bg-muted"
            >
                <div
                    class="h-full rounded-full bg-primary transition-[width] duration-300"
                    :style="{
                        width: `${referral.nextMilestone.progress_percent}%`,
                    }"
                />
            </div>

            <div class="grid gap-2.5 sm:grid-cols-2 lg:grid-cols-4">
                <div
                    v-for="m in referral.milestones"
                    :key="m.id"
                    :class="[
                        'rounded-xl border p-3 text-xs',
                        m.claimed
                            ? 'border-emerald-500/40 bg-emerald-500/5'
                            : m.reached
                              ? 'border-primary/40 bg-primary-soft/50'
                              : 'border-border bg-background',
                    ]"
                >
                    <div class="flex items-center justify-between">
                        <span class="font-semibold text-foreground">{{
                            m.name
                        }}</span>
                        <Badge
                            :variant="
                                m.claimed
                                    ? 'default'
                                    : m.reached
                                      ? 'secondary'
                                      : 'outline'
                            "
                        >
                            {{
                                t('growth.extra.daysShort', {
                                    count: m.cumulative_premium_days,
                                })
                            }}
                        </Badge>
                    </div>
                    <p class="mt-1 text-muted-foreground">
                        {{ m.required_qualified_referrals }}
                        {{ t('growth.referral.qualifiedUnit') }} ·
                        {{
                            m.claimed
                                ? t('growth.referral.claimed')
                                : t('growth.referral.cumulativeTotal')
                        }}
                    </p>
                </div>
            </div>
        </section>

        <!-- Early Member Program & Achievements -->
        <div class="grid gap-6 lg:grid-cols-2">
            <section
                class="space-y-3 rounded-2xl border border-border bg-card p-5"
            >
                <h2 class="flex items-center gap-2 text-sm font-semibold">
                    <Award class="size-4 text-amber-500" />
                    {{ t('growth.earlyMember.title') }}
                </h2>
                <p class="text-xs text-muted-foreground">
                    {{ t('growth.earlyMember.description') }}
                </p>
                <div
                    class="rounded-xl border border-border bg-muted/30 p-3.5 text-xs"
                >
                    <div class="flex items-center justify-between">
                        <span class="font-medium text-foreground">
                            {{
                                earlyMember.enrolled
                                    ? t('growth.earlyMember.yourNumber', {
                                          num: earlyMember.member_number,
                                      })
                                    : earlyMember.enabled
                                      ? t('growth.earlyMember.openStatus')
                                      : t('growth.earlyMember.pausedStatus')
                            }}
                        </span>
                        <span class="font-mono text-muted-foreground">
                            {{ earlyMember.total_claimed }} /
                            {{ earlyMember.max_slots }}
                        </span>
                    </div>
                </div>

                <h3
                    class="flex items-center gap-1.5 pt-2 text-xs font-semibold"
                >
                    <Trophy class="size-3.5 text-primary" />
                    {{ t('growth.achievements.title') }}
                </h3>
                <div class="grid gap-2 sm:grid-cols-2">
                    <div
                        v-for="a in achievements"
                        :key="a.id"
                        :class="[
                            'rounded-lg border p-2.5 text-xs',
                            a.unlocked
                                ? 'border-primary/35 bg-primary-soft/40'
                                : 'border-border opacity-75',
                        ]"
                    >
                        <div class="flex items-center justify-between gap-1">
                            <span class="font-semibold text-foreground">{{
                                a.name
                            }}</span>
                            <Badge
                                :variant="a.unlocked ? 'default' : 'outline'"
                            >
                                {{
                                    a.unlocked
                                        ? t('growth.achievements.unlocked')
                                        : t('growth.achievements.locked')
                                }}
                            </Badge>
                        </div>
                        <p class="mt-1 text-[11px] text-muted-foreground">
                            {{ a.description }}
                        </p>
                    </div>
                </div>
            </section>

            <!-- Referral & Reward History + Notification Preferences -->
            <section
                class="space-y-4 rounded-2xl border border-border bg-card p-5"
            >
                <h2 class="flex items-center gap-2 text-sm font-semibold">
                    <Users class="size-4 text-primary" />
                    {{ t('growth.referral.historyTitle') }}
                </h2>
                <div
                    v-if="referral.referrals.length === 0"
                    class="py-4 text-center text-xs text-muted-foreground"
                >
                    {{ t('growth.referral.emptyHistory') }}
                </div>
                <ul v-else class="divide-y divide-border/60 text-xs">
                    <li
                        v-for="r in referral.referrals.slice(0, 10)"
                        :key="r.id"
                        class="flex items-center justify-between gap-2 py-2"
                    >
                        <div class="min-w-0">
                            <p class="font-medium text-foreground">
                                {{ r.referee_display }}
                            </p>
                            <p
                                v-if="r.status_reason"
                                class="truncate text-[11px] text-muted-foreground"
                            >
                                {{ r.status_reason }}
                            </p>
                        </div>
                        <Badge variant="outline">{{ r.status_label }}</Badge>
                    </li>
                </ul>

                <div
                    v-if="referral.rewardHistory.length > 0"
                    class="border-t border-border pt-3"
                >
                    <h3 class="mb-2 text-xs font-semibold">
                        {{ t('growth.referral.rewardHistoryTitle') }}
                    </h3>
                    <ul class="space-y-1.5 text-xs">
                        <li
                            v-for="rw in referral.rewardHistory"
                            :key="rw.id"
                            class="flex items-center justify-between gap-2"
                        >
                            <span class="truncate text-foreground"
                                >+{{
                                    t('growth.extra.daysShort', {
                                        count: rw.days_granted,
                                    })
                                }}
                                · {{ rw.note || rw.source }}</span
                            >
                            <span class="shrink-0 text-muted-foreground">{{
                                formatDateTime(rw.granted_at, locale)
                            }}</span>
                        </li>
                    </ul>
                </div>

                <!-- Retention & Digest Preferences (Respects Opt-Out) -->
                <form
                    @submit.prevent="savePreferences"
                    class="space-y-2.5 border-t border-border pt-4 text-xs"
                >
                    <div class="flex items-center justify-between">
                        <h3 class="flex items-center gap-1.5 font-semibold">
                            <Bell class="size-3.5 text-primary" />
                            {{ t('growth.preferences.title') }}
                        </h3>
                        <Button
                            type="submit"
                            size="sm"
                            variant="outline"
                            :disabled="prefForm.processing"
                        >
                            {{ t('common.save') }}
                        </Button>
                    </div>
                    <label class="flex items-center justify-between gap-2">
                        <span>{{ t('growth.preferences.weeklyDigest') }}</span>
                        <input
                            v-model="prefForm.weekly_digest"
                            type="checkbox"
                            class="size-4 accent-primary"
                        />
                    </label>
                    <label class="flex items-center justify-between gap-2">
                        <span>{{ t('growth.preferences.reengagement') }}</span>
                        <input
                            v-model="prefForm.reengagement"
                            type="checkbox"
                            class="size-4 accent-primary"
                        />
                    </label>
                    <label class="flex items-center justify-between gap-2">
                        <span>{{
                            t('growth.preferences.referralUpdates')
                        }}</span>
                        <input
                            v-model="prefForm.referral_updates"
                            type="checkbox"
                            class="size-4 accent-primary"
                        />
                    </label>
                    <label class="flex items-center justify-between gap-2">
                        <span>{{
                            t('growth.preferences.marketingEmails')
                        }}</span>
                        <input
                            v-model="prefForm.marketing_emails"
                            type="checkbox"
                            class="size-4 accent-primary"
                        />
                    </label>
                </form>
            </section>
        </div>
    </div>
</template>
