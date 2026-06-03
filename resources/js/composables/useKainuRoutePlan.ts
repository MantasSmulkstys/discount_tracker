import { ref, watch } from 'vue';

export type TravelMode = 'walking' | 'driving';
export type LatLng = { lat: number; lng: number };

type PersistedRoutePlan = {
    visible: boolean;
    origin: LatLng | null;
    originLabel: string;
    mode: TravelMode;
};

const STORAGE_KEY = 'ks_route_plan_v1';

const fallback: PersistedRoutePlan = {
    visible: false,
    origin: null,
    originLabel: '',
    mode: 'driving',
};

function loadInitial(): PersistedRoutePlan {
    if (typeof window === 'undefined') {
        return { ...fallback };
    }

    try {
        const raw = window.localStorage.getItem(STORAGE_KEY);

        if (!raw) {
            return { ...fallback };
        }

        const parsed = JSON.parse(raw);

        return { ...fallback, ...parsed } as PersistedRoutePlan;
    } catch {
        return { ...fallback };
    }
}

const initial = loadInitial();

// Module-level singletons so the planner state survives navigation between the
// cart and other pages (mirrors how useKainuCart persists the basket).
const routePlanVisible = ref<boolean>(initial.visible);
const routeOrigin = ref<LatLng | null>(initial.origin);
const routeOriginLabel = ref<string>(initial.originLabel);
const routeMode = ref<TravelMode>(initial.mode);

if (typeof window !== 'undefined') {
    watch(
        [routePlanVisible, routeOrigin, routeOriginLabel, routeMode],
        () => {
            try {
                window.localStorage.setItem(
                    STORAGE_KEY,
                    JSON.stringify({
                        visible: routePlanVisible.value,
                        origin: routeOrigin.value,
                        originLabel: routeOriginLabel.value,
                        mode: routeMode.value,
                    } satisfies PersistedRoutePlan),
                );
            } catch {}
        },
        { deep: true },
    );
}

export function useKainuRoutePlan() {
    return {
        routePlanVisible,
        routeOrigin,
        routeOriginLabel,
        routeMode,
    };
}
