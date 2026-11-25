<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';

const props = withDefaults(
    defineProps<{
        size?: 'sm' | 'md';
    }>(),
    {
        size: 'sm',
    },
);

const { t } = useI18n();
</script>

<template>
    <TooltipProvider :delay-duration="150">
        <Tooltip>
            <TooltipTrigger as-child>
                <span
                    class="inline-flex shrink-0 cursor-help items-center justify-center align-middle"
                    :aria-label="t('monetization.badge.title')"
                    tabindex="0"
                >
                    <svg
                        viewBox="0 0 24 24"
                        :class="
                            props.size === 'md' ? 'size-[20px]' : 'size-[15px]'
                        "
                        fill="none"
                        xmlns="http://www.w3.org/2000/svg"
                        aria-hidden="true"
                    >
                        <path
                            d="M12 1.75L14.84 3.4L18.12 3.42L19.78 6.26L22.25 8.45L21.6 11.67L22.25 14.89L19.78 17.08L18.12 19.92L14.84 19.94L12 21.59L9.16 19.94L5.88 19.92L4.22 17.08L1.75 14.89L2.4 11.67L1.75 8.45L4.22 6.26L5.88 3.42L9.16 3.4L12 1.75Z"
                            fill="#1D9BF0"
                        />
                        <path
                            d="M9.6 12.15L11.2 13.75L14.85 9.95"
                            stroke="#FFFFFF"
                            stroke-width="2.1"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                        />
                    </svg>
                </span>
            </TooltipTrigger>
            <TooltipContent class="max-w-xs text-xs leading-relaxed">
                <p class="font-semibold text-foreground">
                    {{ t('monetization.badge.title') }}
                </p>
                <p class="mt-0.5 text-muted-foreground">
                    {{ t('monetization.badge.tooltip') }}
                </p>
            </TooltipContent>
        </Tooltip>
    </TooltipProvider>
</template>
