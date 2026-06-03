<script setup lang="ts">
import { computed, ref } from 'vue';
import StoreLogo from '@/components/kainu/StoreLogo.vue';
import { formatPrice } from '@/lib/kainu';

export type HistoryPoint = {
    date: string;
    price: number;
    oldPrice: number | null;
    name: string;
    store: string;
    storeName: string;
    sizeLabel: string | null;
    condition: string | null;
    imageUrl: string | null;
};

type Props = {
    values: number[];
    labels?: string[];
    dates?: string[];
    points?: HistoryPoint[];
    width?: number;
    height?: number;
};

const props = withDefaults(defineProps<Props>(), {
    labels: () => [],
    dates: () => [],
    points: () => [],
    width: 900,
    height: 220,
});

const hoverIndex = ref<number | null>(null);

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

    const weeksSpan = (() => {
        if (props.dates.length >= 2) {
            const start = new Date(props.dates[0]).getTime();
            const end = new Date(props.dates[props.dates.length - 1]).getTime();

            if (Number.isFinite(start) && Number.isFinite(end) && end > start) {
                const days = (end - start) / (1000 * 60 * 60 * 24);

                return Math.max(1, Math.round(days / 7));
            }
        }

        return Math.max(1, props.values.length - 1);
    })();

    const labels =
        props.labels.length === props.values.length
            ? props.labels
            : props.values.map((_, i) =>
                  i === props.values.length - 1
                      ? 'Dabar'
                      : `−${props.values.length - 1 - i}sav`,
              );

    // Half the gap between points, so each hover band fills its own column.
    const bandHalf =
        points.length > 1 ? (points[1].x - points[0].x) / 2 : W / 2;

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
        weeksSpan,
        bandHalf,
    };
});

const hoverPoint = computed<HistoryPoint | null>(() => {
    if (hoverIndex.value === null) {
        return null;
    }

    return props.points[hoverIndex.value] ?? null;
});

// Position the tooltip as a percentage of the viewBox so it stays glued to the
// point regardless of how the responsive SVG is scaled.
const tooltipStyle = computed(() => {
    if (hoverIndex.value === null || !chart.value) {
        return {};
    }

    const p = chart.value.points[hoverIndex.value];

    return {
        left: `${(p.x / props.width) * 100}%`,
        top: `${(p.y / props.height) * 100}%`,
    };
});

const discountPct = (point: HistoryPoint): number | null => {
    if (
        point.oldPrice === null ||
        point.oldPrice <= 0 ||
        point.oldPrice <= point.price
    ) {
        return null;
    }

    return Math.round(((point.oldPrice - point.price) / point.oldPrice) * 100);
};
</script>

<template>
    <div v-if="chart" class="relative mx-auto w-full" :style="{ maxWidth: `${width}px` }">
        <svg
            :viewBox="`0 0 ${width} ${height}`"
            preserveAspectRatio="xMidYMid meet"
            class="block w-full"
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
                    :r="
                        hoverIndex === i
                            ? 6
                            : i === chart.points.length - 1
                              ? 5
                              : 3
                    "
                    :fill="
                        hoverIndex === i || i === chart.points.length - 1
                            ? '#15803d'
                            : '#fff'
                    "
                    stroke="#15803d"
                    stroke-width="2"
                />
                <g v-if="i === chart.points.length - 1 && hoverIndex === null">
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
                    :fill="hoverIndex === i ? '#15803d' : '#a8a29e'"
                    :font-weight="hoverIndex === i ? '600' : '400'"
                >
                    {{ chart.labels[i] }}
                </text>
            </g>

            <!-- Transparent hover bands: one per point, so the whole column is
                 a comfortable hit target rather than just the small dot. -->
            <rect
                v-for="(p, i) in chart.points"
                :key="`band-${i}`"
                :x="p.x - chart.bandHalf"
                :y="chart.pad.t"
                :width="chart.bandHalf * 2"
                :height="chart.H"
                fill="transparent"
                style="cursor: pointer"
                @mouseenter="hoverIndex = i"
                @mouseleave="hoverIndex = null"
            />

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
                    }}{{ chart.change.toFixed(1) }}% per {{ chart.weeksSpan }}sav
                </text>
            </g>
        </svg>

        <!-- Compact listing preview for the hovered week. -->
        <div
            v-if="hoverPoint"
            class="pointer-events-none absolute z-20 w-56 -translate-x-1/2 -translate-y-full"
            :style="tooltipStyle"
        >
            <div
                class="mb-2 flex gap-2 rounded-xl border border-stone-200 bg-white p-2.5 shadow-lg"
            >
                <img
                    v-if="hoverPoint.imageUrl"
                    :src="hoverPoint.imageUrl"
                    :alt="hoverPoint.name"
                    class="h-12 w-12 shrink-0 rounded-md border border-stone-100 bg-white object-contain"
                />
                <div class="flex min-w-0 flex-1 flex-col">
                    <div class="flex items-center gap-1">
                        <StoreLogo :store-id="hoverPoint.store" :size="14" />
                        <span class="truncate text-[11px] text-stone-500">
                            {{ hoverPoint.storeName }}
                        </span>
                        <span class="ml-auto text-[10px] text-stone-400">
                            {{ hoverPoint.date }}
                        </span>
                    </div>
                    <div
                        class="mt-0.5 line-clamp-2 text-[11px] leading-snug font-medium text-stone-800"
                    >
                        {{ hoverPoint.name }}
                    </div>
                    <div class="mt-1 flex items-baseline gap-1.5">
                        <span
                            class="text-[14px] font-bold tracking-tight text-[#15803d]"
                        >
                            {{ formatPrice(hoverPoint.price) }}
                        </span>
                        <span
                            v-if="
                                hoverPoint.oldPrice &&
                                hoverPoint.oldPrice > hoverPoint.price
                            "
                            class="text-[10px] text-stone-400 line-through"
                        >
                            {{ formatPrice(hoverPoint.oldPrice) }}
                        </span>
                        <span
                            v-if="discountPct(hoverPoint)"
                            class="rounded bg-emerald-50 px-1 text-[9px] font-semibold text-emerald-700"
                        >
                            −{{ discountPct(hoverPoint) }}%
                        </span>
                    </div>
                    <div
                        v-if="hoverPoint.sizeLabel || hoverPoint.condition"
                        class="mt-0.5 flex flex-wrap gap-x-2 text-[10px] text-stone-500"
                    >
                        <span v-if="hoverPoint.sizeLabel">{{
                            hoverPoint.sizeLabel
                        }}</span>
                        <span v-if="hoverPoint.condition">{{
                            hoverPoint.condition
                        }}</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
