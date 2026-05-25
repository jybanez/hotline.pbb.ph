const DEFAULT_CONFIG_URL = '/hotline.json';

const DEFAULT_MAP_CONFIG = {
    enabled: true,
    center: [123.8854, 10.3157],
    zoom: 12,
    minZoom: 8,
    maxZoom: 18,
    styleUrl: '/maps/operator-vector-style.json',
    mapServerUrl: 'https://mapserver.pbb.ph',
    assets: {
        script: '/vendor/maplibre/maplibre-gl.js',
        css: '/vendor/maplibre/maplibre-gl.css',
    },
    tiles: {
        vector: 'https://mapserver.pbb.ph/tiles/vector/{z}/{x}/{y}.pbf',
        terrain: 'https://mapserver.pbb.ph/tiles/terrain/{z}/{x}/{y}.png',
        glyphs: 'https://mapserver.pbb.ph/tiles/glyphs/{fontstack}/{range}.pbf',
        poi: 'https://mapserver.pbb.ph/tiles/poi/{z}/{x}/{y}.pbf',
    },
    poi: {
        enabled: true,
        sourceLayers: ['poi', 'pois', 'point', 'points', 'amenity'],
        excludedClasses: [],
    },
    boundary: {
        enabled: false,
        url: '',
    },
};

const SOURCE_ID = 'hotline-dashboard-incidents';
const WORKBENCH_PULSE_LAYER_ID = 'hotline-dashboard-incidents-workbench-pulse';
const CIRCLE_LAYER_ID = 'hotline-dashboard-incidents-circle';
const LABEL_LAYER_ID = 'hotline-dashboard-incidents-label';
const POI_SOURCE_ID = 'hotline-dashboard-poi';
const POI_CIRCLE_LAYER_ID = 'hotline-dashboard-poi-circle';
const POI_LABEL_LAYER_ID = 'hotline-dashboard-poi-label';
const BOUNDARY_SOURCE_ID = 'hotline-dashboard-hub-boundary';
const BOUNDARY_FILL_LAYER_ID = 'hotline-dashboard-hub-boundary-fill';
const BOUNDARY_LINE_LAYER_ID = 'hotline-dashboard-hub-boundary-line';

let configPromise = null;
let maplibrePromise = null;

function mergeMapConfig(config) {
    return {
        ...DEFAULT_MAP_CONFIG,
        ...(config ?? {}),
        assets: {
            ...DEFAULT_MAP_CONFIG.assets,
            ...(config?.assets ?? {}),
        },
        tiles: {
            ...DEFAULT_MAP_CONFIG.tiles,
            ...(config?.tiles ?? {}),
        },
        poi: {
            ...DEFAULT_MAP_CONFIG.poi,
            ...(config?.poi ?? {}),
        },
        boundary: {
            ...DEFAULT_MAP_CONFIG.boundary,
            ...(config?.boundary ?? {}),
        },
    };
}

export async function fetchMapJson(url) {
    const response = await fetch(url, {
        headers: { Accept: 'application/json' },
        credentials: 'same-origin',
    });

    if (!response.ok) {
        throw new Error(`Failed to load ${url} (${response.status})`);
    }

    return response.json();
}

export async function loadHotlineMapConfig(configUrl = DEFAULT_CONFIG_URL) {
    if (!configPromise) {
        configPromise = fetchMapJson(configUrl)
            .then((payload) => mergeMapConfig(payload?.map))
            .catch(() => mergeMapConfig(null));
    }

    return configPromise;
}

function ensureStylesheet(href) {
    if (!href || document.querySelector(`link[data-dashboard-map-css="${CSS.escape(href)}"]`)) {
        return;
    }

    const link = document.createElement('link');
    link.rel = 'stylesheet';
    link.href = href;
    link.dataset.dashboardMapCss = href;
    document.head.appendChild(link);
}

function ensureScript(src) {
    if (!src) {
        return Promise.resolve();
    }

    const existing = document.querySelector(`script[data-dashboard-map-js="${CSS.escape(src)}"]`);
    if (existing) {
        return existing.dataset.loaded === 'true'
            ? Promise.resolve()
            : new Promise((resolve, reject) => {
                existing.addEventListener('load', resolve, { once: true });
                existing.addEventListener('error', reject, { once: true });
            });
    }

    return new Promise((resolve, reject) => {
        const script = document.createElement('script');
        script.src = src;
        script.async = true;
        script.dataset.dashboardMapJs = src;
        script.addEventListener('load', () => {
            script.dataset.loaded = 'true';
            resolve();
        }, { once: true });
        script.addEventListener('error', reject, { once: true });
        document.head.appendChild(script);
    });
}

export async function ensureMapLibre(config) {
    if (window.maplibregl) {
        return window.maplibregl;
    }

    if (!maplibrePromise) {
        ensureStylesheet(config.assets?.css);
        maplibrePromise = ensureScript(config.assets?.script).then(() => window.maplibregl);
    }

    return maplibrePromise;
}

export function applyTileConfig(style, config) {
    const nextStyle = JSON.parse(JSON.stringify(style));
    const sources = nextStyle.sources ?? {};

    if (sources.osm?.tiles?.length && config.tiles?.vector) {
        sources.osm.tiles = [config.tiles.vector];
    }

    if (sources.terrain?.tiles?.length && config.tiles?.terrain) {
        sources.terrain.tiles = [config.tiles.terrain];
    }

    if (sources['terrain-hillshade']?.tiles?.length && config.tiles?.terrain) {
        sources['terrain-hillshade'].tiles = [config.tiles.terrain];
    }

    if (nextStyle.glyphs && config.tiles?.glyphs) {
        nextStyle.glyphs = config.tiles.glyphs;
    }

    return nextStyle;
}

function parseIncidentCoordinates(item) {
    const lat = Number(item?.latitude ?? item?.lat ?? item?.location?.lat ?? item?.location?.latitude);
    const lng = Number(item?.longitude ?? item?.lng ?? item?.location?.lng ?? item?.location?.longitude);

    if (!Number.isFinite(lat) || !Number.isFinite(lng)) {
        return null;
    }

    return [lng, lat];
}

function statusTone(status) {
    const value = String(status ?? '').toLowerCase();

    if (value.includes('deferred')) {
        return 'deferred';
    }

    if (value.includes('resolved')) {
        return 'resolved';
    }

    if (value.includes('discarded') || value.includes('cancelled')) {
        return 'discarded';
    }

    return 'active';
}

function featureCollection(items, selectedIncidentId = null) {
    const features = [];

    (Array.isArray(items) ? items : []).forEach((item) => {
        const coordinates = parseIncidentCoordinates(item);
        const incidentId = Number(item?.id ?? 0);

        if (!coordinates || !incidentId) {
            return;
        }

        features.push({
            type: 'Feature',
            id: incidentId,
            geometry: {
                type: 'Point',
                coordinates,
            },
            properties: {
                id: incidentId,
                label: `#${String(item?.display_id ?? String(incidentId).padStart(6, '0'))}`,
                status: String(item?.status ?? 'Active'),
                tone: statusTone(item?.status),
                selected: selectedIncidentId !== null && Number(selectedIncidentId) === incidentId,
                workbench_active: item?.workbench_active === true,
            },
        });
    });

    return {
        type: 'FeatureCollection',
        features,
    };
}

function incidentCircleColorExpression() {
    return [
        'match',
        ['get', 'tone'],
        'deferred',
        '#d8a332',
        'resolved',
        '#55c987',
        'discarded',
        '#c45b70',
        '#5fd1e0',
    ];
}

function addIncidentLayers(map) {
    if (!map.getSource(SOURCE_ID)) {
        map.addSource(SOURCE_ID, {
            type: 'geojson',
            data: featureCollection([]),
        });
    }

    if (!map.getLayer(CIRCLE_LAYER_ID)) {
        map.addLayer({
            id: CIRCLE_LAYER_ID,
            type: 'circle',
            source: SOURCE_ID,
            paint: {
                'circle-radius': ['case', ['boolean', ['get', 'selected'], false], 8, 5],
                'circle-color': incidentCircleColorExpression(),
                'circle-opacity': 0.92,
                'circle-stroke-color': ['case', ['boolean', ['get', 'selected'], false], '#ffe08a', incidentCircleColorExpression()],
                'circle-stroke-width': ['case', ['boolean', ['get', 'selected'], false], 2.5, 1.2],
            },
        });
    }

    if (!map.getLayer(WORKBENCH_PULSE_LAYER_ID)) {
        map.addLayer({
            id: WORKBENCH_PULSE_LAYER_ID,
            type: 'circle',
            source: SOURCE_ID,
            filter: ['==', ['get', 'workbench_active'], true],
            paint: {
                'circle-radius': 12,
                'circle-color': incidentCircleColorExpression(),
                'circle-blur': 0.32,
                'circle-opacity': 0.58,
                'circle-stroke-color': incidentCircleColorExpression(),
                'circle-stroke-opacity': 0,
                'circle-stroke-width': 0,
            },
        }, CIRCLE_LAYER_ID);
    }

    if (!map.getLayer(LABEL_LAYER_ID)) {
        map.addLayer({
            id: LABEL_LAYER_ID,
            type: 'symbol',
            source: SOURCE_ID,
            layout: {
                'text-field': ['coalesce', ['get', 'label'], ''],
                'text-font': ['Open Sans Bold', 'Arial Unicode MS Bold'],
                'text-size': 10,
                'text-offset': [0, -1.45],
                'text-anchor': 'bottom',
                'text-allow-overlap': true,
                'text-ignore-placement': true,
            },
            paint: {
                'text-color': incidentCircleColorExpression(),
                'text-halo-color': 'rgba(6, 18, 27, 0.95)',
                'text-halo-width': 1,
            },
        });
    }
}

function boundaryFeatureCount(boundary) {
    return Array.isArray(boundary?.features) ? boundary.features.length : 0;
}

async function loadBoundaryGeoJson(config) {
    if (config?.boundary?.enabled === false) {
        return null;
    }

    const boundaryUrl = String(config?.boundary?.url ?? '').trim();

    if (!boundaryUrl) {
        return null;
    }

    const geojson = await fetchMapJson(boundaryUrl);

    return geojson?.type === 'FeatureCollection' && boundaryFeatureCount(geojson) > 0 ? geojson : null;
}

function addBoundaryLayers(map, boundaryGeoJson) {
    if (!boundaryGeoJson || map.getSource(BOUNDARY_SOURCE_ID)) {
        return;
    }

    map.addSource(BOUNDARY_SOURCE_ID, {
        type: 'geojson',
        data: boundaryGeoJson,
    });

    if (!map.getLayer(BOUNDARY_FILL_LAYER_ID)) {
        map.addLayer({
            id: BOUNDARY_FILL_LAYER_ID,
            type: 'fill',
            source: BOUNDARY_SOURCE_ID,
            paint: {
                'fill-color': '#4fc3ff',
                'fill-opacity': 0.08,
            },
        });
    }

    if (!map.getLayer(BOUNDARY_LINE_LAYER_ID)) {
        map.addLayer({
            id: BOUNDARY_LINE_LAYER_ID,
            type: 'line',
            source: BOUNDARY_SOURCE_ID,
            paint: {
                'line-color': '#8fe6ff',
                'line-opacity': 0.92,
                'line-width': ['interpolate', ['linear'], ['zoom'], 8, 1.2, 14, 2.8],
            },
        });
    }
}

function setLayerVisibility(map, layerIds, visible) {
    (Array.isArray(layerIds) ? layerIds : []).forEach((layerId) => {
        if (map?.getLayer?.(layerId)) {
            map.setLayoutProperty(layerId, 'visibility', visible ? 'visible' : 'none');
        }
    });
}

function buildPoiClassExclusionFilter(excludedClasses) {
    const normalized = [...new Set((Array.isArray(excludedClasses) ? excludedClasses : [])
        .map((item) => String(item ?? '').trim().toLowerCase())
        .filter(Boolean))];

    if (!normalized.length) {
        return null;
    }

    return [
        'all',
        ['!', ['in', ['downcase', ['coalesce', ['get', 'class'], '']], ['literal', normalized]]],
        ['!', ['in', ['downcase', ['coalesce', ['get', 'type'], '']], ['literal', normalized]]],
        ['!', ['in', ['downcase', ['coalesce', ['get', 'subclass'], '']], ['literal', normalized]]],
    ];
}

function addPoiLayers(map, config) {
    if (!config.poi?.enabled || map.getLayer(POI_CIRCLE_LAYER_ID) || map.getLayer(POI_LABEL_LAYER_ID)) {
        return;
    }

    const configuredSource = String(config.poi?.source ?? '').trim();
    const sourceId = configuredSource || (config.tiles?.poi ? POI_SOURCE_ID : 'osm');

    if (sourceId === POI_SOURCE_ID && !map.getSource(POI_SOURCE_ID)) {
        if (!config.tiles?.poi) {
            return;
        }

        map.addSource(POI_SOURCE_ID, {
            type: 'vector',
            tiles: [config.tiles.poi],
            minzoom: 0,
            maxzoom: 14,
        });
    }

    if (!map.getSource(sourceId)) {
        return;
    }

    const filter = buildPoiClassExclusionFilter(config.poi.excludedClasses);
    const sourceLayerCandidates = Array.isArray(config.poi?.sourceLayers) && config.poi.sourceLayers.length
        ? config.poi.sourceLayers
        : ['poi', 'pois', 'point', 'points', 'amenity'];

    sourceLayerCandidates.some((sourceLayer) => {
        try {
            const circleLayer = {
                id: POI_CIRCLE_LAYER_ID,
                type: 'circle',
                source: sourceId,
                'source-layer': sourceLayer,
                minzoom: 11,
                paint: {
                    'circle-radius': ['interpolate', ['linear'], ['zoom'], 11, 2.2, 15, 4],
                    'circle-color': '#f4b43d',
                    'circle-opacity': 0.65,
                    'circle-stroke-color': '#0b141b',
                    'circle-stroke-width': 0.7,
                },
            };
            const labelLayer = {
                id: POI_LABEL_LAYER_ID,
                type: 'symbol',
                source: sourceId,
                'source-layer': sourceLayer,
                minzoom: 13,
                layout: {
                    'text-field': ['coalesce', ['get', 'name_en'], ['get', 'name'], ['get', 'class'], ['get', 'type'], ''],
                    'text-font': ['Open Sans Regular', 'Arial Unicode MS Regular'],
                    'text-size': ['interpolate', ['linear'], ['zoom'], 13, 9, 16, 11],
                    'text-offset': [0, 0.9],
                    'text-anchor': 'top',
                },
                paint: {
                    'text-color': '#e8d8b0',
                    'text-halo-color': 'rgba(6, 18, 27, 0.95)',
                    'text-halo-width': 0.8,
                },
            };

            if (filter) {
                circleLayer.filter = filter;
                labelLayer.filter = filter;
            }

            map.addLayer(circleLayer, CIRCLE_LAYER_ID);
            map.addLayer(labelLayer, CIRCLE_LAYER_ID);
            return true;
        } catch (_) {
            if (map.getLayer(POI_CIRCLE_LAYER_ID)) {
                map.removeLayer(POI_CIRCLE_LAYER_ID);
            }
            if (map.getLayer(POI_LABEL_LAYER_ID)) {
                map.removeLayer(POI_LABEL_LAYER_ID);
            }
            return false;
        }
    });
}

export function createDashboardMap(options = {}) {
    const container = options.container ?? null;
    const onIncidentClick = typeof options.onIncidentClick === 'function' ? options.onIncidentClick : null;
    let map = null;
    let config = null;
    let maplibregl = null;
    let initialized = false;
    let loaded = false;
    let unsupported = false;
    let pendingItems = [];
    let selectedIncidentId = null;
    let terrainSpec = null;
    let boundaryGeoJson = null;
    let workbenchPulseFrame = null;

    function stopWorkbenchPulse() {
        if (workbenchPulseFrame !== null) {
            window.cancelAnimationFrame(workbenchPulseFrame);
            workbenchPulseFrame = null;
        }
    }

    function startWorkbenchPulse() {
        stopWorkbenchPulse();

        const animate = (timestamp) => {
            if (!map || !loaded || !map.getLayer(WORKBENCH_PULSE_LAYER_ID)) {
                workbenchPulseFrame = null;
                return;
            }

            const phase = (timestamp % 1450) / 1450;
            const eased = 1 - ((1 - phase) ** 3);
            const radius = 10 + (eased * 42);
            const opacity = Math.max(0, 0.62 * ((1 - phase) ** 1.85));

            map.setPaintProperty(WORKBENCH_PULSE_LAYER_ID, 'circle-radius', radius);
            map.setPaintProperty(WORKBENCH_PULSE_LAYER_ID, 'circle-opacity', opacity);
            workbenchPulseFrame = window.requestAnimationFrame(animate);
        };

        workbenchPulseFrame = window.requestAnimationFrame(animate);
    }

    function setSourceData() {
        if (!map || !loaded || !map.getSource(SOURCE_ID)) {
            return;
        }

        map.getSource(SOURCE_ID).setData(featureCollection(pendingItems, selectedIncidentId));
    }

    function incidentBounds() {
        if (!maplibregl?.LngLatBounds) {
            return null;
        }

        const bounds = new maplibregl.LngLatBounds();
        let hasBounds = false;

        pendingItems.forEach((item) => {
            const coordinates = parseIncidentCoordinates(item);
            if (!coordinates) {
                return;
            }

            bounds.extend(coordinates);
            hasBounds = true;
        });

        return hasBounds ? bounds : null;
    }

    async function init() {
        if (initialized || unsupported || !container) {
            return;
        }

        initialized = true;
        config = await loadHotlineMapConfig(options.configUrl);

        if (config.enabled === false) {
            unsupported = true;
            return;
        }

        ensureStylesheet(config.assets?.css);
        maplibregl = await ensureMapLibre(config);

        if (!maplibregl) {
            unsupported = true;
            return;
        }

        if (typeof maplibregl.supported === 'function' && !maplibregl.supported({ failIfMajorPerformanceCaveat: false })) {
            unsupported = true;
            return;
        }

        const [stylePayload, nextBoundaryGeoJson] = await Promise.all([
            fetchMapJson(config.styleUrl),
            loadBoundaryGeoJson(config).catch(() => null),
        ]);
        const style = applyTileConfig(stylePayload, config);
        boundaryGeoJson = nextBoundaryGeoJson;
        terrainSpec = style?.terrain ?? null;
        container.innerHTML = '';
        map = new maplibregl.Map({
            container,
            style,
            center: config.center,
            zoom: config.zoom,
            minZoom: config.minZoom,
            maxZoom: config.maxZoom,
            attributionControl: false,
        });

        map.on('load', () => {
            loaded = true;
            addBoundaryLayers(map, boundaryGeoJson);
            addIncidentLayers(map);
            addPoiLayers(map, config);
            startWorkbenchPulse();
            setSourceData();
            [CIRCLE_LAYER_ID, LABEL_LAYER_ID].forEach((layerId) => {
                map.on('click', layerId, (event) => {
                    const feature = event.features?.[0];
                    const incidentId = Number(feature?.properties?.id ?? 0);
                    if (incidentId) {
                        onIncidentClick?.(incidentId);
                    }
                });
            });
            map.on('mouseenter', CIRCLE_LAYER_ID, () => {
                map.getCanvas().style.cursor = 'pointer';
            });
            map.on('mouseleave', CIRCLE_LAYER_ID, () => {
                map.getCanvas().style.cursor = '';
            });
        });
    }

    return {
        async init() {
            await init();
        },
        setIncidents(items = []) {
            pendingItems = Array.isArray(items) ? items : [];
            setSourceData();
        },
        focusIncident(incidentId) {
            selectedIncidentId = Number(incidentId ?? 0) || null;
            setSourceData();
        },
        resize() {
            map?.resize?.();
        },
        fitIncidents(options = {}) {
            const bounds = incidentBounds();
            if (!map || !bounds) {
                return false;
            }

            map.fitBounds(bounds, {
                padding: 96,
                maxZoom: 14,
                duration: Number(options.duration ?? 700),
                essential: true,
            });

            return true;
        },
        getMap() {
            return map;
        },
        setLayerGroupVisibility(groupId, visible) {
            if (groupId === 'incidents') {
                setLayerVisibility(map, [WORKBENCH_PULSE_LAYER_ID, CIRCLE_LAYER_ID, LABEL_LAYER_ID], visible);
            } else if (groupId === 'boundary') {
                setLayerVisibility(map, [BOUNDARY_FILL_LAYER_ID, BOUNDARY_LINE_LAYER_ID], visible);
            } else if (groupId === 'terrain') {
                if (map?.setTerrain && terrainSpec) {
                    map.setTerrain(visible ? terrainSpec : null);
                }
                setLayerVisibility(map, ['terrain-hillshade'], visible);
            } else if (groupId === 'poi') {
                setLayerVisibility(map, [POI_CIRCLE_LAYER_ID, POI_LABEL_LAYER_ID], visible);
            }
        },
        hasTerrainLayer() {
            return Boolean(terrainSpec || map?.getLayer?.('terrain-hillshade'));
        },
        hasBoundaryLayer() {
            return boundaryFeatureCount(boundaryGeoJson) > 0 || Boolean(map?.getSource?.(BOUNDARY_SOURCE_ID));
        },
        destroy() {
            stopWorkbenchPulse();
            map?.remove?.();
            map = null;
            loaded = false;
            initialized = false;
        },
        isAvailable() {
            return !!map && !unsupported;
        },
        hasRenderableItems(items = pendingItems) {
            return (Array.isArray(items) ? items : []).some((item) => parseIncidentCoordinates(item));
        },
    };
}
