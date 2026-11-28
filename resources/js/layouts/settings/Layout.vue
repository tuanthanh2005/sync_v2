<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import {
    Bell,
    Palette,
    Settings,
    ShieldCheck,
    User as UserIcon,
} from '@lucide/vue';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import ProfileViewController from '@/actions/App/Http/Controllers/Social/ProfileViewController';
import LocaleSwitcher from '@/components/common/LocaleSwitcher.vue';
import FooterLinkList from '@/components/footer/FooterLinkList.vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { toUrl } from '@/lib/utils';
import { edit as editAppearance } from '@/routes/appearance';
import { index as notificationsIndex } from '@/routes/notifications';
import { edit as editProfile } from '@/routes/profile';
import { edit as editSecurity } from '@/routes/security';
import type { NavItem } from '@/types';

const { t } = useI18n();
const page = usePage();
const footer = computed(() => page.props.footer);
const brandName = computed(() => page.props.name);

const sidebarNavItems = computed<NavItem[]>(() => [
    {
        title: t('nav.profile'),
        href: editProfile(),
        icon: Settings,
    },
    {
        title: t('nav.viewPublicProfile'),
        href: ProfileViewController.me.url(),
        icon: UserIcon,
    },
    {
        title: t('nav.security'),
        href: editSecurity(),
        icon: ShieldCheck,
    },
    {
        title: t('nav.appearanceLanguage'),
        href: editAppearance(),
        icon: Palette,
    },
    {
        title: t('nav.notifications'),
        href: notificationsIndex(),
        icon: Bell,
    },
]);

const { isCurrentOrParentUrl } = useCurrentUrl();
</script>

<template>
    <div class="rounded-xl border border-border bg-card p-5 shadow-xs sm:p-6">
        <Heading
            :title="t('settings.layout.headingTitle')"
            :description="t('settings.layout.headingDescription')"
        />

        <div class="flex flex-col gap-6 md:flex-row md:gap-8">
            <aside class="w-full md:w-52 md:shrink-0">
                <nav
                    class="no-scrollbar flex flex-row gap-1 overflow-x-auto md:flex-col"
                    :aria-label="t('settings.layout.navAriaLabel')"
                >
                    <Button
                        v-for="item in sidebarNavItems"
                        :key="toUrl(item.href)"
                        variant="ghost"
                        :class="[
                            'min-h-10 shrink-0 justify-start gap-2.5 rounded-xl px-3 text-sm md:w-full',
                            {
                                'bg-primary-soft font-semibold text-accent-foreground':
                                    isCurrentOrParentUrl(item.href),
                            },
                        ]"
                        as-child
                    >
                        <Link :href="item.href">
                            <component
                                v-if="item.icon"
                                :is="item.icon"
                                :class="[
                                    'size-4 shrink-0',
                                    isCurrentOrParentUrl(item.href)
                                        ? 'text-primary'
                                        : 'text-muted-foreground',
                                ]"
                            />
                            <span class="truncate">{{ item.title }}</span>
                        </Link>
                    </Button>
                </nav>
            </aside>

            <Separator class="md:hidden" />

            <div class="min-w-0 flex-1">
                <section class="max-w-xl space-y-10">
                    <slot />
                </section>
            </div>
        </div>

        <!-- Mobile Settings Footer Links & Language Switcher -->
        <div class="mt-8 border-t border-border pt-5 xl:hidden">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <p
                    class="text-xs font-semibold tracking-wider text-muted-foreground uppercase"
                >
                    {{ t('footer.mobileSectionTitle') }}
                </p>
                <LocaleSwitcher compact />
            </div>
            <div v-if="footer.links.length > 0" class="mt-3">
                <FooterLinkList :items="footer.links" variant="inline" />
            </div>
            <p class="mt-3 text-[11px] text-muted-foreground">
                {{
                    t('footer.copyright', {
                        year: footer.year,
                        brand: brandName,
                    })
                }}
            </p>
        </div>
    </div>
</template>
