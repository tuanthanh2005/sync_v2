<script setup lang="ts">
import { computed } from 'vue';

const props = withDefaults(
    defineProps<{
        code: string;
        size?: 'sm' | 'md' | 'lg';
    }>(),
    {
        size: 'sm',
    },
);

const normalized = computed(() => props.code.toLowerCase());
const sizeClass = computed(() => {
    switch (props.size) {
        case 'lg':
            return 'h-5 w-7';
        case 'md':
            return 'h-4 w-6';
        default:
            return 'h-3.5 w-5';
    }
});
</script>

<template>
    <span
        :class="[
            'inline-flex shrink-0 items-center justify-center overflow-hidden rounded-[3px] border border-border/70 bg-muted shadow-2xs',
            sizeClass,
        ]"
        aria-hidden="true"
    >
        <!-- US (en) -->
        <svg
            v-if="normalized === 'us' || normalized === 'en'"
            viewBox="0 0 28 20"
            class="size-full"
        >
            <rect width="28" height="20" fill="#B22234" />
            <path
                d="M0 3h28M0 6.1h28M0 9.2h28M0 12.3h28M0 15.4h28M0 18.5h28"
                stroke="#FFFFFF"
                stroke-width="1.55"
            />
            <rect width="12" height="10.8" fill="#3C3B6E" />
            <g fill="#FFFFFF">
                <circle cx="2.2" cy="2.2" r="0.6" />
                <circle cx="4.8" cy="2.2" r="0.6" />
                <circle cx="7.4" cy="2.2" r="0.6" />
                <circle cx="10" cy="2.2" r="0.6" />
                <circle cx="3.5" cy="4.4" r="0.6" />
                <circle cx="6.1" cy="4.4" r="0.6" />
                <circle cx="8.7" cy="4.4" r="0.6" />
                <circle cx="2.2" cy="6.6" r="0.6" />
                <circle cx="4.8" cy="6.6" r="0.6" />
                <circle cx="7.4" cy="6.6" r="0.6" />
                <circle cx="10" cy="6.6" r="0.6" />
                <circle cx="3.5" cy="8.8" r="0.6" />
                <circle cx="6.1" cy="8.8" r="0.6" />
                <circle cx="8.7" cy="8.8" r="0.6" />
            </g>
        </svg>

        <!-- VN (vi) -->
        <svg
            v-else-if="normalized === 'vn' || normalized === 'vi'"
            viewBox="0 0 28 20"
            class="size-full"
        >
            <rect width="28" height="20" fill="#DA251D" />
            <polygon
                points="14,4.2 15.6,9 20.6,9 16.5,11.9 18.1,16.6 14,13.7 9.9,16.6 11.5,11.9 7.4,9 12.4,9"
                fill="#FFFF00"
            />
        </svg>

        <!-- ES (es) -->
        <svg
            v-else-if="normalized === 'es'"
            viewBox="0 0 28 20"
            class="size-full"
        >
            <rect width="28" height="20" fill="#AA151B" />
            <rect y="5" width="28" height="10" fill="#F1BF00" />
            <rect
                x="6.5"
                y="7.5"
                width="3.5"
                height="5"
                rx="0.8"
                fill="#AA151B"
            />
        </svg>

        <!-- BR (pt-BR) -->
        <svg
            v-else-if="normalized === 'br' || normalized === 'pt-br'"
            viewBox="0 0 28 20"
            class="size-full"
        >
            <rect width="28" height="20" fill="#009B3A" />
            <polygon points="14,2.5 25,10 14,17.5 3,10" fill="#FFDF00" />
            <circle cx="14" cy="10" r="4" fill="#002776" />
            <path
                d="M10.4 9.5c2.2-0.7 4.9-0.2 7.2 1.2"
                stroke="#FFFFFF"
                stroke-width="0.9"
                fill="none"
            />
        </svg>

        <!-- ID (id) -->
        <svg
            v-else-if="normalized === 'id'"
            viewBox="0 0 28 20"
            class="size-full"
        >
            <rect width="28" height="10" fill="#CE1126" />
            <rect y="10" width="28" height="10" fill="#FFFFFF" />
        </svg>

        <!-- IN (hi) -->
        <svg
            v-else-if="normalized === 'in' || normalized === 'hi'"
            viewBox="0 0 28 20"
            class="size-full"
        >
            <rect width="28" height="6.7" fill="#FF9933" />
            <rect y="6.7" width="28" height="6.6" fill="#FFFFFF" />
            <rect y="13.3" width="28" height="6.7" fill="#138808" />
            <circle
                cx="14"
                cy="10"
                r="2.2"
                fill="none"
                stroke="#000080"
                stroke-width="0.7"
            />
            <circle cx="14" cy="10" r="0.6" fill="#000080" />
        </svg>

        <!-- TH (th) -->
        <svg
            v-else-if="normalized === 'th'"
            viewBox="0 0 28 20"
            class="size-full"
        >
            <rect width="28" height="20" fill="#A51931" />
            <rect y="3.3" width="28" height="13.4" fill="#F4F5F8" />
            <rect y="6.7" width="28" height="6.6" fill="#2D2A4A" />
        </svg>

        <!-- PH (fil) -->
        <svg
            v-else-if="normalized === 'ph' || normalized === 'fil'"
            viewBox="0 0 28 20"
            class="size-full"
        >
            <rect width="28" height="10" fill="#0038A8" />
            <rect y="10" width="28" height="10" fill="#CE1126" />
            <polygon points="0,0 13,10 0,20" fill="#FFFFFF" />
            <circle cx="4.5" cy="10" r="2.1" fill="#FCD116" />
        </svg>

        <!-- FR (fr) -->
        <svg
            v-else-if="normalized === 'fr'"
            viewBox="0 0 28 20"
            class="size-full"
        >
            <rect width="9.33" height="20" fill="#002395" />
            <rect x="9.33" width="9.34" height="20" fill="#FFFFFF" />
            <rect x="18.67" width="9.33" height="20" fill="#ED2939" />
        </svg>

        <!-- DE (de) -->
        <svg
            v-else-if="normalized === 'de'"
            viewBox="0 0 28 20"
            class="size-full"
        >
            <rect width="28" height="6.7" fill="#000000" />
            <rect y="6.7" width="28" height="6.6" fill="#DD0000" />
            <rect y="13.3" width="28" height="6.7" fill="#FFCE00" />
        </svg>

        <!-- JP (ja) -->
        <svg
            v-else-if="normalized === 'jp' || normalized === 'ja'"
            viewBox="0 0 28 20"
            class="size-full"
        >
            <rect width="28" height="20" fill="#FFFFFF" />
            <circle cx="14" cy="10" r="5.2" fill="#BC002D" />
        </svg>

        <!-- KR (ko) -->
        <svg
            v-else-if="normalized === 'kr' || normalized === 'ko'"
            viewBox="0 0 28 20"
            class="size-full"
        >
            <rect width="28" height="20" fill="#FFFFFF" />
            <circle cx="14" cy="10" r="4.5" fill="#CD2E3A" />
            <path
                d="M9.5 10a4.5 4.5 0 0 0 9 0 2.25 2.25 0 0 0-4.5 0 2.25 2.25 0 0 1-4.5 0z"
                fill="#0047A0"
            />
            <path
                d="M5 5.5l2.5-2M5.7 6.3l2.5-2M20.5 3.5l2.5 2M19.8 4.3l2.5 2M5 14.5l2.5 2M20.5 16.5l2.5-2"
                stroke="#000000"
                stroke-width="0.8"
            />
        </svg>

        <!-- CN (zh-CN) -->
        <svg
            v-else-if="normalized === 'cn' || normalized === 'zh-cn'"
            viewBox="0 0 28 20"
            class="size-full"
        >
            <rect width="28" height="20" fill="#DE2910" />
            <polygon
                points="5.5,3.2 6.3,5.6 8.8,5.6 6.8,7 7.6,9.4 5.5,7.9 3.4,9.4 4.2,7 2.2,5.6 4.7,5.6"
                fill="#FFDE00"
            />
            <circle cx="10.4" cy="3.4" r="0.7" fill="#FFDE00" />
            <circle cx="11.8" cy="5.2" r="0.7" fill="#FFDE00" />
            <circle cx="11.8" cy="7.5" r="0.7" fill="#FFDE00" />
            <circle cx="10.4" cy="9.2" r="0.7" fill="#FFDE00" />
        </svg>

        <!-- SA (ar) -->
        <svg
            v-else-if="normalized === 'sa' || normalized === 'ar'"
            viewBox="0 0 28 20"
            class="size-full"
        >
            <rect width="28" height="20" fill="#006C35" />
            <path
                d="M6.5 8.5h15M8 6.5h12M9 10.5h10"
                stroke="#FFFFFF"
                stroke-width="1.1"
                stroke-linecap="round"
            />
            <path
                d="M7.5 13.8h12.5"
                stroke="#FFFFFF"
                stroke-width="1.2"
                stroke-linecap="round"
            />
            <circle cx="20.5" cy="13.8" r="0.8" fill="#FFFFFF" />
        </svg>

        <!-- TR (tr) -->
        <svg
            v-else-if="normalized === 'tr'"
            viewBox="0 0 28 20"
            class="size-full"
        >
            <rect width="28" height="20" fill="#E30A17" />
            <circle cx="11.5" cy="10" r="4.6" fill="#FFFFFF" />
            <circle cx="12.9" cy="10" r="3.7" fill="#E30A17" />
            <polygon
                points="16.8,10 18.8,10.7 18.1,8.8 19.6,7.6 17.6,7.6 17,5.8 16.4,7.6 14.5,7.6 16,8.8 15.3,10.7"
                fill="#FFFFFF"
            />
        </svg>

        <!-- Fallback globe-like emblem -->
        <svg v-else viewBox="0 0 28 20" class="size-full">
            <rect width="28" height="20" fill="#4F46E5" />
            <circle
                cx="14"
                cy="10"
                r="5"
                fill="none"
                stroke="#FFFFFF"
                stroke-width="1.2"
            />
            <path d="M9 10h10M14 5v10" stroke="#FFFFFF" stroke-width="1" />
        </svg>
    </span>
</template>
