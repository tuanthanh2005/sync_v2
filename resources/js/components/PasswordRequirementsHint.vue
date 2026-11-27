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
</script>

<template>
    <div
        class="rounded-lg border border-border/80 bg-muted/35 px-3 py-2.5 text-start text-xs"
        data-test="password-requirements-hint"
    >
        <p class="mb-1.5 font-medium text-foreground/90">
            {{ t('auth.passwordRules.title') }}
        </p>
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
