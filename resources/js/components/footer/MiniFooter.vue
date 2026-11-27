<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { ChevronDown, ExternalLink } from '@lucide/vue';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import type { FooterLinkItem } from '@/types';

const { t } = useI18n();
const page = usePage();

const brandName = computed(() => page.props.name);
const footer = computed(() => page.props.footer);

const mainLinks = computed<FooterLinkItem[]>(
    () => footer.value.mainLinks ?? footer.value.links ?? [],
);
const moreLinks = computed<FooterLinkItem[]>(
    () => footer.value.moreLinks ?? [],
);

const copyrightLine = computed(
    () =>
        footer.value.copyrightText ||
        t('footer.copyright', {
            year: footer.value.year,
            brand: brandName.value,
        }),
);

function isExternalLink(item: FooterLinkItem): boolean {
    return (
        item.open_in_new_tab ||
        item.href.startsWith('http://') ||
        item.href.startsWith('https://')
    );
}
</script>

<template>
    <footer
        :aria-label="t('accessibility.miniFooter')"
        class="px-2 pt-1 pb-2 text-[11px] leading-relaxed text-muted-foreground"
    >
        <nav
            v-if="mainLinks.length > 0 || moreLinks.length > 0"
            :aria-label="t('accessibility.footer')"
            class="flex flex-wrap items-center gap-x-1.5 gap-y-1"
        >
            <template v-for="(item, idx) in mainLinks" :key="item.id">
                <a
                    v-if="isExternalLink(item)"
                    :href="item.href"
                    :target="item.open_in_new_tab ? '_blank' : undefined"
                    :rel="
                        item.open_in_new_tab ? 'noopener noreferrer' : undefined
                    "
                    class="inline-flex items-center gap-0.5 text-muted-foreground transition-colors hover:text-foreground hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                >
                    <span>{{ item.label }}</span>
                    <ExternalLink
                        v-if="item.open_in_new_tab"
                        class="size-2.5"
                        aria-hidden="true"
                    />
                </a>
                <Link
                    v-else
                    :href="item.href"
                    class="text-muted-foreground transition-colors hover:text-foreground hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                >
                    {{ item.label }}
                </Link>
                <span
                    v-if="idx < mainLinks.length - 1 || moreLinks.length > 0"
                    class="text-muted-foreground/50 select-none"
                    aria-hidden="true"
                >
                    ·
                </span>
            </template>

            <DropdownMenu v-if="moreLinks.length > 0" :modal="false">
                <DropdownMenuTrigger as-child>
                    <button
                        type="button"
                        class="inline-flex items-center gap-0.5 font-medium text-muted-foreground transition-colors hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    >
                        <span>{{ t('footer.more') }}</span>
                        <ChevronDown class="size-3" aria-hidden="true" />
                    </button>
                </DropdownMenuTrigger>
                <DropdownMenuContent
                    align="start"
                    side="top"
                    class="w-52 rounded-xl p-1"
                >
                    <DropdownMenuItem
                        v-for="item in moreLinks"
                        :key="item.id"
                        as-child
                        class="rounded-lg text-xs"
                    >
                        <a
                            v-if="isExternalLink(item)"
                            :href="item.href"
                            :target="
                                item.open_in_new_tab ? '_blank' : undefined
                            "
                            :rel="
                                item.open_in_new_tab
                                    ? 'noopener noreferrer'
                                    : undefined
                            "
                            class="flex items-center justify-between gap-2"
                        >
                            <span class="truncate">{{ item.label }}</span>
                            <ExternalLink
                                v-if="item.open_in_new_tab"
                                class="size-3 shrink-0 text-muted-foreground"
                                aria-hidden="true"
                            />
                        </a>
                        <Link v-else :href="item.href" class="truncate">
                            {{ item.label }}
                        </Link>
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>
        </nav>

        <div
            v-if="footer.supportEmail || footer.contactInfo"
            class="mt-2 flex flex-wrap items-center gap-x-2 gap-y-0.5 text-[11px] text-muted-foreground/90"
        >
            <a
                v-if="footer.supportEmail"
                :href="`mailto:${footer.supportEmail}`"
                class="transition-colors hover:text-primary hover:underline"
            >
                {{ footer.supportEmail }}
            </a>
            <span v-if="footer.contactInfo" class="truncate">
                {{ footer.contactInfo }}
            </span>
        </div>

        <p class="mt-2 text-[11px] text-muted-foreground/80">
            {{ copyrightLine }}
        </p>
    </footer>
</template>
