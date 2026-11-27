<script setup lang="ts">
import { CheckCircle2, Circle } from '@lucide/vue';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

const props = defineProps<{
    password?: string;
}>();

const { t } = useI18n();

const value = computed(() => props.password ?? '');

const rules = computed(() => [
    {
        key: 'minLength',
        met: value.value.length >= 12,
        label: t('auth.passwordRules.minLength'),
    },
    {
        key: 'mixedCase',
        met: /[a-z]/.test(value.value) && /[A-Z]/.test(value.value),
        label: t('auth.passwordRules.mixedCase'),
    },
    {
        key: 'number',
        met: /\d/.test(value.value),
        label: t('auth.passwordRules.number'),
    },
    {
        key: 'symbol',
        met: /[^A-Za-z0-9\s]/.test(value.value),
        label: t('auth.passwordRules.symbol'),
    },
]);

const metCount = computed(() => rules.value.filter((r) => r.met).length);

const strengthScore = computed(() => {
    if (value.value.length === 0) {
        return 0;
    }
    if (metCount.value === 4 && value.value.length >= 15) {
        return 4;
    }
    return Math.min(4, Math.max(1, metCount.value));
});

const strengthMeta = computed(() => {
    switch (strengthScore.value) {
        case 4:
            return {
                label: t('auth.passwordRules.strengthVeryStrong'),
                barClass: 'bg-success',
                textClass: 'text-success',
            };
        case 3:
            return {
                label: t('auth.passwordRules.strengthStrong'),
                barClass: 'bg-primary',
                textClass: 'text-primary',
            };
        case 2:
            return {
                label: t('auth.passwordRules.strengthFair'),
                barClass: 'bg-warning',
                textClass: 'text-warning',
            };
        default:
            return {
                label: t('auth.passwordRules.strengthWeak'),
                barClass: 'bg-destructive',
                textClass: 'text-destructive',
            };
    }
});
</script>

<template>
    <div
        class="rounded-lg border border-border/80 bg-muted/35 px-3 py-2.5 text-start text-xs"
        data-test="password-requirements-hint"
    >
        <div class="mb-1.5 flex items-center justify-between gap-2">
            <p class="font-medium text-foreground/90">
                {{ t('auth.passwordRules.title') }}
            </p>
            <span
                v-if="value.length > 0"
                :class="['font-semibold', strengthMeta.textClass]"
            >
                {{ strengthMeta.label }}
            </span>
        </div>

        <div
            v-if="value.length > 0"
            class="mb-2 grid grid-cols-4 gap-1"
            aria-hidden="true"
        >
            <div
                v-for="step in 4"
                :key="step"
                :class="[
                    'h-1 rounded-full transition-colors',
                    step <= strengthScore
                        ? strengthMeta.barClass
                        : 'bg-border/70',
                ]"
            />
        </div>

        <ul class="grid gap-1 sm:grid-cols-2">
            <li
                v-for="rule in rules"
                :key="rule.key"
                :class="[
                    'flex items-center gap-1.5 transition-colors',
                    rule.met
                        ? 'font-medium text-success'
                        : 'text-muted-foreground',
                ]"
            >
                <CheckCircle2
                    v-if="rule.met"
                    class="size-3.5 shrink-0 text-success"
                    aria-hidden="true"
                />
                <Circle
                    v-else
                    class="size-3.5 shrink-0 text-muted-foreground/50"
                    aria-hidden="true"
                />
                <span>{{ rule.label }}</span>
            </li>
        </ul>
    </div>
</template>
