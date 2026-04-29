<script setup lang="ts">
import { computed } from 'vue';
import { formatPrice } from '@/lib/kainu';

type Props = {
    values: number[];
    labels?: string[];
    width?: number;
    height?: number;
};

const props = withDefaults(defineProps<Props>(), {
    labels: () => [],
    width: 900,
    height: 220,
});

const chart = computed(() => {
    if (!props.values.length) {
        return null;
    }

    const pad = { t: 20, r: 20, b: 28, l: 56 };
    const W = props.width - pad.l - pad.r;
    const H = props.height - pad.t - pad.b;
    const min = Math.min(...props.values) * 0.9;
    const max = Math.max(...props.values) * 1.05;
    const range = max - min || 1;

    const points = props.values.map((v, i) => {
        const x = pad.l + (i / Math.max(props.values.length - 1, 1)) * W;
        const y = pad.t + H - ((v - min) / range) * H;

        return { x, y, v };
    });

    const linePath = points
        .map(
            (p, i) =>
                `${i === 0 ? 'M' : 'L'}${p.x.toFixed(1)},${p.y.toFixed(1)}`,
        )
        .join(' ');
    const areaPath =
        linePath +
        ` L ${points[points.length - 1].x.toFixed(1)} ${(pad.t + H).toFixed(1)}` +
        ` L ${points[0].x.toFixed(1)} ${(pad.t + H).toFixed(1)} Z`;

    const gridLines = [0, 0.25, 0.5, 0.75, 1].map((t) => {
        const y = pad.t + H * t;
        const val = max - range * t;

        return { y, val };
    });

    const latest = props.values[props.values.length - 1];
    const first = props.values[0];
    const change = first !== 0 ? ((latest - first) / first) * 100 : 0;

    const labels =
        props.labels.length === props.values.length
            ? props.labels
            : props.values.map((_, i) =>
                  i === props.values.length - 1
                      ? 'Dabar'
                      : `−${props.values.length - 1 - i}sav`,
              );

    return {
        pad,
        W,
        H,
        points,
        linePath,
        areaPath,
        gridLines,
        change,
        labels,
    };
});
</script>

<template>
    <div v-if="chart" class="w-full">
        <svg
            :viewBox="`0 0 ${width} ${height}`"
            preserveAspectRatio="xMidYMid meet"
            class="block w-full"
            :style="{ maxWidth: `${width}px` }"
        >
            <defs>
                <linearGradient id="priceFill" x1="0" x2="0" y1="0" y2="1">
                    <stop
                        offset="0%"
                        stop-color="#15803d"
                        stop-opacity="0.18"
                    />
                    <stop offset="100%" stop-color="#15803d" stop-opacity="0" />
                </linearGradient>
            </defs>

            <g v-for="(g, idx) in chart.gridLines" :key="`g-${idx}`">
                <line
                    :x1="chart.pad.l"
                    :x2="chart.pad.l + chart.W"
                    :y1="g.y"
                    :y2="g.y"
                    stroke="#e7e5e0"
                    stroke-dasharray="2 3"
                />
                <text
                    :x="chart.pad.l - 10"
                    :y="g.y + 4"
                    text-anchor="end"
                    font-size="10"
                    fill="#a8a29e"
                >
                    {{ formatPrice(g.val) }}
                </text>
            </g>

            <path :d="chart.areaPath" fill="url(#priceFill)" />
            <path
                :d="chart.linePath"
                fill="none"
                stroke="#15803d"
                stroke-width="2"
                stroke-linejoin="round"
            />

            <g v-for="(p, i) in chart.points" :key="`p-${i}`">
                <circle
                    :cx="p.x"
                    :cy="p.y"
                    :r="i === chart.points.length - 1 ? 5 : 3"
                    :fill="i === chart.points.length - 1 ? '#15803d' : '#fff'"
                    stroke="#15803d"
                    stroke-width="2"
                />
                <g v-if="i === chart.points.length - 1">
                    <rect
                        :x="p.x - 34"
                        :y="p.y - 30"
                        width="68"
                        height="20"
                        rx="4"
                        fill="#1c1917"
                    />
                    <text
                        :x="p.x"
                        :y="p.y - 16"
                        text-anchor="middle"
                        font-size="11"
                        font-weight="600"
                        fill="#fff"
                    >
                        {{ formatPrice(p.v) }}
                    </text>
                </g>
                <text
                    :x="p.x"
                    :y="height - 8"
                    text-anchor="middle"
                    font-size="10"
                    fill="#a8a29e"
                >
                    {{ chart.labels[i] }}
                </text>
            </g>

            <g
                :transform="`translate(${chart.pad.l + chart.W - 96}, ${chart.pad.t})`"
            >
                <rect
                    width="90"
                    height="22"
                    rx="6"
                    :fill="chart.change < 0 ? '#f0f9f4' : '#fef2f2'"
                />
                <text
                    x="45"
                    y="15"
                    text-anchor="middle"
                    font-size="11"
                    font-weight="600"
                    :fill="chart.change < 0 ? '#15803d' : '#dc2626'"
                >
                    {{ chart.change >= 0 ? '+' : ''
                    }}{{ chart.change.toFixed(1) }}% per 7sav
                </text>
            </g>
        </svg>
    </div>
</template>
