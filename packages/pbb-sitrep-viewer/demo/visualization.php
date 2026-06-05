<?php

require __DIR__.'/../src/Html.php';
require __DIR__.'/../src/SitrepPayload.php';
require __DIR__.'/../src/SitrepViewOptions.php';
require __DIR__.'/../src/SitrepSchemaReference.php';
require __DIR__.'/../src/SitrepDocumentRenderer.php';
require __DIR__.'/../src/SitrepVisualizationDataBuilder.php';
require __DIR__.'/../src/SitrepViewer.php';

use Pbb\Sitreps\Viewer\SitrepViewer;

$viewer = new SitrepViewer();
$payloadJson = null;
$visuals = null;
$error = null;
$fileName = 'demo/input/sitrep.json';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $upload = $_FILES['sitrep'] ?? null;

    if (! is_array($upload) || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        $error = 'Choose a SITREP JSON file to visualize.';
    } elseif (($upload['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        $error = 'The uploaded file could not be read.';
    } elseif (($upload['size'] ?? 0) > 8 * 1024 * 1024) {
        $error = 'The uploaded file is larger than 8 MB.';
    } else {
        $fileName = basename((string) ($upload['name'] ?? 'sitrep.json'));

        if (strtolower(pathinfo($fileName, PATHINFO_EXTENSION)) !== 'json') {
            $error = 'Upload a .json SITREP file.';
        } else {
            $payloadJson = file_get_contents((string) $upload['tmp_name']) ?: null;
        }
    }
} else {
    $sample = __DIR__.'/input/sitrep.json';
    $payloadJson = is_file($sample) ? file_get_contents($sample) : null;
}

if ($error === null && is_string($payloadJson) && trim($payloadJson) !== '') {
    try {
        $visuals = $viewer->visualizationData($payloadJson);
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
        $visuals = null;
    }
}

$documentRoot = realpath((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''));
$helperBase = null;
if ($documentRoot !== false && is_file($documentRoot.'/public/vendor/helpers.pbb.ph/js/ui/ui.loader.js')) {
    $helperBase = '/public/vendor/helpers.pbb.ph';
} elseif ($documentRoot !== false && is_file($documentRoot.'/vendor/helpers.pbb.ph/js/ui/ui.loader.js')) {
    $helperBase = '/vendor/helpers.pbb.ph';
}

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>PBB SITREP Visualization Demo</title>
    <?php if ($helperBase !== null): ?>
        <link rel="stylesheet" href="<?= e($helperBase) ?>/dist/helpers.ui.bundle.min.css?v=93d562a">
    <?php endif; ?>
    <style>
        body {
            margin: 0;
            background: #07111c;
            color: #e5edf8;
            font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        }

        .demo-shell {
            min-height: 100vh;
        }

        .demo-toolbar {
            position: sticky;
            top: 0;
            z-index: 10;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            padding: 14px 18px;
            border-bottom: 1px solid rgba(148, 163, 184, 0.25);
            background: rgba(7, 17, 28, 0.96);
        }

        .demo-toolbar h1 {
            margin: 0;
            font-size: 16px;
            font-weight: 700;
        }

        .demo-upload {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }

        .demo-upload input[type="file"] {
            width: 280px;
            max-width: 100%;
            color: #cbd5e1;
        }

        .demo-upload button {
            border: 1px solid rgba(96, 165, 250, 0.55);
            border-radius: 6px;
            background: #1d4ed8;
            color: white;
            font: inherit;
            font-weight: 700;
            padding: 8px 12px;
            cursor: pointer;
        }

        .demo-content {
            width: min(1240px, calc(100vw - 32px));
            margin: 18px auto 36px;
            display: grid;
            gap: 16px;
        }

        .demo-message,
        .demo-empty {
            padding: 14px;
            border-radius: 8px;
            background: rgba(15, 23, 42, 0.72);
            border: 1px solid rgba(148, 163, 184, 0.28);
            color: #cbd5e1;
        }

        .demo-message.is-error {
            border-color: rgba(248, 113, 113, 0.45);
            background: rgba(127, 29, 29, 0.28);
            color: #fecaca;
        }

        .demo-file {
            margin: 0;
            color: #93c5fd;
            font-size: 13px;
        }

        .demo-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(360px, 1fr));
            gap: 14px;
            align-items: start;
        }

        .demo-panel {
            min-width: 0;
            border: 1px solid rgba(59, 130, 246, 0.28);
            border-radius: 8px;
            background: #0c1d2a;
            padding: 14px;
            display: grid;
            gap: 12px;
        }

        .demo-panel h2 {
            margin: 0;
            font-size: 16px;
        }

        .demo-panel h3 {
            margin: 0;
            font-size: 13px;
            color: #bfdbfe;
            letter-spacing: 0.04em;
            text-transform: uppercase;
        }

        .demo-map {
            position: relative;
            min-height: 380px;
            border: 1px solid rgba(148, 163, 184, 0.3);
            border-radius: 8px;
            overflow: hidden;
            background:
                linear-gradient(90deg, rgba(96, 165, 250, 0.12) 1px, transparent 1px),
                linear-gradient(rgba(96, 165, 250, 0.1) 1px, transparent 1px),
                radial-gradient(circle at 35% 38%, rgba(251, 191, 36, 0.2), transparent 16%),
                radial-gradient(circle at 68% 58%, rgba(34, 197, 94, 0.14), transparent 18%),
                #07131f;
            background-size: 56px 56px, 56px 56px, auto, auto, auto;
        }

        .demo-marker-position {
            position: absolute;
            transform: translate(-50%, -50%);
        }

        .demo-map-empty {
            position: absolute;
            inset: 0;
            display: grid;
            place-items: center;
            color: #cbd5e1;
            padding: 16px;
            text-align: center;
        }

        .demo-debug {
            white-space: pre-wrap;
            overflow: auto;
            max-height: 260px;
            margin: 0;
            border: 1px solid rgba(148, 163, 184, 0.28);
            border-radius: 8px;
            background: #08111d;
            color: #cbd5e1;
            padding: 10px;
            font-size: 12px;
        }
    </style>
</head>
<body>
    <div class="demo-shell">
        <header class="demo-toolbar">
            <h1>PBB SITREP Visualization Demo</h1>
            <form class="demo-upload" method="post" enctype="multipart/form-data">
                <input type="file" name="sitrep" accept="application/json,.json">
                <button type="submit">Render Visuals</button>
            </form>
        </header>

        <main class="demo-content">
            <?php if ($error !== null): ?>
                <p class="demo-message is-error"><?= e($error) ?></p>
            <?php elseif ($helperBase === null): ?>
                <p class="demo-message is-error">Helper assets were not found. Start the demo from the Hotline repo root, for example: php -S 127.0.0.1:8097 -t C:\wamp64\www\pbb\hotline</p>
            <?php elseif ($visuals === null): ?>
                <div class="demo-empty">Upload a generated SITREP JSON payload to render Helper-backed visualizations.</div>
            <?php else: ?>
                <p class="demo-file">Visualizing <?= e($fileName) ?></p>
                <section class="demo-grid">
                    <article class="demo-panel">
                        <h2>Summary</h2>
                        <div id="summaryStats"></div>
                        <div id="summaryGaps"></div>
                        <div id="summaryAccomplishments"></div>
                    </article>
                    <article class="demo-panel">
                        <h2>Situation</h2>
                        <div id="locationsChart"></div>
                        <div id="incidentTypesChart"></div>
                    </article>
                    <article class="demo-panel">
                        <h2>Population</h2>
                        <div id="populationStats"></div>
                        <div id="populationGroupsChart"></div>
                        <div id="memberBreakdownChart"></div>
                    </article>
                    <article class="demo-panel">
                        <h2>Actions</h2>
                        <div id="assignmentStatusChart"></div>
                    </article>
                    <article class="demo-panel">
                        <h2>Needs</h2>
                        <div id="categoryDemandChart"></div>
                        <div id="resourceNeedsChart"></div>
                    </article>
                    <article class="demo-panel">
                        <h2>Gaps</h2>
                        <div id="gapTypes"></div>
                    </article>
                    <article class="demo-panel">
                        <h2>Map Evidence</h2>
                        <div class="demo-map" id="incidentMap"></div>
                        <div id="mapLegend"></div>
                    </article>
                    <article class="demo-panel">
                        <h2>Dataset Payload</h2>
                        <pre class="demo-debug"><?= e(json_encode($visuals, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></pre>
                    </article>
                </section>
            <?php endif; ?>
        </main>
    </div>

    <?php if ($visuals !== null && $helperBase !== null): ?>
        <script type="application/json" id="sitrep-visuals-json"><?= json_encode($visuals, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
        <script type="module">
            import { uiLoader } from "<?= e($helperBase) ?>/js/ui/ui.loader.js?v=93d562a";

            const visuals = JSON.parse(document.getElementById("sitrep-visuals-json").textContent);
            await uiLoader.loadMany(["ui.stat.cards", "ui.charts", "ui.map.legend", "ui.map.markers"]);
            const createStatCards = await uiLoader.get("ui.stat.cards");
            const charts = await uiLoader.get("ui.charts");
            const createMapLegend = await uiLoader.get("ui.map.legend");
            const mapMarkers = await uiLoader.get("ui.map.markers");

            function camelizeKey(key) {
                return String(key).replace(/_([a-z])/g, (_, letter) => letter.toUpperCase());
            }

            function camelize(value) {
                if (Array.isArray(value)) {
                    return value.map(camelize);
                }
                if (value && typeof value === "object") {
                    return Object.fromEntries(Object.entries(value).map(([key, item]) => [camelizeKey(key), camelize(item)]));
                }
                return value;
            }

            function byId(id) {
                return document.getElementById(id);
            }

            function renderStatCards(id, dataset, options = {}) {
                const host = byId(id);
                if (!host || !dataset) return;
                createStatCards(host, camelize(dataset.items || []), {
                    columns: "auto",
                    size: "sm",
                    chrome: true,
                    ...options,
                });
            }

            function renderChart(id, dataset, options = {}) {
                const host = byId(id);
                if (!host || !dataset) return;
                const next = camelize(dataset);
                charts.createChart(host, {
                    title: next.title,
                    type: next.type || "horizontal-bar",
                    valueLabel: next.valueLabel || "Value",
                    data: next.data || [],
                    sort: "value-desc",
                    ...options,
                });
            }

            function renderLegend(id, dataset) {
                const host = byId(id);
                if (!host || !dataset) return;
                createMapLegend(host, camelize({
                    title: dataset.title,
                    compact: true,
                    sections: dataset.sections || [],
                }));
            }

            function renderMarkers(id, dataset) {
                const host = byId(id);
                if (!host || !dataset) return;
                const items = camelize(dataset.items || []);
                if (!items.length) {
                    host.innerHTML = '<div class="demo-map-empty">No incident coordinates are available in this SITREP.</div>';
                    return;
                }

                const lats = items.map((item) => Number(item.lat)).filter(Number.isFinite);
                const lngs = items.map((item) => Number(item.lng)).filter(Number.isFinite);
                const minLat = Math.min(...lats);
                const maxLat = Math.max(...lats);
                const minLng = Math.min(...lngs);
                const maxLng = Math.max(...lngs);
                const latSpan = Math.max(maxLat - minLat, 0.00001);
                const lngSpan = Math.max(maxLng - minLng, 0.00001);

                items.slice(0, 120).forEach((item) => {
                    const marker = mapMarkers.createMapMarker({
                        ...item,
                        label: item.label || `Incident ${item.id}`,
                        size: "sm",
                    });
                    const wrapper = document.createElement("div");
                    wrapper.className = "demo-marker-position";
                    wrapper.style.left = `${8 + ((Number(item.lng) - minLng) / lngSpan) * 84}%`;
                    wrapper.style.top = `${92 - ((Number(item.lat) - minLat) / latSpan) * 84}%`;
                    wrapper.appendChild(marker);
                    host.appendChild(wrapper);
                });
            }

            const sections = visuals.sections || {};
            renderStatCards("summaryStats", sections.summary?.stat_cards);
            renderStatCards("summaryGaps", sections.summary?.gap_cards, { columns: "1" });
            renderStatCards("summaryAccomplishments", sections.summary?.accomplishment_cards, { columns: "1" });
            renderChart("locationsChart", sections.situation?.locations);
            renderChart("incidentTypesChart", sections.situation?.incident_types);
            renderStatCards("populationStats", sections.population?.stat_cards);
            renderChart("populationGroupsChart", sections.population?.population_groups);
            renderChart("memberBreakdownChart", sections.population?.member_breakdown, { type: "bar" });
            renderChart("assignmentStatusChart", sections.actions?.assignment_status, { type: "stacked-bar", sort: "input" });
            renderChart("categoryDemandChart", sections.needs?.category_demand);
            renderChart("resourceNeedsChart", sections.needs?.resource_needs);
            renderStatCards("gapTypes", sections.gaps?.gap_types, { columns: "1" });
            renderMarkers("incidentMap", sections.map?.incident_markers);
            renderLegend("mapLegend", sections.map?.legend);
        </script>
    <?php endif; ?>
</body>
</html>
