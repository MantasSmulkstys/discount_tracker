<script setup lang="ts">
import { AlertTriangle, MapPin } from 'lucide-vue-next';
import { computed, onBeforeUnmount, ref, shallowRef, watch } from 'vue';
import { configureMaps, loadMapsLibrary } from '@/composables/useGoogleMaps';
import { resolveStore } from '@/lib/kainu';
import type { GoogleMapsConfig } from '@/types/kainu';

type LatLng = { lat: number; lng: number };

type Stop = {
    chain: string;
    name: string;
    address: string;
    city: string;
    lat: number;
    lng: number;
};

const props = defineProps<{
    config: GoogleMapsConfig;
    origin: LatLng | null;
    stops: Stop[];
    routePolyline: string | null;
}>();

const mapEl = ref<HTMLDivElement | null>(null);
const map = shallowRef<google.maps.Map | null>(null);
const markers = shallowRef<google.maps.Marker[]>([]);
const originMarker = shallowRef<google.maps.Marker | null>(null);
const routeLine = shallowRef<google.maps.Polyline | null>(null);
const errorMessage = ref<string | null>(null);
const mapInitialized = ref(false);

const FALLBACK_CENTER = { lat: 55.3, lng: 23.9 };

const hasKey = computed(() => Boolean(props.config.apiKey));
const hasOrigin = computed(() => props.origin !== null);

function originPinSvg(): string {
    return [
        '<svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 28 28">',
        '<circle cx="14" cy="14" r="12" fill="#15803d" opacity="0.25"/>',
        '<circle cx="14" cy="14" r="7" fill="#15803d"/>',
        '<circle cx="14" cy="14" r="3" fill="white"/>',
        '</svg>',
    ].join('');
}

function stopPinSvg(color: string, label: string): string {
    return [
        '<svg xmlns="http://www.w3.org/2000/svg" width="36" height="44" viewBox="0 0 36 44">',
        `<path d="M18 0C8 0 0 8 0 18c0 12 18 26 18 26s18-14 18-26C36 8 28 0 18 0z" fill="${color}"/>`,
        '<circle cx="18" cy="18" r="9" fill="white"/>',
        `<text x="18" y="22" font-family="Inter, system-ui, sans-serif" font-size="13" font-weight="700" text-anchor="middle" fill="${color}">${label}</text>`,
        '</svg>',
    ].join('');
}

async function ensureMap(): Promise<google.maps.Map | null> {
    if (!hasKey.value || !mapEl.value) {
        return null;
    }

    if (map.value) {
        return map.value;
    }

    try {
        configureMaps({
            apiKey: props.config.apiKey as string,
            mapId: props.config.mapId,
        });

        const { Map } = await loadMapsLibrary<typeof google.maps>('maps');

        map.value = new Map(mapEl.value, {
            center: props.origin ?? FALLBACK_CENTER,
            zoom: props.origin ? 12 : 7,
            mapTypeControl: false,
            streetViewControl: false,
            fullscreenControl: false,
            clickableIcons: false,
            mapId: props.config.mapId ?? undefined,
        });

        mapInitialized.value = true;

        return map.value;
    } catch (err) {
        errorMessage.value =
            err instanceof Error ? err.message : 'Nepavyko užkrauti žemėlapio.';

        return null;
    }
}

function clearMarkers(): void {
    for (const m of markers.value) {
        m.setMap(null);
    }

    markers.value = [];
}

function clearRouteLine(): void {
    routeLine.value?.setMap(null);
    routeLine.value = null;
}

async function renderMap(): Promise<void> {
    const m = await ensureMap();

    if (!m) {
        return;
    }

    clearMarkers();

    const bounds = new google.maps.LatLngBounds();

    if (props.origin) {
        originMarker.value?.setMap(null);
        originMarker.value = new google.maps.Marker({
            position: props.origin,
            map: m,
            title: 'Pradžios vieta',
            icon: {
                url: 'data:image/svg+xml;utf8,' + encodeURIComponent(originPinSvg()),
                scaledSize: new google.maps.Size(28, 28),
                anchor: new google.maps.Point(14, 14),
            },
            zIndex: 99,
        });
        bounds.extend(props.origin);
    }

    props.stops.forEach((stop, idx) => {
        const meta = resolveStore(stop.chain);
        const marker = new google.maps.Marker({
            position: { lat: stop.lat, lng: stop.lng },
            map: m,
            title: `${stop.name} — ${stop.address}`,
            icon: {
                url:
                    'data:image/svg+xml;utf8,' +
                    encodeURIComponent(stopPinSvg(meta.color, String(idx + 1))),
                scaledSize: new google.maps.Size(36, 44),
                anchor: new google.maps.Point(18, 44),
            },
        });

        const info = new google.maps.InfoWindow({
            content: `
                <div style="font-family:'Instrument Sans',system-ui,sans-serif;min-width:220px;">
                    <div style="font-weight:700;color:#111827;">${stop.name}</div>
                    <div style="color:#6b7280;font-size:12px;margin-top:2px;">${stop.address}${stop.city ? ', ' + stop.city : ''}</div>
                </div>
            `,
        });

        marker.addListener('click', () => info.open({ anchor: marker, map: m }));
        markers.value.push(marker);
        bounds.extend({ lat: stop.lat, lng: stop.lng });
    });

    clearRouteLine();

    if (props.routePolyline) {
        const { encoding } = await loadMapsLibrary<typeof google.maps.geometry>('geometry');
        const path = encoding.decodePath(props.routePolyline);

        if (path.length > 0) {
            routeLine.value = new google.maps.Polyline({
                path,
                map: m,
                geodesic: true,
                strokeColor: '#15803d',
                strokeOpacity: 0.85,
                strokeWeight: 4,
            });

            for (const p of path) {
                bounds.extend(p);
            }
        }
    }

    if (!bounds.isEmpty()) {
        const totalPoints = markers.value.length + (props.origin ? 1 : 0);

        if (totalPoints > 1) {
            m.fitBounds(bounds, 64);
        } else if (props.origin) {
            m.setCenter(props.origin);
            m.setZoom(13);
        }
    }
}

watch(
    () => [props.origin, props.stops, props.routePolyline],
    () => {
        if (hasOrigin.value) {
            void renderMap();
        }
    },
    { deep: true },
);

watch(hasOrigin, (next) => {
    if (next) {
        void renderMap();
    }
});

onBeforeUnmount(() => {
    clearMarkers();
    clearRouteLine();
    originMarker.value?.setMap(null);
    originMarker.value = null;
    map.value = null;
});
</script>

<template>
    <section
        class="flex flex-col overflow-hidden rounded-[16px] border border-kainu-border bg-white"
    >
        <div
            v-if="!hasKey"
            class="flex items-start gap-2 bg-amber-50 px-5 py-4 text-[12px] text-amber-900"
        >
            <AlertTriangle :size="14" :stroke-width="2" class="mt-0.5 shrink-0" />
            <span>
                Google Maps raktas nenustatytas — pridėkite
                <code class="rounded bg-amber-100 px-1.5 py-0.5 font-mono text-[11px]">
                    GOOGLE_MAPS_API_KEY
                </code>
                į <code class="rounded bg-amber-100 px-1.5 py-0.5 font-mono text-[11px]">.env</code>.
            </span>
        </div>

        <div
            v-else-if="errorMessage"
            class="flex items-start gap-2 bg-red-50 px-5 py-3 text-[12px] text-red-700"
        >
            <AlertTriangle :size="14" :stroke-width="2" class="mt-0.5 shrink-0" />
            <span>{{ errorMessage }}</span>
        </div>

        <div class="relative">
            <div
                ref="mapEl"
                class="h-[420px] w-full bg-kainu-surface"
                :class="{ 'opacity-0 pointer-events-none': !hasKey || !hasOrigin }"
            />
            <div
                v-if="!hasOrigin"
                class="pointer-events-none absolute inset-0 flex flex-col items-center justify-center gap-2 px-6 text-center text-[12px] text-stone-500"
            >
                <MapPin :size="22" :stroke-width="1.5" class="text-stone-400" />
                <span>
                    Įveskite pradžios adresą kairėje, kad pamatytumėte
                    optimalų maršrutą per krepšelio parduotuves.
                </span>
            </div>
        </div>
    </section>
</template>
