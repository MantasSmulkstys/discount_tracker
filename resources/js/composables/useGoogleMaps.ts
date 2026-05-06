import { importLibrary, setOptions } from '@googlemaps/js-api-loader';

let configured = false;
let configuredKey: string | null = null;

export type MapsBootstrap = {
    apiKey: string;
    mapId?: string | null;
};

export type MapsLibrary = 'maps' | 'places' | 'geometry' | 'marker';

export function configureMaps({ apiKey, mapId }: MapsBootstrap): void {
    if (configured) {
        if (configuredKey !== apiKey) {
            console.warn(
                '[useGoogleMaps] setOptions has already been called with a different key; the first one is in effect.',
            );
        }

        return;
    }

    setOptions({
        key: apiKey,
        v: 'weekly',
        libraries: ['places', 'geometry'],
        ...(mapId ? { mapIds: [mapId] } : {}),
    });

    configured = true;
    configuredKey = apiKey;
}

export async function loadMapsLibrary<T = unknown>(
    name: MapsLibrary,
): Promise<T> {
    return importLibrary(name) as Promise<T>;
}
