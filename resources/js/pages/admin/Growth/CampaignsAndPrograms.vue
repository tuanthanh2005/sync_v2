<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { Award, Crown, Plus, Trophy } from '@lucide/vue';
import { useI18n } from 'vue-i18n';
import GrowthCampaignsAndProgramsController from '@/actions/App/Http/Controllers/Admin/Growth/GrowthCampaignsAndProgramsController';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

defineProps<{
    campaigns: Array<{
        id: number;
        slug: string;
        name: string;
        type: string;
        status: string;
        status_label: string;
        max_participants: number | null;
        current_participants: number;
        max_reward_days_budget: number | null;
        used_reward_days_budget: number;
        versions_count: number;
    }>;
    earlyMembers: Array<{
        id: number;
        member_number: number;
        username: string | null;
        status: string;
        premium_days_granted: number;
    }>;
    earlySummary: {
        enabled: boolean;
        max_slots: number;
        active_count: number;
        highest_number: number;
    };
    badges: Array<{
        id: number;
        username: string | null;
        badge_type: string;
        grant_source: string;
        is_active: boolean;
    }>;
    achievements: Array<{
        id: number;
        name: string;
        description: string;
        criteria_type: string;
        is_active: boolean;
        unlocked_count: number;
    }>;
    campaignStatuses: Array<{ value: string; label: string }>;
    badgeTypes: Array<{ value: string; label: string }>;
}>();

const { t } = useI18n();

const campForm = useForm({
    name: '',
    type: 'referral',
    status: 'draft',
    max_participants: 1000 as number | string,
    max_reward_days_budget: 10000 as number | string,
});

const earlyForm = useForm({ username: '' });
const badgeForm = useForm({
    username: '',
    badge_type: 'founding_member',
    source: 'admin_grant',
});

function submitCampaign(): void {
    campForm.post(GrowthCampaignsAndProgramsController.storeCampaign.url(), {
        preserveScroll: true,
        onSuccess: () => campForm.reset('name'),
    });
}

function setCampaignStatus(id: number, status: string): void {
    router.patch(
        GrowthCampaignsAndProgramsController.updateCampaign.url(id),
        { status },
        { preserveScroll: true },
    );
}

function submitEarlyEnroll(): void {
    earlyForm.post(
        GrowthCampaignsAndProgramsController.enrollEarlyMember.url(),
        {
            preserveScroll: true,
            onSuccess: () => earlyForm.reset(),
        },
    );
}

function submitGrantBadge(): void {
    badgeForm.post(GrowthCampaignsAndProgramsController.grantBadge.url(), {
        preserveScroll: true,
        onSuccess: () => badgeForm.reset('username'),
    });
}

function toggleAchievement(id: number): void {
    router.patch(
        GrowthCampaignsAndProgramsController.toggleAchievement.url(id),
        {},
        { preserveScroll: true },
    );
}
</script>

<template>
    <div class="space-y-6">
        <Head :title="t('admin.growth.campaignsTitle')" />

        <div class="rounded-xl border border-border bg-card p-5">
            <h1 class="flex items-center gap-2 text-xl font-bold">
                <Crown class="size-5 text-primary" />
                {{ t('admin.growth.campaignsTitle') }}
            </h1>
            <p class="mt-1 text-sm text-muted-foreground">
                {{ t('admin.growth.campaignsSubtitle') }}
            </p>
        </div>

        <!-- Growth Campaigns -->
        <section class="space-y-4 rounded-xl border border-border bg-card p-5">
            <h2 class="text-sm font-semibold">
                Growth Campaigns ({{ campaigns.length }})
            </h2>
            <div class="space-y-2.5">
                <div
                    v-for="c in campaigns"
                    :key="c.id"
                    class="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-border p-3 text-xs"
                >
                    <div>
                        <p class="font-semibold text-foreground">
                            {{ c.name }}
                            <Badge variant="outline" class="ml-1">{{
                                c.type
                            }}</Badge>
                            <Badge
                                :variant="
                                    c.status === 'active'
                                        ? 'default'
                                        : 'secondary'
                                "
                                class="ml-1"
                            >
                                {{ c.status_label }}
                            </Badge>
                        </p>
                        <p class="text-muted-foreground">
                            Participants: {{ c.current_participants }}/{{
                                c.max_participants ?? '∞'
                            }}
                            · Reward budget: {{ c.used_reward_days_budget }}/{{
                                c.max_reward_days_budget ?? '∞'
                            }}d · v{{ c.versions_count }}
                        </p>
                    </div>
                    <div class="flex gap-1.5">
                        <Button
                            v-if="c.status !== 'active'"
                            size="sm"
                            variant="outline"
                            @click="setCampaignStatus(c.id, 'active')"
                        >
                            Activate
                        </Button>
                        <Button
                            v-if="c.status === 'active'"
                            size="sm"
                            variant="ghost"
                            @click="setCampaignStatus(c.id, 'paused')"
                        >
                            Pause
                        </Button>
                    </div>
                </div>
            </div>

            <form
                @submit.prevent="submitCampaign"
                class="grid gap-2 border-t border-border pt-3 sm:grid-cols-4"
            >
                <Input
                    v-model="campForm.name"
                    required
                    placeholder="New Campaign Name"
                />
                <select
                    v-model="campForm.type"
                    class="h-9 rounded-lg border border-input bg-background px-2 text-xs"
                >
                    <option value="referral">Referral</option>
                    <option value="launch">Launch</option>
                    <option value="early_member">Early Member</option>
                    <option value="founding_member">Founding Member</option>
                    <option value="community_growth">Community Growth</option>
                    <option value="seasonal">Seasonal</option>
                </select>
                <Input
                    v-model.number="campForm.max_participants"
                    type="number"
                    min="1"
                    placeholder="Max Participants"
                />
                <Button type="submit" size="sm" :disabled="campForm.processing">
                    <Plus class="size-3.5" />
                    <span>Create Draft</span>
                </Button>
            </form>
        </section>

        <!-- Early Members & Member Badges -->
        <div class="grid gap-6 lg:grid-cols-2">
            <section
                class="space-y-3 rounded-xl border border-border bg-card p-5"
            >
                <div class="flex items-center justify-between">
                    <h2 class="flex items-center gap-2 text-sm font-semibold">
                        <Award class="size-4 text-amber-500" />
                        Early Members ({{ earlySummary.active_count }} /
                        {{ earlySummary.max_slots }})
                    </h2>
                    <Badge
                        :variant="
                            earlySummary.enabled ? 'default' : 'secondary'
                        "
                    >
                        {{ earlySummary.enabled ? 'Active' : 'Paused' }}
                    </Badge>
                </div>
                <form @submit.prevent="submitEarlyEnroll" class="flex gap-2">
                    <Input
                        v-model="earlyForm.username"
                        required
                        placeholder="Verified human username"
                    />
                    <Button
                        type="submit"
                        size="sm"
                        :disabled="earlyForm.processing"
                        >Enroll</Button
                    >
                </form>
                <InputError :message="earlyForm.errors.username" />
                <ul class="divide-y divide-border/60 text-xs">
                    <li
                        v-for="e in earlyMembers.slice(0, 10)"
                        :key="e.id"
                        class="flex items-center justify-between py-1.5"
                    >
                        <span
                            >#{{ e.member_number }} @{{ e.username }} (+{{
                                e.premium_days_granted
                            }}d)</span
                        >
                        <Badge variant="outline">{{ e.status }}</Badge>
                    </li>
                </ul>
            </section>

            <section
                class="space-y-3 rounded-xl border border-border bg-card p-5"
            >
                <h2 class="text-sm font-semibold">
                    Member Badges & Founding Members
                </h2>
                <form
                    @submit.prevent="submitGrantBadge"
                    class="grid gap-2 sm:grid-cols-3"
                >
                    <div>
                        <Label for="bdg_user" class="sr-only">Username</Label>
                        <Input
                            id="bdg_user"
                            v-model="badgeForm.username"
                            required
                            placeholder="Username"
                        />
                    </div>
                    <select
                        v-model="badgeForm.badge_type"
                        class="h-9 rounded-lg border border-input bg-background px-2 text-xs"
                    >
                        <option
                            v-for="bt in badgeTypes"
                            :key="bt.value"
                            :value="bt.value"
                        >
                            {{ bt.label }}
                        </option>
                    </select>
                    <Button
                        type="submit"
                        size="sm"
                        :disabled="badgeForm.processing"
                    >
                        Grant Badge
                    </Button>
                </form>
                <InputError :message="badgeForm.errors.username" />
                <ul class="divide-y divide-border/60 text-xs">
                    <li
                        v-for="b in badges.slice(0, 10)"
                        :key="b.id"
                        class="flex items-center justify-between py-1.5"
                    >
                        <span
                            >@{{ b.username }} · {{ b.badge_type }} ({{
                                b.grant_source
                            }})</span
                        >
                        <Badge
                            :variant="b.is_active ? 'default' : 'destructive'"
                        >
                            {{ b.is_active ? 'Active' : 'Revoked' }}
                        </Badge>
                    </li>
                </ul>
            </section>
        </div>

        <!-- Value-Driven Achievements -->
        <section class="space-y-3 rounded-xl border border-border bg-card p-5">
            <h2 class="flex items-center gap-2 text-sm font-semibold">
                <Trophy class="size-4 text-primary" />
                Achievements ({{ achievements.length }})
            </h2>
            <div class="grid gap-2.5 text-xs sm:grid-cols-2 lg:grid-cols-4">
                <div
                    v-for="a in achievements"
                    :key="a.id"
                    class="flex flex-col justify-between gap-2 rounded-lg border border-border p-3"
                >
                    <div>
                        <p class="font-semibold text-foreground">
                            {{ a.name }}
                        </p>
                        <p class="mt-0.5 text-muted-foreground">
                            {{ a.description }}
                        </p>
                    </div>
                    <div class="flex items-center justify-between">
                        <span class="text-muted-foreground"
                            >{{ a.unlocked_count }} unlocked</span
                        >
                        <Button
                            size="sm"
                            variant="outline"
                            @click="toggleAchievement(a.id)"
                        >
                            {{ a.is_active ? 'Enabled' : 'Disabled' }}
                        </Button>
                    </div>
                </div>
            </div>
        </section>
    </div>
</template>
