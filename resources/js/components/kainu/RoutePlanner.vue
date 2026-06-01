<script setup lang="ts">
import { useHttp } from '@inertiajs/vue3';
import {
    AlertTriangle,
    Car,
    Clock,
    ExternalLink,
    Footprints,
    Loader2,
    Locate,
    MapPin,
    MapPinned,
    Navigation,
    Route as RouteIcon,
    TriangleAlert,
} from 'lucide-vue-next';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import RoutePlanController from '@/actions/App/Http/Controllers/RoutePlanController';
import { configureMaps, loadMapsLibrary } from '@/composables/useGoogleMaps';
import { resolveStore } from '@/lib/kainu';

type TravelMode = 'walking' | 'driving';
type LatLng = { lat: number; lng: number };

type Stop = {
    chain: string;
    name: string;
    address: string;
    city: string;
    lat: number;
    lng: number;
    durationFromPreviousSec: number;
    distanceFromPreviousMeters: number;
};

type CartItemPayload = {
    familyId: number | null;
    normalizedProductId: number | null;
    variantId: number | null;
    quantity: number;
    storeSlug: string | null;
    priceCents: number | null;
};

type AlternativeItem = {
    familyId: number | null;
    normalizedProductId: number | null;
    variantId: number | null;
    storeSlug: string;
    priceCents: number;
    quantity: number;
    name: string;
    fixed: boolean;
};

type Alternative = {
    label: string;
    stores: string[];
    totalPriceCents: number;
    totalDurationSec: number;
    priceDeltaCents: number;
    durationDeltaSec: number;
    items: AlternativeItem[];
};

type RoutePlan = {
    mode: TravelMode;
    stops: Stop[];
    returnLeg: { durationSec: number; distanceMeters: number };
    polyline: string;
    totalDurationSec: number;
    totalDistanceMeters: number;
    alternatives?: Alternative[];
};

const props = defineProps<{
    chains: string[];
    hasMapsKey: boolean;
    apiKey: string | null;
    items?: CartItemPayload[];
}>();

const emit = defineEmits<{
    (e: 'plan', plan: RoutePlan | null): void;
    (e: 'origin', origin: LatLng | null): void;
}>();

type Status = 'idle' | 'locating' | 'planning' | 'ready' | 'error';

const status = ref<Status>('idle');
const errorMessage = ref<string | null>(null);
const mode = ref<TravelMode>('driving');
const origin = ref<LatLng | null>(null);
const originLabel = ref<string>('');
const plan = ref<RoutePlan | null>(null);
const addressInput = ref<HTMLInputElement | null>(null);
let autocomplete: google.maps.places.Autocomplete | null = null;

type RoutePlanForm = {
    origin: LatLng;
    chains: string[];
    mode: TravelMode;
    items: CartItemPayload[];
};

const planForm = useHttp<RoutePlanForm, RoutePlan>({
    origin: { lat: 0, lng: 0 },
    chains: [],
    mode: 'driving',
    items: [],
});

const formatDuration = (sec: number): string => {
    if (sec < 60) {
        return `${sec}s`;
    }

    const totalMin = Math.round(sec / 60);

    if (totalMin < 60) {
        return `${totalMin} min`;
    }

    const h = Math.floor(totalMin / 60);
    const m = totalMin % 60;

    return m === 0 ? `${h} h` : `${h} h ${m} min`;
};

const formatDistance = (m: number): string => {
    if (m < 1000) {
        return `${m} m`;
    }

    const km = m / 1000;

    return `${km.toFixed(km < 10 ? 1 : 0)} km`;
};

async function ensureAutocomplete(): Promise<void> {
    if (!props.apiKey || autocomplete || !addressInput.value) {
        return;
    }

    configureMaps({ apiKey: props.apiKey });
    await loadMapsLibrary('places');

    autocomplete = new google.maps.places.Autocomplete(addressInput.value, {
        fields: ['geometry', 'formatted_address', 'name'],
        componentRestrictions: { country: 'lt' },
    });

    autocomplete.addListener('place_changed', () => {
        const place = autocomplete?.getPlace();
        const lat = place?.geometry?.location?.lat();
        const lng = place?.geometry?.location?.lng();

        if (lat === undefined || lng === undefined) {
            errorMessage.value =
                'Pasirinktam adresui nepavyko gauti koordinačių. Pabandykite kitą.';
            status.value = 'error';

            return;
        }

        const next = { lat, lng };
        origin.value = next;
        originLabel.value =
            place?.formatted_address ?? place?.name ?? originLabel.value;
        emit('origin', next);
        void fetchPlan(next, mode.value);
    });
}

const fetchPlan = async (
    coord: LatLng,
    travelMode: TravelMode,
): Promise<void> => {
    if (props.chains.length === 0) {
        return;
    }

    status.value = 'planning';
    errorMessage.value = null;

    planForm.origin = coord;
    planForm.chains = [...props.chains];
    planForm.mode = travelMode;
    planForm.items = props.items ?? [];

    try {
        const data = await planForm.submit(RoutePlanController());
        plan.value = data;
        emit('plan', data);
        status.value = 'ready';
    } catch (e) {
        const detail = (
            e as { response?: { data?: { detail?: string; message?: string } } }
        )?.response?.data;
        errorMessage.value =
            detail?.detail ??
            detail?.message ??
            (e instanceof Error
                ? e.message
                : 'Nepavyko apskaičiuoti maršruto.');
        status.value = 'error';
        plan.value = null;
        emit('plan', null);
    }
};

const useMyLocation = (): void => {
    if (typeof navigator === 'undefined' || !navigator.geolocation) {
        errorMessage.value = 'Naršyklė nepalaiko vietos nustatymo.';
        status.value = 'error';

        return;
    }

    status.value = 'locating';
    errorMessage.value = null;

    navigator.geolocation.getCurrentPosition(
        (pos) => {
            const next = {
                lat: pos.coords.latitude,
                lng: pos.coords.longitude,
            };
            origin.value = next;
            originLabel.value = 'Mano vieta';
            emit('origin', next);
            void fetchPlan(next, mode.value);
        },
        (err) => {
            status.value = 'error';
            errorMessage.value =
                err.code === err.PERMISSION_DENIED
                    ? 'Atsisakėte dalintis vieta. Įveskite adresą rankiniu būdu.'
                    : err.message || 'Nepavyko nustatyti vietos.';
        },
        { enableHighAccuracy: true, timeout: 8000, maximumAge: 60_000 },
    );
};

const switchMode = async (next: TravelMode): Promise<void> => {
    if (mode.value === next) {
        return;
    }

    mode.value = next;

    if (origin.value) {
        await fetchPlan(origin.value, next);
    }
};

watch(
    () => props.chains.join('|'),
    () => {
        if (plan.value) {
            plan.value = null;
            emit('plan', null);
        }

        if (origin.value) {
            void fetchPlan(origin.value, mode.value);
        }
    },
);

onMounted(() => {
    void ensureAutocomplete();
});

onBeforeUnmount(() => {
    if (autocomplete) {
        google.maps.event.clearInstanceListeners(autocomplete);
        autocomplete = null;
    }
});

const googleMapsLink = computed<string | null>(() => {
    if (!plan.value || !origin.value) {
        return null;
    }

    const o = origin.value;
    const params = new URLSearchParams({
        api: '1',
        origin: `${o.lat},${o.lng}`,
        destination: `${o.lat},${o.lng}`,
        waypoints: plan.value.stops.map((s) => `${s.lat},${s.lng}`).join('|'),
        travelmode: plan.value.mode,
    });

    return `https://www.google.com/maps/dir/?${params.toString()}`;
});
</script>

<template>
    <section
        class="flex flex-col gap-4 rounded-[16px] border border-kainu-border bg-white p-5"
    >
        <header class="flex items-start gap-2">
            <RouteIcon
                :size="16"
                :stroke-width="2"
                class="mt-0.5 text-brand-700"
            />
            <div class="flex-1">
                <h3
                    class="text-[15px] font-semibold tracking-tight text-stone-900"
                >
                    Apsipirkimo maršrutas
                </h3>
                <p class="mt-0.5 text-[12px] text-stone-500">
                    Įveskite pradžios adresą — sukursime trumpiausią kelią per
                    krepšelio parduotuves ir atgal.
                </p>
            </div>
        </header>

        <div
            v-if="!hasMapsKey"
            class="flex items-start gap-2 rounded-[10px] bg-amber-50 px-3 py-2.5 text-[12px] text-amber-900"
        >
            <AlertTriangle
                :size="14"
                :stroke-width="2"
                class="mt-0.5 shrink-0"
            />
            <span>
                Google Maps raktas nenustatytas — pridėkite
                <code
                    class="rounded bg-amber-100 px-1 py-0.5 font-mono text-[11px]"
                    >GOOGLE_MAPS_API_KEY</code
                >
                į
                <code
                    class="rounded bg-amber-100 px-1 py-0.5 font-mono text-[11px]"
                    >.env</code
                >.
            </span>
        </div>

        <div
            v-else-if="chains.length === 0"
            class="rounded-[10px] border border-dashed border-kainu-border bg-kainu-surface px-4 py-3 text-center text-[12px] text-stone-500"
        >
            Pridėkite prekių į krepšelį, kad galėtumėte planuoti maršrutą.
        </div>

        <template v-else>
            <div class="flex flex-col gap-2">
                <label
                    class="text-[11px] font-semibold tracking-[0.06em] text-stone-500 uppercase"
                    for="route-origin-input"
                >
                    Pradžios adresas
                </label>
                <div class="flex flex-col gap-2 sm:flex-row">
                    <div
                        class="flex min-w-0 flex-1 items-center gap-2 rounded-[10px] border border-kainu-border bg-white px-3 py-2 focus-within:border-stone-300"
                    >
                        <MapPin
                            :size="14"
                            :stroke-width="2"
                            class="shrink-0 text-stone-400"
                        />
                        <input
                            id="route-origin-input"
                            ref="addressInput"
                            type="text"
                            autocomplete="off"
                            placeholder="pvz. Gedimino pr. 1, Vilnius"
                            class="min-w-0 flex-1 bg-transparent text-[13px] text-stone-900 placeholder:text-stone-400 focus:outline-none"
                        />
                    </div>
                    <button
                        type="button"
                        :disabled="
                            status === 'locating' || status === 'planning'
                        "
                        class="inline-flex h-9 shrink-0 items-center justify-center gap-1.5 rounded-[10px] border border-kainu-border bg-kainu-surface px-3 text-[12px] font-medium text-stone-700 transition-colors hover:bg-white disabled:opacity-50"
                        @click="useMyLocation"
                    >
                        <Locate :size="13" :stroke-width="2" />
                        Naudoti mano vietą
                    </button>
                </div>
                <div
                    v-if="originLabel"
                    class="flex items-center gap-1 text-[11px] text-stone-500"
                >
                    <Navigation :size="11" :stroke-width="2" />
                    {{ originLabel }}
                </div>
            </div>

            <div
                class="flex items-center gap-1 rounded-[10px] bg-kainu-surface p-1"
            >
                <button
                    type="button"
                    class="inline-flex h-8 flex-1 items-center justify-center gap-1.5 rounded-[8px] text-[12px] font-medium transition-colors"
                    :class="
                        mode === 'driving'
                            ? 'bg-white text-stone-900 shadow-sm'
                            : 'text-stone-500 hover:text-stone-700'
                    "
                    @click="switchMode('driving')"
                >
                    <Car :size="14" :stroke-width="2" />
                    Automobiliu
                </button>
                <button
                    type="button"
                    class="inline-flex h-8 flex-1 items-center justify-center gap-1.5 rounded-[8px] text-[12px] font-medium transition-colors"
                    :class="
                        mode === 'walking'
                            ? 'bg-white text-stone-900 shadow-sm'
                            : 'text-stone-500 hover:text-stone-700'
                    "
                    @click="switchMode('walking')"
                >
                    <Footprints :size="14" :stroke-width="2" />
                    Pėsčiomis
                </button>
            </div>

            <div
                v-if="status === 'locating' || status === 'planning'"
                class="flex items-center gap-2 rounded-[10px] bg-kainu-surface px-3 py-2.5 text-[12px] text-stone-600"
            >
                <Loader2 :size="14" class="animate-spin" :stroke-width="2" />
                <span v-if="status === 'locating'">Nustatoma vieta…</span>
                <span v-else>Skaičiuojamas maršrutas…</span>
            </div>

            <div
                v-else-if="status === 'idle' && !plan"
                class="flex items-start gap-2 rounded-[10px] border border-dashed border-kainu-border bg-kainu-surface px-3 py-3 text-[12px] text-stone-500"
            >
                <Navigation
                    :size="14"
                    :stroke-width="2"
                    class="mt-0.5 shrink-0"
                />
                <span>
                    Pradžios adresą galite pasirinkti iš pasiūlymų sąrašo arba
                    spauskite <strong>„Naudoti mano vietą“</strong>.
                </span>
            </div>

            <div
                v-if="status === 'error' && errorMessage"
                class="flex items-start gap-2 rounded-[10px] bg-red-50 px-3 py-2.5 text-[12px] text-red-700"
            >
                <TriangleAlert
                    :size="14"
                    :stroke-width="2"
                    class="mt-0.5 shrink-0"
                />
                <span>{{ errorMessage }}</span>
            </div>

            <div
                v-if="plan"
                class="flex flex-col gap-3 rounded-[12px] border border-kainu-border bg-kainu-surface p-4"
            >
                <div
                    class="flex items-center justify-between text-[12px] text-stone-500"
                >
                    <div class="flex items-center gap-1.5">
                        <Clock :size="13" :stroke-width="2" />
                        <span>Bendras laikas</span>
                    </div>
                    <span
                        class="text-[15px] font-bold text-stone-900 tabular-nums"
                    >
                        {{ formatDuration(plan.totalDurationSec) }}
                    </span>
                </div>
                <div
                    class="flex items-center justify-between text-[12px] text-stone-500"
                >
                    <div class="flex items-center gap-1.5">
                        <Footprints
                            v-if="plan.mode === 'walking'"
                            :size="13"
                            :stroke-width="2"
                        />
                        <Car v-else :size="13" :stroke-width="2" />
                        <span>Atstumas</span>
                    </div>
                    <span class="font-semibold text-stone-700 tabular-nums">
                        {{ formatDistance(plan.totalDistanceMeters) }}
                    </span>
                </div>
            </div>

            <ol v-if="plan" class="flex flex-col gap-2">
                <li
                    v-for="(stop, idx) in plan.stops"
                    :key="`${stop.chain}-${stop.lat}-${stop.lng}`"
                    class="flex items-start gap-3 rounded-[12px] border border-kainu-border bg-white px-3 py-3"
                >
                    <span
                        class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-[12px] font-bold text-white"
                        :style="{
                            backgroundColor: resolveStore(stop.chain).color,
                        }"
                    >
                        {{ idx + 1 }}
                    </span>
                    <div class="min-w-0 flex-1">
                        <div
                            class="truncate text-[13px] font-semibold text-stone-900"
                        >
                            {{ stop.name }}
                        </div>
                        <div class="truncate text-[11px] text-stone-500">
                            {{ stop.address
                            }}<template v-if="stop.city"
                                >, {{ stop.city }}</template
                            >
                        </div>
                    </div>
                    <div class="shrink-0 text-right text-[11px] text-stone-500">
                        <div class="font-semibold text-brand-700">
                            {{ formatDuration(stop.durationFromPreviousSec) }}
                        </div>
                        <div class="tabular-nums">
                            {{
                                formatDistance(stop.distanceFromPreviousMeters)
                            }}
                        </div>
                    </div>
                </li>
                <li
                    class="flex items-center gap-3 rounded-[12px] border border-dashed border-kainu-border bg-kainu-surface px-3 py-3 text-[12px] text-stone-500"
                >
                    <MapPinned
                        :size="16"
                        :stroke-width="2"
                        class="shrink-0 text-stone-400"
                    />
                    <span class="flex-1">Grįžimas į pradžios vietą</span>
                    <div class="shrink-0 text-right">
                        <div class="font-semibold text-stone-700">
                            {{ formatDuration(plan.returnLeg.durationSec) }}
                        </div>
                        <div class="tabular-nums">
                            {{ formatDistance(plan.returnLeg.distanceMeters) }}
                        </div>
                    </div>
                </li>
            </ol>

            <a
                v-if="googleMapsLink"
                :href="googleMapsLink"
                target="_blank"
                rel="noopener noreferrer"
                class="inline-flex h-10 w-full items-center justify-center gap-2 rounded-[10px] border border-kainu-border bg-white text-[13px] font-medium text-stone-700 transition-colors hover:bg-kainu-surface"
            >
                <ExternalLink :size="14" :stroke-width="2" />
                Atidaryti Google Maps
            </a>
        </template>
    </section>
</template>
