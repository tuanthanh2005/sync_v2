<script setup lang="ts">
import { Bot, Crown, Gift, ShieldCheck, Star, Users } from '@lucide/vue';
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import type { UnifiedBadgeItem } from '@/types/ui';

const props = withDefaults(
    defineProps<{
        badges?: UnifiedBadgeItem[] | null;
        mode?: 'compact' | 'showcase';
        size?: 'xs' | 'sm' | 'md';
        max?: number;
        interactive?: boolean;
        fallbackIsPremium?: boolean;
        fallbackIsAi?: boolean;
        fallbackAiDisclosure?: string | null;
    }>(),
    {
        badges: () => [],
        mode: 'compact',
        size: 'sm',
        max: 2,
        interactive: true,
        fallbackIsPremium: false,
        fallbackIsAi: false,
        fallbackAiDisclosure: null,
    },
);

const { t, te } = useI18n();

const normalizedBadges = computed<UnifiedBadgeItem[]>(() => {
    const incoming = Array.isArray(props.badges) ? [...props.badges] : [];

    // Safe fallback only when a lightweight endpoint did not emit unified resolved badges yet.
    if (incoming.length === 0) {
        if (props.fallbackIsPremium && !props.fallbackIsAi) {
            incoming.push({
                type: 'premium_member',
                label_key: 'badges.premiumMember.label',
                description_key: 'badges.premiumMember.description',
                icon: 'premium_checkmark',
                color: '#1D9BF0',
                priority: 70,
            });
        }

        if (props.fallbackIsAi) {
            incoming.push({
                type: 'ai_account',
                label_key: 'badges.aiAccount.label',
                description_key: 'badges.aiAccount.description',
                icon: 'bot',
                color: '#14B8A6',
                priority: 40,
                ai_disclosure: props.fallbackAiDisclosure ?? null,
            });
        }
    }

    if (props.mode === 'compact') {
        return incoming.slice(0, Math.max(1, props.max));
    }

    return incoming;
});

const iconSizeClass = computed(() => {
    if (props.size === 'xs') {
        return 'size-3.5';
    }
    if (props.size === 'md') {
        return 'size-[18px]';
    }
    return 'size-4';
});

const pillPaddingClass = computed(() => {
    if (props.size === 'md') {
        return 'h-6 gap-1.5 px-2.5 text-xs';
    }
    return 'h-[18px] gap-1 px-1.5 text-[10px]';
});

function badgeLabel(badge: UnifiedBadgeItem): string {
    const label = badge.label_key && te(badge.label_key) ? t(badge.label_key) : fallbackTitle(badge.type);
    if (badge.type === 'early_member' && badge.member_number) {
        return `${label} #${badge.member_number}`;
    }

    return label;
}

function badgeDescription(badge: UnifiedBadgeItem): string {
    if (badge.description_key && te(badge.description_key)) {
        const desc = t(badge.description_key);
        if (badge.type === 'ai_account' && badge.ai_disclosure) {
            return `${desc} ${badge.ai_disclosure}`;
        }

        return desc;
    }

    if (badge.type === 'ai_account' && badge.ai_disclosure) {
        return badge.ai_disclosure;
    }

    return fallbackDescription(badge.type);
}

function fallbackTitle(type: string): string {
    const map: Record<string, string> = {
        official_account: 'Official Account',
        official_staff: 'Official Staff',
        moderator: 'Moderator',
        premium_member: 'Premium Member',
        early_member: 'Early Member',
        ai_account: 'AI Account',
        founding_member: 'Founding Member',
        referral_ambassador: 'Referral Ambassador',
        community_builder: 'Community Builder',
    };

    return map[type] ?? type;
}

function fallbackDescription(type: string): string {
    const map: Record<string, string> = {
        official_account: 'This is an official account operated by SysNoxis.',
        official_staff: 'This account belongs to an official member of the SysNoxis team.',
        moderator: 'This account belongs to an official SysNoxis moderator.',
        premium_member: 'This account has an active SysNoxis Premium membership. This badge does not indicate identity verification.',
        early_member: 'This account is recognized as an early member of the SysNoxis community.',
        ai_account: 'This account is operated by an AI system and is transparently identified as automated.',
    };

    return map[type] ?? '';
}

function badgeHex(badge: UnifiedBadgeItem): string {
    if (badge.color && badge.color.startsWith('#')) {
        return badge.color;
    }

    const map: Record<string, string> = {
        official_account: '#EAB308',
        official_staff: '#EAB308',
        moderator: '#94A3B8',
        premium_member: '#1D9BF0',
        early_member: '#A855F7',
        ai_account: '#14B8A6',
        founding_member: '#10B981',
        referral_ambassador: '#4F46E5',
        community_builder: '#0EA5E9',
    };

    return map[badge.type] ?? '#4F46E5';
}

function resolvedIcon(badge: UnifiedBadgeItem): string {
    if (badge.icon && ['official_checkmark', 'premium_checkmark', 'shield_check', 'star', 'bot', 'crown', 'gift', 'users'].includes(badge.icon)) {
        return badge.icon;
    }

    const map: Record<string, string> = {
        official_account: 'official_checkmark',
        official_staff: 'official_checkmark',
        moderator: 'shield_check',
        premium_member: 'premium_checkmark',
        early_member: 'star',
        ai_account: 'bot',
        founding_member: 'crown',
        referral_ambassador: 'gift',
        community_builder: 'users',
    };

    return map[badge.type] ?? 'star';
}

function lucideForBadge(badge: UnifiedBadgeItem) {
    const icon = resolvedIcon(badge);
    if (icon === 'shield_check') {
        return ShieldCheck;
    }
    if (icon === 'bot') {
        return Bot;
    }
    if (icon === 'crown') {
        return Crown;
    }
    if (icon === 'gift') {
        return Gift;
    }
    if (icon === 'users') {
        return Users;
    }
    return Star;
}

// Accessible Tooltip + Popover state (supports desktop hover, keyboard focus, and mobile tap cleanly)
const activeIndex = ref<number | null>(null);
const pinnedIndex = ref<number | null>(null);
const popoverStyle = ref<{ top: string; left: string }>({ top: '0px', left: '0px' });
const triggerRefs = ref<Array<HTMLElement | null>>([]);
const popoverRef = ref<HTMLElement | null>(null);
let closeTimer: number | null = null;

function setTriggerRef(index: number, el: unknown): void {
    triggerRefs.value[index] = el instanceof HTMLElement ? el : null;
}

function clearTimers(): void {
    if (closeTimer !== null) {
        window.clearTimeout(closeTimer);
        closeTimer = null;
    }
}

async function positionPopover(index: number): Promise<void> {
    await nextTick();
    const trigger = triggerRefs.value[index];
    if (!trigger) {
        return;
    }

    const rect = trigger.getBoundingClientRect();
    const viewportW = window.innerWidth;
    const viewportH = window.innerHeight;
    const panelWidth = Math.min(264, viewportW - 24);
    const estHeight = popoverRef.value?.offsetHeight ?? 92;

    let left = rect.left + rect.width / 2 - panelWidth / 2;
    left = Math.max(12, Math.min(left, viewportW - panelWidth - 12));

    let top = rect.bottom + 8;
    if (top + estHeight > viewportH - 12 && rect.top - estHeight - 8 > 12) {
        top = rect.top - estHeight - 8;
    }

    popoverStyle.value = {
        top: `${Math.round(top)}px`,
        left: `${Math.round(left)}px`,
    };
}

function handleHoverOpen(index: number): void {
    if (!props.interactive || pinnedIndex.value !== null) {
        return;
    }
    clearTimers();
    activeIndex.value = index;
    void positionPopover(index);
}

function handleHoverClose(index: number): void {
    if (pinnedIndex.value === index) {
        return;
    }
    clearTimers();
    closeTimer = window.setTimeout(() => {
        if (pinnedIndex.value === null && activeIndex.value === index) {
            activeIndex.value = null;
        }
    }, 110);
}

function handleTapToggle(index: number): void {
    if (!props.interactive) {
        return;
    }
    clearTimers();
    if (pinnedIndex.value === index) {
        pinnedIndex.value = null;
        activeIndex.value = null;
        return;
    }
    pinnedIndex.value = index;
    activeIndex.value = index;
    void positionPopover(index);
}

function closeAll(): void {
    clearTimers();
    pinnedIndex.value = null;
    activeIndex.value = null;
}

function handleOutsidePointerDown(event: PointerEvent): void {
    if (activeIndex.value === null) {
        return;
    }
    const target = event.target as Node | null;
    if (!target) {
        closeAll();
        return;
    }
    const insideTrigger = triggerRefs.value.some((el) => el?.contains(target));
    const insidePopover = popoverRef.value?.contains(target) ?? false;
    if (!insideTrigger && !insidePopover) {
        closeAll();
    }
}

function handleGlobalKeydown(event: KeyboardEvent): void {
    if (event.key === 'Escape' && activeIndex.value !== null) {
        closeAll();
    }
}

onMounted(() => {
    document.addEventListener('pointerdown', handleOutsidePointerDown, true);
    document.addEventListener('keydown', handleGlobalKeydown);
});

onBeforeUnmount(() => {
    clearTimers();
    document.removeEventListener('pointerdown', handleOutsidePointerDown, true);
    document.removeEventListener('keydown', handleGlobalKeydown);
});
</script>

<template>
    <span
        v-if="normalizedBadges.length > 0"
        :class="[
            'inline-flex shrink-0 items-center align-middle',
            mode === 'showcase' ? 'flex-wrap gap-1.5' : 'gap-1',
        ]"
    >
        <template v-for="(badge, idx) in normalizedBadges" :key="`${badge.type}-${idx}`">
            <component
                :is="interactive ? 'button' : 'span'"
                :ref="(el: unknown) => setTriggerRef(idx, el)"
                :type="interactive ? 'button' : undefined"
                :aria-label="interactive ? `${badgeLabel(badge)} — ${badgeDescription(badge)}` : undefined"
                :aria-expanded="interactive ? activeIndex === idx : undefined"
                :class="[
                    'inline-flex shrink-0 items-center justify-center leading-none select-none',
                    interactive
                        ? 'cursor-pointer rounded-full transition-opacity hover:opacity-90 focus-visible:ring-2 focus-visible:ring-primary/50 focus-visible:outline-none'
                        : '',
                    mode === 'showcase'
                        ? 'gap-1.5 rounded-full border border-border bg-surface-hover/70 px-2.5 py-1 text-xs font-medium text-foreground dark:bg-card'
                        : badge.type === 'ai_account'
                          ? `${pillPaddingClass} rounded-full border border-[#14B8A6]/40 bg-[#14B8A6]/10 font-semibold text-[#0F766E] dark:text-[#2DD4BF]`
                          : '',
                ]"
                @click="interactive ? ($event.stopPropagation(), $event.preventDefault(), handleTapToggle(idx)) : undefined"
                @mouseenter="interactive ? handleHoverOpen(idx) : undefined"
                @mouseleave="interactive ? handleHoverClose(idx) : undefined"
                @focus="interactive ? handleHoverOpen(idx) : undefined"
                @blur="interactive ? handleHoverClose(idx) : undefined"
            >
                <!-- Official Seal (#EAB308) or Premium Seal (#1D9BF0) geometric SVG -->
                <svg
                    v-if="resolvedIcon(badge) === 'official_checkmark' || resolvedIcon(badge) === 'premium_checkmark'"
                    viewBox="0 0 24 24"
                    :class="[mode === 'showcase' ? 'size-4' : iconSizeClass, 'shrink-0']"
                    fill="none"
                    xmlns="http://www.w3.org/2000/svg"
                    aria-hidden="true"
                >
                    <path
                        d="M12 1.75L14.84 3.4L18.12 3.42L19.78 6.26L22.25 8.45L21.6 11.67L22.25 14.89L19.78 17.08L18.12 19.92L14.84 19.94L12 21.59L9.16 19.94L5.88 19.92L4.22 17.08L1.75 14.89L2.4 11.67L1.75 8.45L4.22 6.26L5.88 3.42L9.16 3.4L12 1.75Z"
                        :fill="badgeHex(badge)"
                    />
                    <path
                        d="M9.6 12.15L11.2 13.75L14.85 9.95"
                        stroke="#FFFFFF"
                        stroke-width="2.3"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                    />
                </svg>

                <!-- Lucide ShieldCheck (#94A3B8), Star (#A855F7), Bot (#14B8A6), or Growth Icon -->
                <component
                    :is="lucideForBadge(badge)"
                    v-else
                    :class="[
                        mode === 'showcase'
                            ? 'size-3.5'
                            : badge.type === 'ai_account'
                              ? 'size-3'
                              : iconSizeClass,
                        'shrink-0',
                    ]"
                    :style="{
                        color: badge.type === 'ai_account' && mode === 'compact' ? undefined : badgeHex(badge),
                    }"
                    :fill="badge.type === 'early_member' ? '#A855F7' : 'none'"
                    :fill-opacity="badge.type === 'early_member' ? 0.2 : undefined"
                    aria-hidden="true"
                />

                <!-- Showcase renders full label; Compact renders subtle 'AI' pill label ONLY for AI transparent disclosure -->
                <span v-if="mode === 'showcase'" class="whitespace-nowrap">
                    {{ badgeLabel(badge) }}
                </span>
                <span v-else-if="badge.type === 'ai_account'" class="leading-none tracking-tight">
                    {{ te('badges.aiAccount.short') ? t('badges.aiAccount.short') : 'AI' }}
                </span>
            </component>
        </template>

        <Teleport to="body">
            <div
                v-if="interactive && activeIndex !== null && normalizedBadges[activeIndex]"
                ref="popoverRef"
                role="tooltip"
                :style="{ top: popoverStyle.top, left: popoverStyle.left }"
                class="fixed z-[100] w-[264px] max-w-[calc(100vw-24px)] rounded-xl border border-border bg-popover p-3 text-start text-popover-foreground shadow-md"
                @mouseenter="clearTimers()"
                @mouseleave="handleHoverClose(activeIndex)"
            >
                <div class="flex items-start gap-2.5">
                    <span class="mt-0.5 flex size-6 shrink-0 items-center justify-center rounded-full bg-muted/70">
                        <svg
                            v-if="
                                resolvedIcon(normalizedBadges[activeIndex]) === 'official_checkmark' ||
                                resolvedIcon(normalizedBadges[activeIndex]) === 'premium_checkmark'
                            "
                            viewBox="0 0 24 24"
                            class="size-4"
                            fill="none"
                            xmlns="http://www.w3.org/2000/svg"
                            aria-hidden="true"
                        >
                            <path
                                d="M12 1.75L14.84 3.4L18.12 3.42L19.78 6.26L22.25 8.45L21.6 11.67L22.25 14.89L19.78 17.08L18.12 19.92L14.84 19.94L12 21.59L9.16 19.94L5.88 19.92L4.22 17.08L1.75 14.89L2.4 11.67L1.75 8.45L4.22 6.26L5.88 3.42L9.16 3.4L12 1.75Z"
                                :fill="badgeHex(normalizedBadges[activeIndex])"
                            />
                            <path
                                d="M9.6 12.15L11.2 13.75L14.85 9.95"
                                stroke="#FFFFFF"
                                stroke-width="2.3"
                                stroke-linecap="round"
                                stroke-linejoin="round"
                            />
                        </svg>
                        <component
                            :is="lucideForBadge(normalizedBadges[activeIndex])"
                            v-else
                            class="size-4"
                            :style="{ color: badgeHex(normalizedBadges[activeIndex]) }"
                            aria-hidden="true"
                        />
                    </span>
                    <div class="min-w-0 flex-1 space-y-1">
                        <p class="text-xs font-semibold text-foreground">
                            {{ badgeLabel(normalizedBadges[activeIndex]) }}
                        </p>
                        <p class="text-[11px] leading-relaxed text-muted-foreground">
                            {{ badgeDescription(normalizedBadges[activeIndex]) }}
                        </p>
                    </div>
                </div>
            </div>
        </Teleport>
    </span>
</template>
