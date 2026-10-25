<script setup>
import { formatValue } from '@/lib/format';
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';

/**
 * A report chart (bar, line, doughnut). Chart.js is loaded only when a chart is on screen.
 */
const props = defineProps({
    chart: { type: Object, required: true },
    currency: { type: String, default: 'LKR' },
});

const canvas = ref(null);
let instance = null;

const palette = ['#4f46e5', '#ea580c', '#2563eb', '#16a34a', '#9333ea', '#db2777', '#0891b2', '#ca8a04', '#64748b'];

const empty = () => props.chart.datasets.every((d) => d.data.every((v) => !v));

const draw = async () => {
    if (empty()) {
        instance?.destroy();
        instance = null;

        return;
    }

    const { default: Chart } = await import('chart.js/auto');
    instance?.destroy();

    const doughnut = props.chart.type === 'doughnut';
    const format = (v) => formatValue(Math.round(v), props.chart.format, props.currency);

    instance = new Chart(canvas.value, {
        type: props.chart.type,
        data: {
            labels: props.chart.labels,
            datasets: props.chart.datasets.map((d, i) => ({
                label: d.label,
                data: d.data,
                backgroundColor: doughnut ? palette : palette[i % palette.length] + (props.chart.type === 'line' ? '33' : ''),
                borderColor: doughnut ? '#fff' : palette[i % palette.length],
                borderWidth: props.chart.type === 'line' ? 2 : doughnut ? 2 : 0,
                fill: props.chart.type === 'line' && i === 0,
                tension: 0.3,
                pointRadius: props.chart.labels.length > 40 ? 0 : 3,
                borderRadius: props.chart.type === 'bar' ? 4 : 0,
            })),
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            indexAxis: props.chart.horizontal ? 'y' : 'x',
            plugins: {
                legend: { display: doughnut || props.chart.datasets.length > 1, position: 'bottom' },
                tooltip: { callbacks: { label: (ctx) => `${ctx.dataset.label}: ${format(ctx.raw)}` } },
            },
            scales: doughnut
                ? {}
                : {
                      [props.chart.horizontal ? 'x' : 'y']: { beginAtZero: true, ticks: { callback: (v) => (props.chart.format === 'money' ? String(formatValue(Math.round(v), 'money', '')).trim().replace(/\.00$/, '') : v) } },
                      [props.chart.horizontal ? 'y' : 'x']: { grid: { display: false } },
                  },
        },
    });
};

onMounted(draw);
watch(() => props.chart, draw, { deep: true });
onBeforeUnmount(() => instance?.destroy());
</script>

<template>
    <div class="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-gray-200">
        <h3 class="mb-2 text-sm font-semibold text-gray-700">{{ chart.title }}</h3>
        <p v-if="chart.datasets.every((d) => d.data.every((v) => !v))" class="flex h-40 items-center justify-center text-sm text-gray-400">No data yet.</p>
        <div v-else :class="chart.type === 'doughnut' ? 'h-64' : chart.horizontal ? 'h-80' : 'h-64'">
            <canvas ref="canvas" role="img" :aria-label="chart.title" />
        </div>
    </div>
</template>
