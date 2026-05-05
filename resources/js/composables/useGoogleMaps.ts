import { importLibrary, setOptions } from '@googlemaps/js-api-loader';

/**
 * Shared loader for the Google Maps JavaScript API.
 *
 * `setOptions()` may only be called once per page load, before any
 * `importLibrary()` call. Multiple components (the cart map, the route
 * planner's address autocomplete, …) need the same SDK, so we centralize
 * the bootstrap here and gate it with a module-scoped flag.
 */

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

export async function loadMapsLibrary<T = unknown>(name: MapsLibrary): Promise<T> {
    return importLibrary(name) as Promise<T>;
}
