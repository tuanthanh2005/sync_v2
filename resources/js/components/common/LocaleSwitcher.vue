<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { Globe } from '@lucide/vue';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import LocaleController from '@/actions/App/Http/Controllers/LocaleController';
import { syncI18nLocale } from '@/i18n';

const { compact = false } = defineProps<{
    compact?: boolean;
}>();

const { t } = useI18n();
const page = usePage();
const currentLocale = computed(() => page.props.locale || 'vi');

function switchLocale(nextLocale: 'vi' | 'en'): void {
    if (currentLocale.value === nextLocale) {
        return;
    }

    syncI18nLocale(nextLocale);

    router.post(
        LocaleController.url(),
        { locale: nextLocale },
        {
            preserveScroll: true,
            preserveState: true,
        },
    );
}
</script>

<template>
    <div
        role="group"
        :aria-label="t('locale.label')"
        class="inline-flex items-center gap-0.5 rounded-lg border border-border bg-muted/60 p-0.5"
    >
        <Globe
            v-if="!compact"
            class="mx-1.5 size-3.5 text-muted-foreground"
            aria-hidden="true"
        />
        <button
            type="button"
            :aria-pressed="currentLocale === 'vi'"
            data-test="locale-vi"
            :class="[
                'rounded-md px-2 py-1 text-[11px] font-semibold transition-colors',
                currentLocale === 'vi'
                    ? 'bg-card text-foreground shadow-2xs'
                    : 'text-muted-foreground hover:text-foreground',
            ]"
            @click="switchLocale('vi')"
        >
            VI
        </button>
        <button
            type="button"
            :aria-pressed="currentLocale === 'en'"
            data-test="locale-en"
            :class="[
                'rounded-md px-2 py-1 text-[11px] font-semibold transition-colors',
                currentLocale === 'en'
                    ? 'bg-card text-foreground shadow-2xs'
                    : 'text-muted-foreground hover:text-foreground',
            ]"
            @click="switchLocale('en')"
        >
            EN
        </button>
    </div>
</template>
