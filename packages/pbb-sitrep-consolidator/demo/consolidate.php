<?php

declare(strict_types=1);

use Pbb\Sitreps\Consolidation\SitrepConsolidator;
use Pbb\Sitreps\Consolidation\SitrepNormalizer;
use Pbb\Sitreps\Consolidation\Staging\FilesystemSitrepStagingStore;

$autoloadCandidates = [
    __DIR__.'/../../../vendor/autoload.php',
    __DIR__.'/../vendor/autoload.php',
];

foreach ($autoloadCandidates as $autoload) {
    if (is_file($autoload)) {
        require $autoload;
        break;
    }
}

if (! class_exists(SitrepConsolidator::class)) {
    fwrite(STDERR, "Unable to load SDK classes. Run composer dump-autoload from the consuming app.\n");
    exit(1);
}

$baseDir = __DIR__;
$inputDir = $baseDir.'/input/barangay';
$stagingDir = $baseDir.'/staging';
$outputDir = $baseDir.'/output';

foreach ([$stagingDir, $outputDir] as $directory) {
    if (! is_dir($directory)) {
        mkdir($directory, 0775, true);
    }
}

$normalizer = new SitrepNormalizer();
$staging = new FilesystemSitrepStagingStore($stagingDir);
$consolidator = new SitrepConsolidator($normalizer);

foreach (glob($inputDir.'/*.json') ?: [] as $path) {
    $payload = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    $normalized = $normalizer->normalize($payload);

    if ($normalized['normalized'] === null) {
        fwrite(STDERR, sprintf("Skipping invalid input: %s\n", basename($path)));
        continue;
    }

    $staging->stage($normalized['normalized']);
}

$staged = $staging->list('barangay');
$result = $consolidator->consolidate($staged, [
    'target_level' => 'city',
    'target_hub_id' => '21',
    'target_hub_name' => 'Cebu City, Cebu',
    'coverage_area' => 'Cebu City, Cebu',
    'period_started_at' => '2026-05-29T17:00:00+08:00',
    'period_ended_at' => '2026-05-29T17:15:00+08:00',
]);

if (! $result->ok) {
    fwrite(STDERR, json_encode($result->toArray()['errors'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
    exit(1);
}

$outputPath = $outputDir.'/city-sitrep.json';
file_put_contents($outputPath, json_encode($result->sitrep, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

printf("Sources: %d barangay SITREPs\n", count($staged));
printf("Target: city\n");
printf("Alert level: %s\n", $result->sitrep['alert_level']);
printf("Output: %s\n", str_replace('\\', '/', $outputPath));
printf("Warnings: %d\n", count($result->warnings()));
