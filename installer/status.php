<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$manifestPath = $root.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'installer'.DIRECTORY_SEPARATOR.'install-manifest.json';
$reportPath = $root.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'installer'.DIRECTORY_SEPARATOR.'install-report.json';

$payload = [
    'schema_version' => 1,
    'app' => 'pbb-hotline',
    'status' => file_exists($manifestPath) ? 'installed' : 'not_installed',
    'checked_at' => gmdate('c'),
    'manifest_path' => $manifestPath,
    'report_path' => $reportPath,
    'artifacts' => [
        'manifest_exists' => file_exists($manifestPath),
        'report_exists' => file_exists($reportPath),
        'env_exists' => file_exists($root.DIRECTORY_SEPARATOR.'.env'),
        'vendor_exists' => file_exists($root.DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR.'autoload.php'),
        'build_exists' => file_exists($root.DIRECTORY_SEPARATOR.'public'.DIRECTORY_SEPARATOR.'build'.DIRECTORY_SEPARATOR.'manifest.json'),
    ],
];

echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
