import { ref, watch } from 'vue';

const STORAGE_KEY = 'ks_favs_v1';

function loadInitial(): Set<number> {
    if (typeof window === 'undefined') {
        return new Set();
    }

    try {
        const raw = window.localStorage.getItem(STORAGE_KEY);

        if (!raw) {
            return new Set();
        }

        const parsed = JSON.parse(raw);

        if (Array.isArray(parsed)) {
            return new Set(parsed as number[]);
        }

        return new Set();
    } catch {
        return new Set();
    }
}

const favoritesSet = ref<Set<number>>(loadInitial());

if (typeof window !== 'undefined') {
    watch(
        favoritesSet,
        (next) => {
            try {
                window.localStorage.setItem(
                    STORAGE_KEY,
                    JSON.stringify(Array.from(next)),
                );
            } catch {}
        },
        { deep: true },
    );
}

function toggle(id: number) {
    const next = new Set(favoritesSet.value);

    if (next.has(id)) {
        next.delete(id);
    } else {
        next.add(id);
    }

    favoritesSet.value = next;
}

function isFavorited(id: number): boolean {
    return favoritesSet.value.has(id);
}

export function useKainuFavorites() {
    return {
        favorites: favoritesSet,
        toggle,
        isFavorited,
    };
}
