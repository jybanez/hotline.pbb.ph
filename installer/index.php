<?php

declare(strict_types=1);

header('Content-Type: text/html; charset=utf-8');

?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>PBB Hotline Installer</title>
    <style>
        body {
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            margin: 0;
            padding: 2rem;
            color: #172033;
            background: #f5f7fb;
        }

        main {
            max-width: 760px;
            margin: 0 auto;
            background: #fff;
            border: 1px solid #d8deea;
            border-radius: 8px;
            padding: 1.5rem;
        }

        h1 {
            margin: 0 0 .75rem;
            font-size: 1.35rem;
        }

        code {
            background: #edf1f7;
            border-radius: 4px;
            padding: .1rem .3rem;
        }
    </style>
</head>
<body>
    <main>
        <h1>PBB Hotline Installer</h1>
        <p>The Kit Setup-compatible installer contract is present. Use <code>installer/install-run.php</code> for unattended installation and <code>installer/status.php</code> for machine-readable status.</p>
        <p>The full browser installer UI is not implemented in this slice.</p>
    </main>
</body>
</html>
