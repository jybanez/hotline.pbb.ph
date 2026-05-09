let maplibreglRef = null;
let pendingMapSyncTimer = null;
let flyTimer = null;

export function createGeodataMapRuntime(context) {
    const {
        state,
        markerVisible,
        itemCode,
        emptyGeojsonSource,
        emptyFeatureCollection,
        boundsFromGeojson,
        listForMapLevel,
        loadLevel,
        persistGeodataViewState,
        showMessage,
    } = context;

    return {
        initMap,
        applyPoiFilter,
        updateMapSourceForLevel,
        scheduleMapSync,
        refreshLevelMapSource,
        toggleLayerVisibility,
        toggleTerrain,
        syncMapToggleButtons,
        isLayerVisible,
        setLayerVisibility,
        isTerrainEnabled,
        setTerrainEnabled,
        fitMapForCurrentLevel,
        fitForSearchResults,
        fitAllRegions,
        debounceFlyTo,
    };

    async function getMapLibre() {
        if (!maplibreglRef) {
            const mod = await import("maplibre-gl");
            maplibreglRef = mod.default;
        }
        return maplibreglRef;
    }

    async function loadMapStyleConfig() {
        if (state.mapStyleConfig) return state.mapStyleConfig;
        const response = await fetch("/map-settings.json", { credentials: "same-origin" });
        if (!response.ok) {
            throw new Error(`Failed to load map style config: ${response.status}`);
        }
        state.mapStyleConfig = await response.json();
        return state.mapStyleConfig;
    }

    function cloneStyleConfig(style) {
        if (typeof structuredClone === "function") {
            return structuredClone(style);
        }
        return JSON.parse(JSON.stringify(style));
    }

    async function initMap() {
        const maplibregl = await getMapLibre();
        const styleConfig = cloneStyleConfig(await loadMapStyleConfig());
        state.mapHoverPopup?.remove?.();
        state.mapHoverPopup = null;
        state.mapHoverBindings = new Set();
        state.mapClickBindings = new Set();
        state.map?.remove?.();
        state.map = new maplibregl.Map({
            container: "map",
            attributionControl: false,
            style: styleConfig,
            center: [122.9, 12.2],
            zoom: 5.2,
        });
        state.map.on("moveend", () => {
            persistGeodataViewState();
        });
        state.mapHoverPopup = new maplibregl.Popup({
            closeButton: false,
            closeOnClick: false,
            closeOnMove: false,
            offset: 12,
            className: "map-hover-popup",
        });

        await new Promise((resolve) => {
            state.map.on("load", () => {
                ensureGeodataLayers();
                syncMapForCurrentView();
                state.map.once("idle", () => {
                    syncMapForCurrentView();
                });
                resolve();
            });
        });
    }

    function ensureGeodataLayers() {
        const map = state.map;
        if (!map?.isStyleLoaded()) return;

        addCivicMarkerImages(map);
        ensureGeojsonSource("regions-src");
        ensureGeojsonSource("provinces-src");
        ensureGeojsonSource("cities-src");
        ensureGeojsonSource("barangays-src");
        ensureGeojsonSource("region-boundary-src");

        ensureLayer("regions-layer", {
            id: "regions-layer",
            type: "circle",
            source: "regions-src",
            paint: { "circle-radius": 6, "circle-color": "#0ea5e9" },
        });
        ensureLayer("provinces-layer", {
            id: "provinces-layer",
            type: "symbol",
            source: "provinces-src",
            layout: {
                "icon-image": "province-capitol-marker",
                "icon-size": 1,
                "icon-allow-overlap": true,
                "icon-ignore-placement": true,
            },
        });
        ensureLayer("cities-layer", {
            id: "cities-layer",
            type: "symbol",
            source: "cities-src",
            layout: {
                "icon-image": "city-hall-marker",
                "icon-size": 1,
                "icon-allow-overlap": true,
                "icon-ignore-placement": true,
            },
        });
        ensureLayer("barangays-layer", {
            id: "barangays-layer",
            type: "symbol",
            source: "barangays-src",
            layout: {
                "icon-image": "barangay-hall-marker",
                "icon-size": 1,
                "icon-allow-overlap": true,
                "icon-ignore-placement": true,
            },
        });
        ensureLayer("region-boundary-fill", {
            id: "region-boundary-fill",
            type: "fill",
            source: "region-boundary-src",
            paint: { "fill-color": "#0ea5e9", "fill-opacity": 0.2 },
        });
        ensureLayer("region-boundary-line", {
            id: "region-boundary-line",
            type: "line",
            source: "region-boundary-src",
            paint: { "line-color": "#0369a1", "line-width": 2 },
        });
        bindMarkerHoverEvents();
        bindMarkerClickEvents();
        applyPoiFilter();

        setCurrentListLayerVisibility(state.geodata.level);
    }

    function ensureGeojsonSource(sourceId) {
        if (state.map.getSource(sourceId)) return;
        state.map.addSource(sourceId, emptyGeojsonSource());
    }

    function ensureLayer(layerId, spec) {
        if (state.map.getLayer(layerId)) return;
        state.map.addLayer(spec);
    }

    function bindMarkerHoverEvents() {
        ["regions-layer", "provinces-layer", "cities-layer", "barangays-layer", "poi-layer"].forEach((layerId) => {
            if (state.mapHoverBindings.has(layerId) || !state.map.getLayer(layerId)) return;

            state.map.on("mouseenter", layerId, (event) => {
                state.map.getCanvas().style.cursor = "pointer";
                showMarkerHoverPopup(event);
            });
            state.map.on("mousemove", layerId, (event) => {
                showMarkerHoverPopup(event);
            });
            state.map.on("mouseleave", layerId, () => {
                state.map.getCanvas().style.cursor = "";
                state.mapHoverPopup?.remove?.();
            });

            state.mapHoverBindings.add(layerId);
        });
    }

    function bindMarkerClickEvents() {
        const clickLayers = {
            "provinces-layer": async (event) => {
                const item = mapItemByFeature("provinces", event?.features?.[0]);
                if (!item) return;
                state.geodata.scope.province = item;
                state.geodata.scope.city = null;
                await loadLevel("cities", item.provCode, true);
            },
            "cities-layer": async (event) => {
                const item = mapItemByFeature("cities", event?.features?.[0]);
                if (!item) return;
                state.geodata.scope.city = item;
                await loadLevel("barangays", item.citymunCode, true);
            },
            "barangays-layer": async (event) => {
                const feature = event?.features?.[0];
                const coordinates = feature?.geometry?.coordinates;
                if (!Array.isArray(coordinates) || coordinates.length < 2) return;
                debounceFlyTo(Number(coordinates[0]), Number(coordinates[1]), 15);
            },
        };

        Object.entries(clickLayers).forEach(([layerId, handler]) => {
            if (state.mapClickBindings.has(layerId) || !state.map.getLayer(layerId)) return;

            state.map.on("click", layerId, async (event) => {
                await handler(event);
            });

            state.mapClickBindings.add(layerId);
        });
    }

    function applyPoiFilter() {
        if (!state.map?.getLayer?.("poi-layer")) return;

        const excluded = state.settings.excludedPoiClasses;
        if (!excluded.length) {
            state.map.setFilter("poi-layer", null);
            return;
        }

        state.map.setFilter("poi-layer", [
            "!",
            [
                "in",
                ["downcase", ["coalesce", ["to-string", ["get", "class"]], ""]],
                ["literal", excluded],
            ],
        ]);
    }

    function showMarkerHoverPopup(event) {
        const feature = event?.features?.[0];
        const name = resolveFeatureHoverLabel(feature);
        if (!name || !state.mapHoverPopup) return;

        state.mapHoverPopup
            .setLngLat(event.lngLat)
            .setText(name)
            .addTo(state.map);
    }

    function resolveFeatureHoverLabel(feature) {
        const props = feature?.properties ?? {};
        const candidates = [
            props.name,
            props.name_en,
            props.name_int,
            props.ref,
            props.class,
        ];

        const match = candidates.find((value) => String(value ?? "").trim());
        return String(match ?? "").trim();
    }

    function addCivicMarkerImages(map) {
        const markers = [
            {
                id: "province-capitol-marker",
                colors: { badge: "#8b5cf6", accent: "#6d28d9", building: "#ffffff" },
            },
            {
                id: "city-hall-marker",
                colors: { badge: "#10b981", accent: "#047857", building: "#ffffff" },
            },
            {
                id: "barangay-hall-marker",
                colors: { badge: "#f59e0b", accent: "#c2410c", building: "#ffffff" },
            },
        ];

        markers.forEach((marker) => {
            if (map.hasImage(marker.id)) return;
            map.addImage(marker.id, createCivicMarkerImage(marker.colors), {
                pixelRatio: 2,
            });
        });
    }

    function createCivicMarkerImage(colors) {
        const size = 64;
        const canvas = document.createElement("canvas");
        canvas.width = size;
        canvas.height = size;
        const ctx = canvas.getContext("2d");

        ctx.clearRect(0, 0, size, size);
        ctx.beginPath();
        ctx.arc(32, 28, 16, 0, Math.PI * 2);
        ctx.fillStyle = colors.badge;
        ctx.fill();

        ctx.beginPath();
        ctx.moveTo(32, 54);
        ctx.lineTo(22, 38);
        ctx.lineTo(42, 38);
        ctx.closePath();
        ctx.fillStyle = colors.accent;
        ctx.fill();

        ctx.fillStyle = colors.building;
        ctx.fillRect(23, 26, 18, 12);
        ctx.fillRect(29, 22, 6, 4);
        ctx.fillRect(24, 20, 16, 2);
        ctx.fillRect(26, 28, 2, 8);
        ctx.fillRect(31, 28, 2, 8);
        ctx.fillRect(36, 28, 2, 8);
        ctx.fillRect(30, 31, 4, 7);

        return ctx.getImageData(0, 0, size, size);
    }

    function updateMapSourceForLevel(level, list) {
        const sourceId = sourceByLevel(level);
        if (!sourceId) return;
        const source = state.map?.getSource?.(sourceId);
        if (!source) return;

        const safeList = Array.isArray(list) ? list : [];
        const visibilityFiltered = safeList.filter((item) => markerVisible(level, itemCode(item, level)));
        const coordinatesFiltered = visibilityFiltered.filter((item) => Number(item.lat) !== 0 && Number(item.lon) !== 0);
        const features = coordinatesFiltered.map((item) => ({
            type: "Feature",
            properties: {
                id: item.id,
                code: itemCode(item, level),
                name: item.name,
            },
            geometry: {
                type: "Point",
                coordinates: [Number(item.lon), Number(item.lat)],
            },
        }));

        source.setData({
            type: "FeatureCollection",
            features,
        });
    }

    function mapItemByFeature(level, feature) {
        const code = String(feature?.properties?.code ?? "").trim();
        if (!code) return null;

        const list = listForMapLevel(level);
        return list.find((item) => String(itemCode(item, level)) === code) ?? null;
    }

    function sourceByLevel(level) {
        if (level === "regions") return "regions-src";
        if (level === "provinces") return "provinces-src";
        if (level === "cities") return "cities-src";
        if (level === "barangays") return "barangays-src";
        return null;
    }

    function syncMapForCurrentView() {
        if (!state.map?.isStyleLoaded()) return;
        ensureGeodataLayers();

        const orderedLevels = ["regions", "provinces", "cities", "barangays"];
        const activeDepth = orderedLevels.indexOf(state.geodata.level);
        if (activeDepth === -1) return;

        orderedLevels.slice(0, activeDepth).forEach((level) => {
            updateMapSourceForLevel(level, listForMapLevel(level));
        });

        updateMapSourceForLevel(state.geodata.level, Array.isArray(state.geodata.filtered) ? state.geodata.filtered : []);

        orderedLevels.slice(activeDepth + 1).forEach((level) => {
            const sourceId = sourceByLevel(level);
            state.map.getSource(sourceId)?.setData(emptyFeatureCollection());
        });

        setCurrentListLayerVisibility(state.geodata.level);
    }

    function scheduleMapSync() {
        syncMapForCurrentView();

        if (typeof window !== "undefined" && typeof window.requestAnimationFrame === "function") {
            window.requestAnimationFrame(() => {
                syncMapForCurrentView();
            });
        }

        if (pendingMapSyncTimer) {
            window.clearTimeout(pendingMapSyncTimer);
        }
        pendingMapSyncTimer = window.setTimeout(() => {
            pendingMapSyncTimer = null;
            syncMapForCurrentView();
        }, 120);
    }

    function refreshLevelMapSource(level, list) {
        const run = () => {
            const sourceId = sourceByLevel(level);
            const hasSource = !!state.map?.getSource?.(sourceId);
            if (!hasSource) return;
            updateMapSourceForLevel(level, Array.isArray(list) ? list : []);
        };

        run();

        if (typeof window !== "undefined" && typeof window.requestAnimationFrame === "function") {
            window.requestAnimationFrame(run);
        }

        window.setTimeout(run, 120);
    }

    function setCurrentListLayerVisibility(level) {
        if (!state.map?.isStyleLoaded()) return;

        const orderedLevels = ["regions", "provinces", "cities", "barangays"];
        const levelLayerIds = {
            regions: ["regions-layer"],
            provinces: ["provinces-layer"],
            cities: ["cities-layer"],
            barangays: ["barangays-layer"],
        };
        const activeDepth = orderedLevels.indexOf(level);

        Object.entries(levelLayerIds).forEach(([listLevel, layerIds]) => {
            const layerDepth = orderedLevels.indexOf(listLevel);
            const visible = layerDepth !== -1 && layerDepth <= activeDepth ? "visible" : "none";
            layerIds.forEach((layerId) => {
                if (!state.map.getLayer(layerId)) return;
                state.map.setLayoutProperty(layerId, "visibility", visible);
            });
        });
    }

    function toggleLayerVisibility(layerId) {
        const vis = state.map.getLayoutProperty(layerId, "visibility");
        setLayerVisibility(layerId, vis === "none");
    }

    function toggleTerrain() {
        if (!state.map) return;
        setTerrainEnabled(!isTerrainEnabled());
        syncMapToggleButtons();
    }

    function syncMapToggleButtons() {
        const terrainButton = state.ui.terrainToggle;
        const poiButton = state.ui.poiToggle;
        if (!state.map || (!terrainButton && !poiButton)) return;

        if (terrainButton) {
            terrainButton.setPressed(isTerrainEnabled());
        }

        if (poiButton && state.map.getLayer("poi-layer")) {
            poiButton.setPressed(isLayerVisible("poi-layer"));
        }
    }

    function isLayerVisible(layerId) {
        if (!state.map?.getLayer(layerId)) return false;
        return state.map.getLayoutProperty(layerId, "visibility") !== "none";
    }

    function setLayerVisibility(layerId, visible) {
        if (!state.map?.getLayer(layerId)) return;
        state.map.setLayoutProperty(layerId, "visibility", visible ? "visible" : "none");
        persistGeodataViewState();
    }

    function isTerrainEnabled() {
        if (!state.map) return false;
        const hillshadeVisible = state.map.getLayer("terrain-hillshade")
            ? state.map.getLayoutProperty("terrain-hillshade", "visibility") !== "none"
            : false;
        return !!state.map.getTerrain() || hillshadeVisible;
    }

    function setTerrainEnabled(enabled) {
        if (!state.map) return;
        const terrainLayerId = "terrain-hillshade";
        const terrainSpec = state.mapStyleConfig?.terrain ?? null;
        state.map.setTerrain(enabled ? terrainSpec : null);
        if (state.map.getLayer(terrainLayerId)) {
            state.map.setLayoutProperty(terrainLayerId, "visibility", enabled ? "visible" : "none");
        }
        persistGeodataViewState();
    }

    async function ensureRegionBoundariesLoaded() {
        if (state.boundaries) return;
        const response = await fetch("/geodata/ph.json");
        state.boundaries = await response.json();
    }

    async function fitMapForCurrentLevel(level) {
        if (level === "regions") {
            clearRegionBoundaryOverlay();
            await ensureRegionBoundariesLoaded();
            fitAllRegions();
            return;
        }

        if (level === "provinces" && state.geodata.scope.region?.regCode) {
            await showRegionBoundary(state.geodata.scope.region.regCode);
            return;
        }

        clearRegionBoundaryOverlay();
        fitForSearchResults();
    }

    function clearRegionBoundaryOverlay() {
        state.map?.getSource?.("region-boundary-src")?.setData(emptyFeatureCollection());
    }

    async function showRegionBoundary(regCode) {
        await ensureRegionBoundariesLoaded();
        const pcode = `PH${String(regCode).padStart(2, "0")}`;
        const matched = state.boundaries.features.filter((f) => f.properties.ADM1_PCODE === pcode);
        if (!matched.length) {
            showMessage("Region boundary not found.");
            return;
        }
        const fc = { type: "FeatureCollection", features: matched };
        state.map.getSource("region-boundary-src")?.setData(fc);
        const bounds = boundsFromGeojson(fc);
        if (bounds) state.map.fitBounds(bounds, { padding: 40, duration: 600 });
    }

    function fitAllRegions() {
        if (!state.boundaries) return;
        const bounds = boundsFromGeojson(state.boundaries);
        if (bounds) state.map.fitBounds(bounds, { padding: 30, duration: 600 });
    }

    function fitForSearchResults() {
        const withCoords = state.geodata.filtered
            .filter((x) => Number(x.lat) !== 0 && Number(x.lon) !== 0)
            .map((x) => [Number(x.lon), Number(x.lat)]);

        if (!withCoords.length) return;
        if (withCoords.length === 1) {
            debounceFlyTo(withCoords[0][0], withCoords[0][1], 12);
            return;
        }
        const b = withCoords.reduce((acc, [lon, lat]) => {
            if (!acc) return [lon, lat, lon, lat];
            acc[0] = Math.min(acc[0], lon);
            acc[1] = Math.min(acc[1], lat);
            acc[2] = Math.max(acc[2], lon);
            acc[3] = Math.max(acc[3], lat);
            return acc;
        }, null);
        state.map.fitBounds(
            [
                [b[0], b[1]],
                [b[2], b[3]],
            ],
            { padding: 60, duration: 500 }
        );
    }

    function debounceFlyTo(lon, lat, zoom = 11) {
        if (flyTimer) clearTimeout(flyTimer);
        flyTimer = setTimeout(() => {
            state.map.flyTo({ center: [lon, lat], zoom, essential: true, duration: 650 });
        }, 140);
    }
}
