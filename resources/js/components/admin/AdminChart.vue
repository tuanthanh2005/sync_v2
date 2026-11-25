<script setup lang="ts">
import {
    BarController,
    BarElement,
    CategoryScale,
    Chart,
    Filler,
    Legend,
    LinearScale,
    LineController,
    LineElement,
    PointElement,
    Tooltip,
} from 'chart.js';
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';

Chart.register(
    LineController,
    BarController,
    LineElement,
    BarElement,
    PointElement,
    LinearScale,
    CategoryScale,
    Tooltip,
    Legend,
    Filler,
);

type DatasetInput = {
    label: string;
    data: number[];
    color?: string;
    fill?: boolean;
};

const props = withDefaults(
    defineProps<{
        type?: 'line' | 'bar';
        labels: string[];
        datasets: DatasetInput[];
        height?: number;
    }>(),
    {
        type: 'line',
        height: 260,
    },
);

const canvasRef = ref<HTMLCanvasElement | null>(null);
let chartInstance: Chart | null = null;

function renderChart(): void {
    if (!canvasRef.value) {
        return;
    }

    if (chartInstance) {
        chartInstance.destroy();
        chartInstance = null;
    }

    const isDark = document.documentElement.classList.contains('dark');
    const textColor = isDark ? '#94A3B8' : '#64748B';
    const gridColor = isDark
        ? 'rgba(39, 50, 68, 0.7)'
        : 'rgba(226, 232, 240, 0.8)';
    const defaultColors = [
        '#4F46E5',
        '#16A34A',
        '#D97706',
        '#0284C7',
        '#DC2626',
    ];

    chartInstance = new Chart(canvasRef.value, {
        type: props.type,
        data: {
            labels: props.labels,
            datasets: props.datasets.map((ds, index) => {
                const color =
                    ds.color || defaultColors[index % defaultColors.length];
                return {
                    label: ds.label,
                    data: ds.data,
                    borderColor: color,
                    backgroundColor:
                        props.type === 'bar' ? `${color}CC` : `${color}1F`,
                    borderWidth: 2,
                    tension: 0.3,
                    fill: ds.fill ?? props.type === 'line',
                    pointRadius: 2.5,
                    borderRadius: props.type === 'bar' ? 6 : 0,
                };
            }),
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: {
                        color: textColor,
                        boxWidth: 10,
                        usePointStyle: true,
                        font: { size: 11 },
                    },
                },
            },
            scales: {
                x: {
                    grid: { display: false },
                    ticks: {
                        color: textColor,
                        maxRotation: 0,
                        autoSkipPadding: 14,
                        font: { size: 11 },
                    },
                },
                y: {
                    beginAtZero: true,
                    grid: { color: gridColor },
                    ticks: {
                        color: textColor,
                        precision: 0,
                        font: { size: 11 },
                    },
                },
            },
        },
    });
}

onMounted(() => {
    renderChart();
});

watch(
    () => [props.labels, props.datasets, props.type],
    () => {
        renderChart();
    },
    { deep: true },
);

onBeforeUnmount(() => {
    if (chartInstance) {
        chartInstance.destroy();
        chartInstance = null;
    }
});
</script>

<template>
    <div class="relative w-full" :style="{ height: `${height}px` }">
        <canvas ref="canvasRef" />
    </div>
</template>
