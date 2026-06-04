<?php

require __DIR__.'/../src/Html.php';
require __DIR__.'/../src/SitrepPayload.php';
require __DIR__.'/../src/SitrepViewOptions.php';
require __DIR__.'/../src/SitrepDocumentRenderer.php';
require __DIR__.'/../src/SitrepViewer.php';

use Pbb\Sitreps\Viewer\SitrepViewer;

$viewer = new SitrepViewer();
$rendered = null;
$error = null;
$fileName = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $upload = $_FILES['sitrep'] ?? null;

    if (! is_array($upload) || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        $error = 'Choose a SITREP JSON file to render.';
    } elseif (($upload['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        $error = 'The uploaded file could not be read.';
    } elseif (($upload['size'] ?? 0) > 5 * 1024 * 1024) {
        $error = 'The uploaded file is larger than 5 MB.';
    } else {
        $fileName = basename((string) ($upload['name'] ?? 'sitrep.json'));
        $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

        if ($extension !== 'json') {
            $error = 'Upload a .json SITREP file.';
        } else {
            $json = file_get_contents((string) $upload['tmp_name']);

            if ($json === false || trim($json) === '') {
                $error = 'The uploaded JSON file is empty.';
            } else {
                try {
                    $rendered = $viewer->render($json, [
                        'full_document' => false,
                        'preview' => true,
                    ]);
                } catch (Throwable $exception) {
                    $error = $exception->getMessage();
                }
            }
        }
    }
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
    <title>PBB SITREP Viewer Upload Demo</title>
    <style>
        <?= $viewer->css() ?>

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

        .demo-message {
            max-width: 920px;
            margin: 18px auto 0;
            padding: 12px 14px;
            border: 1px solid rgba(248, 113, 113, 0.45);
            border-radius: 8px;
            background: rgba(127, 29, 29, 0.28);
            color: #fecaca;
        }

        .demo-empty {
            max-width: 920px;
            margin: 32px auto;
            padding: 22px;
            border: 1px dashed rgba(148, 163, 184, 0.35);
            border-radius: 8px;
            color: #cbd5e1;
            background: rgba(15, 23, 42, 0.55);
        }

        .demo-file {
            max-width: 920px;
            margin: 18px auto 0;
            color: #93c5fd;
            font-size: 13px;
        }
    </style>
</head>
<body>
    <div class="demo-shell">
        <header class="demo-toolbar">
            <h1>PBB SITREP Viewer Upload Demo</h1>
            <form class="demo-upload" method="post" enctype="multipart/form-data">
                <input type="file" name="sitrep" accept="application/json,.json" required>
                <button type="submit">Render SITREP</button>
            </form>
        </header>

        <?php if ($error !== null): ?>
            <p class="demo-message"><?= e($error) ?></p>
        <?php endif; ?>

        <?php if ($rendered !== null): ?>
            <?php if ($fileName !== null): ?>
                <p class="demo-file">Rendered <?= e($fileName) ?></p>
            <?php endif; ?>
            <?= $rendered ?>
        <?php else: ?>
            <div class="demo-empty">
                Upload a generated SITREP JSON payload to render it with the framework-agnostic viewer SDK.
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
