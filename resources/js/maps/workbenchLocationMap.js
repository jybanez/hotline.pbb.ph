import { applyTileConfig, ensureMapLibre, fetchMapJson, loadHotlineMapConfig } from './dashboardMap.js';

const DEFAULT_CONFIG_URL = '/hotline.json';
const DEFAULT_LOCATION_ZOOM = 16;
const DEFAULT_INTERACTIVE_LOCATION_ZOOM = 17;
const TRACK_SOURCE_ID = 'hotline-workbench-caller-track';
const TRACK_LINE_LAYER_ID = 'hotline-workbench-caller-track-line';
const TRACK_POINT_LAYER_ID = 'hotline-workbench-caller-track-points';

function parseLocation(location) {
    const latitude = Number(location?.latitude ?? location?.caller_latitude ?? location?.lat ?? NaN);
    const longitude = Number(location?.longitude ?? location?.caller_longitude ?? location?.lng ?? NaN);

    if (!Number.isFinite(latitude) || !Number.isFinite(longitude)) {
        return null;
    }

    if (latitude < -90 || latitude > 90 || longitude < -180 || longitude > 180) {
        return null;
    }

    return {
        latitude,
        longitude,
        center: [longitude, latitude],
    };
}

function disableMapInteractions(map) {
    map.dragPan?.disable();
    map.scrollZoom?.disable();
    map.boxZoom?.disable();
    map.dragRotate?.disable();
    map.keyboard?.disable();
    map.doubleClickZoom?.disable();
    map.touchZoomRotate?.disable();
}

function createMarkerElement() {
    const marker = document.createElement('span');
    marker.className = 'operator-workbench-location-marker';
    marker.setAttribute('aria-hidden', 'true');
    return marker;
}

function trackFeatureCollection(items = []) {
    const points = (Array.isArray(items) ? items : [])
        .map((item) => parseLocation(item))
        .filter(Boolean);
    const features = [];

    if (points.length >= 2) {
        features.push({
            type: 'Feature',
            geometry: {
                type: 'LineString',
                coordinates: points.map((point) => point.center),
            },
            properties: { kind: 'track' },
        });
    }

    points.forEach((point, index) => {
        features.push({
            type: 'Feature',
            geometry: {
                type: 'Point',
                coordinates: point.center,
            },
            properties: {
                kind: index === 0 ? 'start' : 'sample',
                index,
            },
        });
    });

    return {
        type: 'FeatureCollection',
        features,
    };
}

function ensureTrackLayers(map) {
    if (!map || map.getSource(TRACK_SOURCE_ID)) {
        return;
    }

    map.addSource(TRACK_SOURCE_ID, {
        type: 'geojson',
        data: trackFeatureCollection([]),
    });

    map.addLayer({
        id: TRACK_LINE_LAYER_ID,
        type: 'line',
        source: TRACK_SOURCE_ID,
        filter: ['==', ['get', 'kind'], 'track'],
        paint: {
            'line-color': '#6dd8ff',
            'line-opacity': 0.82,
            'line-width': ['interpolate', ['linear'], ['zoom'], 10, 2, 16, 4],
            'line-blur': 0.5,
        },
    });

    map.addLayer({
        id: TRACK_POINT_LAYER_ID,
        type: 'circle',
        source: TRACK_SOURCE_ID,
        filter: ['!=', ['get', 'kind'], 'track'],
        paint: {
            'circle-radius': ['case', ['==', ['get', 'kind'], 'start'], 4.5, 3],
            'circle-color': ['case', ['==', ['get', 'kind'], 'start'], '#55c987', '#6dd8ff'],
            'circle-opacity': 0.9,
            'circle-stroke-color': '#06121d',
            'circle-stroke-width': 1,
        },
    });
}

function isMapLibreSupported(maplibregl) {
    return typeof maplibregl?.supported !== 'function'
        || maplibregl.supported({ failIfMajorPerformanceCaveat: false });
}

export function createWorkbenchLocationMap({
    container,
    configUrl = DEFAULT_CONFIG_URL,
    interactive = false,
    controls = false,
    zoom: zoomOverride = null,
} = {}) {
    let map = null;
    let marker = null;
    let destroyed = false;
    let pendingLocation = null;
    let pendingTrack = [];

    const applyTrack = () => {
        if (!map?.getSource?.(TRACK_SOURCE_ID)) {
            return;
        }

        map.getSource(TRACK_SOURCE_ID).setData(trackFeatureCollection(pendingTrack));
    };

    const applyLocation = (location) => {
        const parsed = parseLocation(location);

        if (!parsed) {
            return;
        }

        pendingLocation = parsed;

        if (!map) {
            return;
        }

        if (!marker) {
            marker = new window.maplibregl.Marker({
                anchor: 'center',
                element: createMarkerElement(),
            }).setLngLat(parsed.center).addTo(map);
        } else {
            marker.setLngLat(parsed.center);
        }

        map.jumpTo({
            center: parsed.center,
            zoom: map.getZoom() || DEFAULT_LOCATION_ZOOM,
        });
    };

    return {
        container,

        async init(initialLocation = null) {
            if (!container || destroyed) {
                return false;
            }

            if (initialLocation) {
                pendingLocation = parseLocation(initialLocation);
            }

            const config = await loadHotlineMapConfig(configUrl);

            if (destroyed || !config?.enabled) {
                return false;
            }

            const maplibregl = await ensureMapLibre(config);

            if (destroyed || !maplibregl || !isMapLibreSupported(maplibregl)) {
                return false;
            }

            const style = applyTileConfig(await fetchMapJson(config.styleUrl), config);
            const center = pendingLocation?.center ?? config.center;
            const configuredZoom = Number(config.workbenchLocation?.zoom ?? DEFAULT_LOCATION_ZOOM);
            const defaultZoom = interactive ? DEFAULT_INTERACTIVE_LOCATION_ZOOM : DEFAULT_LOCATION_ZOOM;
            const zoom = Number(zoomOverride ?? configuredZoom ?? defaultZoom);

            map = new maplibregl.Map({
                attributionControl: false,
                center,
                container,
                interactive: Boolean(interactive),
                maxZoom: config.maxZoom,
                minZoom: config.minZoom,
                style,
                zoom: Number.isFinite(zoom) ? zoom : defaultZoom,
            });
            if (!interactive) {
                disableMapInteractions(map);
            }
            if (controls && typeof maplibregl.NavigationControl === 'function') {
                map.addControl(new maplibregl.NavigationControl({ visualizePitch: true }), 'top-right');
            }
            applyLocation(pendingLocation);
            requestAnimationFrame(() => map?.resize?.());

            map.once('load', () => {
                ensureTrackLayers(map);
                applyTrack();
                applyLocation(pendingLocation);
                map.resize();
            });

            return true;
        },

        resize() {
            map?.resize?.();
        },

        setLocation(location) {
            applyLocation(location);
        },

        setTrack(items = []) {
            pendingTrack = (Array.isArray(items) ? items : [])
                .map((item) => parseLocation(item))
                .filter(Boolean);
            applyTrack();
        },

        appendTrackPoint(location) {
            const parsed = parseLocation(location);

            if (!parsed) {
                return;
            }

            const previous = pendingTrack[pendingTrack.length - 1] ?? null;
            if (previous && previous.latitude === parsed.latitude && previous.longitude === parsed.longitude) {
                return;
            }

            pendingTrack.push(parsed);
            if (pendingTrack.length > 1000) {
                pendingTrack = pendingTrack.slice(-1000);
            }
            applyTrack();
        },

        destroy() {
            destroyed = true;
            marker?.remove?.();
            marker = null;
            map?.remove?.();
            map = null;
        },
    };
}
