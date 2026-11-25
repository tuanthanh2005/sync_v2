<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { Check, ChevronDown, Search } from '@lucide/vue';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import LocaleController from '@/actions/App/Http/Controllers/LocaleController';
import FlagIcon from '@/components/common/FlagIcon.vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { syncI18nLocale } from '@/i18n';
import type { PublicLanguageItem } from '@/types/ui';

const { compact = false } = defineProps<{
    compact?: boolean;
}>();

const { t } = useI18n();
const page = usePage();
const menuOpen = ref(false);
const query = ref('');

const fallbackLanguages: PublicLanguageItem[] = [
    {
        code: 'en',
        native_name: 'English',
        english_name: 'English',
        flag_code: 'us',
        is_rtl: false,
    },
    {
        code: 'vi',
        native_name: 'Tiếng Việt',
        english_name: 'Vietnamese',
        flag_code: 'vn',
        is_rtl: false,
    },
];

const languages = computed<PublicLanguageItem[]>(() => {
    const shared = page.props.languages as PublicLanguageItem[] | undefined;
    return shared && shared.length > 0 ? shared : fallbackLanguages;
});

const currentLocale = computed(() => (page.props.locale as string) || 'en');

const activeLanguage = computed<PublicLanguageItem>(() => {
    return (
        languages.value.find((lang) => lang.code === currentLocale.value) ??
        languages.value[0]
    );
});

const filteredLanguages = computed<PublicLanguageItem[]>(() => {
    const q = query.value.trim().toLowerCase();

    if (!q) {
        return languages.value;
    }

    return languages.value.filter(
        (lang) =>
            lang.native_name.toLowerCase().includes(q) ||
            lang.english_name.toLowerCase().includes(q) ||
            lang.code.toLowerCase().includes(q),
    );
});

function switchLocale(lang: PublicLanguageItem): void {
    menuOpen.value = false;
    query.value = '';

    if (currentLocale.value === lang.code) {
        return;
    }

    syncI18nLocale(lang.code, lang.is_rtl);

    router.post(
        LocaleController.url(),
        { locale: lang.code },
        {
            preserveScroll: true,
            preserveState: true,
        },
    );
}
</script>

<template>
    <DropdownMenu v-model:open="menuOpen">
        <DropdownMenuTrigger as-child>
            <Button
                type="button"
                variant="outline"
                size="sm"
                :aria-label="t('locale.label')"
                data-test="locale-switcher"
                class="h-8 gap-1.5 rounded-lg border-border bg-card px-2.5 text-xs font-medium text-foreground hover:bg-surface-hover"
            >
                <FlagIcon :code="activeLanguage.flag_code" size="sm" />
                <span v-if="!compact" class="max-w-28 truncate">
                    {{ activeLanguage.native_name }}
                </span>
                <span v-else class="uppercase">
                    {{ activeLanguage.code }}
                </span>
                <ChevronDown
                    class="size-3.5 text-muted-foreground"
                    aria-hidden="true"
                />
            </Button>
        </DropdownMenuTrigger>

        <DropdownMenuContent align="end" class="w-64 rounded-xl p-2">
            <div class="relative mb-2">
                <Search
                    class="pointer-events-none absolute top-1/2 left-2.5 size-3.5 -translate-y-1/2 text-muted-foreground"
                    aria-hidden="true"
                />
                <input
                    v-model="query"
                    type="search"
                    :placeholder="t('locale.search')"
                    class="h-8 w-full rounded-lg border border-input bg-background pr-2.5 pl-8 text-xs text-foreground placeholder:text-muted-foreground focus:border-primary focus:ring-2 focus:ring-primary/20 focus:outline-none"
                    @keydown.stop
                />
            </div>

            <div class="max-h-64 space-y-0.5 overflow-y-auto pr-0.5">
                <button
                    v-for="lang in filteredLanguages"
                    :key="lang.code"
                    type="button"
                    :data-test="`locale-${lang.code}`"
                    :aria-current="
                        currentLocale === lang.code ? 'true' : undefined
                    "
                    :class="[
                        'flex w-full items-center gap-2.5 rounded-lg px-2.5 py-2 text-left text-xs transition-colors',
                        currentLocale === lang.code
                            ? 'bg-primary-soft font-semibold text-accent-foreground'
                            : 'text-foreground hover:bg-surface-hover',
                    ]"
                    @click="switchLocale(lang)"
                >
                    <FlagIcon :code="lang.flag_code" size="sm" />
                    <span class="min-w-0 flex-1 truncate">
                        {{ lang.native_name }}
                    </span>
                    <span
                        class="font-mono text-[10px] text-muted-foreground uppercase"
                    >
                        {{ lang.code }}
                    </span>
                    <Check
                        v-if="currentLocale === lang.code"
                        class="size-3.5 shrink-0 text-primary"
                        aria-hidden="true"
                    />
                </button>

                <p
                    v-if="filteredLanguages.length === 0"
                    class="px-2.5 py-4 text-center text-xs text-muted-foreground"
                >
                    {{ t('search.emptyTitle') }}
                </p>
            </div>
        </DropdownMenuContent>
    </DropdownMenu>
</template>
