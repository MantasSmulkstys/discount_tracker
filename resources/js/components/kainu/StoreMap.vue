<script setup lang="ts">
import { importLibrary, setOptions } from '@googlemaps/js-api-loader';
import {
    AlertTriangle,
    Loader2,
    MapPin,
    Navigation,
    TriangleAlert,
} from 'lucide-vue-next';
import {
    computed,
    onBeforeUnmount,
    ref,
    shallowRef,
    watch,
} from 'vue';
import { resolveStore } from '@/lib/kainu';
import type { GoogleMapsConfig, KainuStoreLocation } from '@/types/kainu';

const props = defineProps<{
    config: GoogleMapsConfig;
    locations: KainuStoreLocation[];
    /** Optional subset of chain slugs to show — lets the Cart page focus on chains actually in cart. */
    highlightChains?: string[] | null;
}>();

// Track whether the Maps JavaScript API options have been set this session.
// `setOptions()` must be called once before the first `importLibrary()` call.
let mapsOptionsApplied = false;

type UserCoord = { lat: number; lng: number };
type Status = 'idle' | 'prompting' | 'denied' | 'unsupported' | 'error' | 'ready';

const mapEl = ref<HTMLDivElement | null>(null);
const map = shallowRef<google.maps.Map | null>(null);
const markers = shallowRef<google.maps.Marker[]>([]);
const userMarker = shallowRef<google.maps.Marker | null>(null);
const loading = ref(false);
const status = ref<Status>('idle');
const errorMessage = ref<string | null>(null);
const userCoord = ref<UserCoord | null>(null);
const mapInitialized = ref(false);

// Central Lithuania fallback center (Kėdainiai-ish) when the user refuses geolocation.
const FALLBACK_CENTER = { lat: 55.3, lng: 23.9 };

const hasKey = computed(() => !!props.config.apiKey);

const highlightSet = computed<Set<string>>(() => {
    if (!props.highlightChains || props.highlightChains.length === 0) {
        return new Set();
    }

    return new Set(props.highlightChains);
});

const visibleLocations = computed<KainuStoreLocation[]>(() => {
    const set = highlightSet.value;

    if (set.size === 0) {
        return props.locations;
    }

    return props.locations.filter((l) => set.has(l.chain));
});

function distanceKm(a: UserCoord, b: UserCoord): number {
    const toRad = (deg: number) => (deg * Math.PI) / 180;
    const R = 6371;
    const dLat = toRad(b.lat - a.lat);
    const dLng = toRad(b.lng - a.lng);
    const lat1 = toRad(a.lat);
    const lat2 = toRad(b.lat);

    const h =
        Math.sin(dLat / 2) ** 2 +
        Math.cos(lat1) * Math.cos(lat2) * Math.sin(dLng / 2) ** 2;

    return 2 * R * Math.asin(Math.sqrt(h));
}

const rankedLocations = computed<KainuStoreLocation[]>(() => {
    if (!userCoord.value) {
        return visibleLocations.value;
    }

    const from = userCoord.value;

    return [...visibleLocations.value]
        .map((loc) => ({
            ...loc,
            distanceKm: distanceKm(from, { lat: loc.lat, lng: loc.lng }),
        }))
        .sort((a, b) => (a.distanceKm ?? 0) - (b.distanceKm ?? 0));
});

const closestFive = computed(() => rankedLocations.value.slice(0, 5));

function formatDistance(km: number | null | undefined): string {
    if (km === null || km === undefined) {
        return '';
    }

    if (km < 1) {
        return `${Math.round(km * 1000)} m`;
    }

    return `${km.toFixed(km < 10 ? 1 : 0)} km`;
}

function markerSvg(color: string, isUser = false): string {
    if (isUser) {
        return [
            `<svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 28 28">`,
            `<circle cx="14" cy="14" r="12" fill="${color}" opacity="0.25"/>`,
            `<circle cx="14" cy="14" r="7" fill="${color}"/>`,
            `<circle cx="14" cy="14" r="3" fill="white"/>`,
            `</svg>`,
        ].join('');
    }

    return [
        `<svg xmlns="http://www.w3.org/2000/svg" width="32" height="40" viewBox="0 0 32 40">`,
        `<path d="M16 0C7.2 0 0 7.2 0 16c0 11 16 24 16 24s16-13 16-24C32 7.2 24.8 0 16 0z" fill="${color}"/>`,
        `<circle cx="16" cy="16" r="6" fill="white"/>`,
        `</svg>`,
    ].join('');
}

async function ensureMap(): Promise<google.maps.Map | null> {
    if (!hasKey.value) {
        return null;
    }

    if (map.value) {
        return map.value;
    }

    if (!mapEl.value) {
        return null;
    }

    try {
        if (!mapsOptionsApplied) {
            setOptions({
                key: props.config.apiKey as string,
                v: 'weekly',
                libraries: ['places'],
                ...(props.config.mapId
                    ? { mapIds: [props.config.mapId] }
                    : {}),
            });
            mapsOptionsApplied = true;
        }

        const { Map } = await importLibrary('maps');

        map.value = new Map(mapEl.value, {
            center: userCoord.value ?? FALLBACK_CENTER,
            zoom: userCoord.value ? 13 : 7,
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
            err instanceof Error
                ? err.message
                : 'Nepavyko užkrauti žemėlapio.';
        status.value = 'error';

        return null;
    }
}

function clearMarkers(): void {
    for (const m of markers.value) {
        m.setMap(null);
    }

    markers.value = [];
}

function renderMarkers(m: google.maps.Map): void {
    clearMarkers();

    const bounds = new google.maps.LatLngBounds();

    if (userCoord.value) {
        userMarker.value?.setMap(null);
        userMarker.value = new google.maps.Marker({
            position: userCoord.value,
            map: m,
            title: 'Jūs esate čia',
            icon: {
                url:
                    'data:image/svg+xml;utf8,' +
                    encodeURIComponent(markerSvg('#15803d', true)),
                scaledSize: new google.maps.Size(28, 28),
                anchor: new google.maps.Point(14, 14),
            },
            zIndex: 99,
        });
        bounds.extend(userCoord.value);
    }

    const limit = userCoord.value ? 8 : visibleLocations.value.length;
    const list = (
        userCoord.value ? rankedLocations.value : visibleLocations.value
    ).slice(0, limit);

    for (const loc of list) {
        const meta = resolveStore(loc.chain);
        const marker = new google.maps.Marker({
            position: { lat: loc.lat, lng: loc.lng },
            map: m,
            title: `${loc.name} — ${loc.address}`,
            icon: {
                url:
                    'data:image/svg+xml;utf8,' +
                    encodeURIComponent(markerSvg(meta.color)),
                scaledSize: new google.maps.Size(32, 40),
                anchor: new google.maps.Point(16, 40),
            },
        });

        const distanceLine = loc.distanceKm
            ? `<div style="color:#15803d;font-weight:600;margin-top:4px;">${formatDistance(loc.distanceKm)} nuo Jūsų</div>`
            : '';

        const info = new google.maps.InfoWindow({
            content: `
                <div style="font-family: 'Instrument Sans', system-ui, sans-serif; min-width: 220px;">
                    <div style="font-weight:700;color:#111827;">${loc.name}</div>
                    <div style="color:#6b7280;font-size:12px;margin-top:2px;">${loc.address}, ${loc.city}</div>
                    ${distanceLine}
                </div>
            `,
        });

        marker.addListener('click', () => info.open({ anchor: marker, map: m }));
        markers.value.push(marker);
        bounds.extend({ lat: loc.lat, lng: loc.lng });
    }

    if (!bounds.isEmpty() && markers.value.length + (userCoord.value ? 1 : 0) > 1) {
        m.fitBounds(bounds, 64);
    } else if (userCoord.value) {
        m.setCenter(userCoord.value);
        m.setZoom(13);
    }
}

async function loadMap(): Promise<void> {
    const m = await ensureMap();

    if (m) {
        renderMarkers(m);
    }
}

async function locateMe(): Promise<void> {
    if (!hasKey.value) {
        status.value = 'error';
        errorMessage.value =
            'Google Maps raktas nenustatytas — nurodykite GOOGLE_MAPS_API_KEY .env faile.';

        return;
    }

    if (typeof navigator === 'undefined' || !navigator.geolocation) {
        status.value = 'unsupported';

        return;
    }

    status.value = 'prompting';
    loading.value = true;
    errorMessage.value = null;

    navigator.geolocation.getCurrentPosition(
        async (position) => {
            userCoord.value = {
                lat: position.coords.latitude,
                lng: position.coords.longitude,
            };
            status.value = 'ready';
            loading.value = false;
            await loadMap();
        },
        (geoError) => {
            loading.value = false;

            if (geoError.code === geoError.PERMISSION_DENIED) {
                status.value = 'denied';
            } else {
                status.value = 'error';
                errorMessage.value =
                    geoError.message ||
                    'Nepavyko nustatyti Jūsų vietos. Leiskite naršyklei dalintis vieta.';
            }

            // Still show the map centered on Lithuania so the user sees the full store network.
            void loadMap();
        },
        {
            enableHighAccuracy: true,
            timeout: 8000,
            maximumAge: 60_000,
        },
    );
}

watch(
    () => [visibleLocations.value, userCoord.value],
    () => {
        if (map.value) {
            renderMarkers(map.value);
        }
    },
);

onBeforeUnmount(() => {
    clearMarkers();
    userMarker.value?.setMap(null);
    userMarker.value = null;
    map.value = null;
});
</script>

<template>
    <section
        class="flex flex-col overflow-hidden rounded-[16px] border border-kainu-border bg-white"
    >
        <header
            class="flex items-start justify-between gap-4 border-b border-kainu-border px-5 py-4"
        >
            <div>
                <h3
                    class="flex items-center gap-2 text-[15px] font-semibold text-stone-900"
                >
                    <MapPin :size="16" :stroke-width="2" class="text-brand-700" />
                    Artimiausios parduotuvės
                </h3>
                <p class="mt-1 text-[12px] text-stone-500">
                    Leiskite peržiūrėti Jūsų vietą ir parodysime 5 arčiausiai
                    esančias parduotuves su krepšelyje pasirinktomis prekėmis.
                </p>
            </div>
            <button
                type="button"
                :disabled="loading"
                class="inline-flex h-9 shrink-0 items-center gap-2 rounded-[10px] bg-brand-700 px-3 text-[12px] font-semibold text-white transition-colors hover:bg-brand-800 disabled:opacity-60"
                @click="locateMe"
            >
                <Loader2
                    v-if="loading"
                    :size="14"
                    class="animate-spin"
                    :stroke-width="2"
                />
                <Navigation v-else :size="14" :stroke-width="2" />
                {{ userCoord ? 'Atnaujinti vietą' : 'Naudoti mano vietą' }}
            </button>
        </header>

        <div
            v-if="!hasKey"
            class="flex flex-col items-start gap-3 bg-amber-50 px-5 py-4 text-[12px] text-amber-900"
        >
            <div class="flex items-center gap-2 font-semibold">
                <AlertTriangle :size="14" :stroke-width="2" />
                Google Maps raktas nenustatytas
            </div>
            <p class="leading-5">
                Norėdami pamatyti interaktyvų žemėlapį, pridėkite
                <code
                    class="rounded bg-amber-100 px-1.5 py-0.5 font-mono text-[11px]"
                >
                    GOOGLE_MAPS_API_KEY
                </code>
                į
                <code
                    class="rounded bg-amber-100 px-1.5 py-0.5 font-mono text-[11px]"
                >
                    .env
                </code>
                failą ir iš naujo paleiskite
                <code
                    class="rounded bg-amber-100 px-1.5 py-0.5 font-mono text-[11px]"
                >
                    php artisan config:clear
                </code>
                .
            </p>
        </div>

        <div
            v-else-if="status === 'denied'"
            class="flex items-center gap-2 bg-amber-50 px-5 py-3 text-[12px] text-amber-900"
        >
            <TriangleAlert :size="14" :stroke-width="2" />
            Atsisakėte dalintis vieta. Parodysime visas parduotuves, tačiau be
            atstumo informacijos.
        </div>

        <div
            v-else-if="status === 'unsupported'"
            class="flex items-center gap-2 bg-amber-50 px-5 py-3 text-[12px] text-amber-900"
        >
            <TriangleAlert :size="14" :stroke-width="2" />
            Naršyklė nepalaiko vietos nustatymo.
        </div>

        <div
            v-else-if="status === 'error' && errorMessage"
            class="flex items-center gap-2 bg-red-50 px-5 py-3 text-[12px] text-red-700"
        >
            <AlertTriangle :size="14" :stroke-width="2" />
            {{ errorMessage }}
        </div>

        <div class="relative">
            <div
                ref="mapEl"
                class="h-[340px] w-full bg-kainu-surface"
                :class="{ 'opacity-0 pointer-events-none': !hasKey }"
            />
            <div
                v-if="!mapInitialized"
                class="pointer-events-none absolute inset-0 flex flex-col items-center justify-center gap-2 text-[12px] text-stone-500"
            >
                <MapPin :size="22" :stroke-width="1.5" class="text-stone-400" />
                <span v-if="hasKey">
                    Paspauskite „Naudoti mano vietą“, kad būtų parodytos
                    arčiausiai esančios parduotuvės.
                </span>
                <span v-else>Žemėlapio peržiūra neprieinama.</span>
            </div>
        </div>

        <div
            v-if="userCoord && closestFive.length > 0"
            class="border-t border-kainu-border"
        >
            <div
                class="border-b border-kainu-border bg-kainu-surface px-5 py-2 text-[11px] font-semibold tracking-[0.12em] text-stone-500 uppercase"
            >
                5 arčiausios parduotuvės
            </div>
            <ul class="divide-y divide-kainu-border">
                <li
                    v-for="loc in closestFive"
                    :key="loc.id"
                    class="flex items-center gap-3 px-5 py-3"
                >
                    <span
                        class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-[11px] font-bold text-white"
                        :style="{
                            backgroundColor: resolveStore(loc.chain).color,
                        }"
                    >
                        {{ resolveStore(loc.chain).short }}
                    </span>
                    <div class="min-w-0 flex-1">
                        <div
                            class="truncate text-[13px] font-semibold text-stone-900"
                        >
                            {{ loc.name }}
                        </div>
                        <div class="truncate text-[11px] text-stone-500">
                            {{ loc.address }}, {{ loc.city }}
                        </div>
                    </div>
                    <div
                        class="shrink-0 text-[12px] font-semibold text-brand-700 tabular-nums"
                    >
                        {{ formatDistance(loc.distanceKm) }}
                    </div>
                </li>
            </ul>
        </div>
    </section>
</template>
