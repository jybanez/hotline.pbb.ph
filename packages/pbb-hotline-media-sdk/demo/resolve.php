<?php

declare(strict_types=1);

use Pbb\Hotline\Media\FilesystemMediaCache;
use Pbb\Hotline\Media\HotlineMediaClient;
use Pbb\Hotline\Media\MediaRefLocalUrl;
use Pbb\Hotline\Media\SitrepMediaRefResolver;

$autoload = dirname(__DIR__, 3).DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR.'autoload.php';
if (! is_file($autoload)) {
    fwrite(STDERR, "Unable to find project autoload file: {$autoload}\n");
    exit(1);
}

require $autoload;

spl_autoload_register(static function (string $class): void {
    $prefix = 'Pbb\\Hotline\\Media\\';
    if (! str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $path = dirname(__DIR__).DIRECTORY_SEPARATOR.'src'.DIRECTORY_SEPARATOR.str_replace('\\', DIRECTORY_SEPARATOR, $relative).'.php';
    if (is_file($path)) {
        require $path;
    }
});

$options = parseOptions($argv);
if (isset($options['help'])) {
    printHelp();
    exit(0);
}

$sitrepPath = (string) ($options['sitrep'] ?? (__DIR__.DIRECTORY_SEPARATOR.'input'.DIRECTORY_SEPARATOR.'sample-sitrep-media.json'));
$cacheDir = (string) ($options['cache-dir'] ?? (__DIR__.DIRECTORY_SEPARATOR.'cache'));
$baseUrlOverride = text($options['base-url'] ?? null);
$token = text($options['token'] ?? getenv('HOTLINE_MEDIA_ACCESS_TOKEN') ?: null);
$sourceSystem = text($options['source-system'] ?? getenv('PBB_SOURCE_SYSTEM') ?: null) ?? 'support.dispatch';
$sourceHubId = text($options['source-hub-id'] ?? getenv('PBB_SOURCE_HUB_ID') ?: null) ?? 'demo-support-hub';
$dryRun = isset($options['dry-run']);

$sitrep = loadJsonFile($sitrepPath);
$resolver = new SitrepMediaRefResolver();
$localUrl = new MediaRefLocalUrl();
$refs = $resolver->extractMediaRefs($sitrep);
$sourceHubs = $resolver->resolveSourceHubs($sitrep);
$groups = groupRefsByBaseUrl($refs, $sourceHubs, $baseUrlOverride);

line('Hotline Media SDK Demo');
line('SITREP: '.$sitrepPath);
line('Media refs: '.count($refs));
line('Source hubs: '.json_encode($sourceHubs, JSON_UNESCAPED_SLASHES));
line('');

if ($refs === []) {
    line('No media_refs were found in this SITREP.');
    exit(0);
}

foreach ($groups as $baseUrl => $groupRefs) {
    line('Source: '.$baseUrl);
    foreach ($groupRefs as $ref) {
        line('  - '.describeRef($ref));
        line('    local_url: '.($localUrl->path($ref) ?? 'unavailable'));
        line('    cache_key: '.($localUrl->cacheKey($ref) ?? 'unavailable'));
    }
    line('');
}

if ($dryRun) {
    line('Dry run only. No manifest or download requests were sent.');
    exit(0);
}

if ($token === null) {
    fwrite(STDERR, "Live mode requires --token or HOTLINE_MEDIA_ACCESS_TOKEN. Use --dry-run to inspect only.\n");
    exit(1);
}

$client = new HotlineMediaClient([
    'token' => $token,
    'source_system' => $sourceSystem,
    'source_hub_id' => $sourceHubId,
], new FilesystemMediaCache($cacheDir));

foreach ($groups as $baseUrl => $groupRefs) {
    line('Requesting manifest from '.$baseUrl);
    $manifest = $client->manifest($groupRefs, $baseUrl);
    line('  Manifest: '.($manifest['status'] ?? 'unknown').' HTTP '.($manifest['http_status'] ?? 'n/a'));

    if (($manifest['status'] ?? null) !== 'ok') {
        line('');
        continue;
    }

    $payload = is_array($manifest['payload'] ?? null) ? $manifest['payload'] : [];
    $items = is_array($payload['items'] ?? null) ? $payload['items'] : [];
    $unavailable = is_array($payload['unavailable'] ?? null) ? $payload['unavailable'] : [];
    line('  Available: '.count($items));
    line('  Unavailable: '.count($unavailable));

    foreach ($items as $item) {
        if (! is_array($item)) {
            continue;
        }

        $result = $client->fetchAndCache($item, $baseUrl);
        $path = text($result['path'] ?? $result['local_path'] ?? null);
        $suffix = $path !== null ? ' -> '.$path : '';
        line('  Download: '.($result['status'] ?? 'unknown').' '.describeRef($result).$suffix);
    }

    line('');
}

/**
 * @return array<string, string|bool>
 */
function parseOptions(array $argv): array
{
    $options = [];

    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--help' || $arg === '-h') {
            $options['help'] = true;
            continue;
        }

        if ($arg === '--dry-run') {
            $options['dry-run'] = true;
            continue;
        }

        if (str_starts_with($arg, '--') && str_contains($arg, '=')) {
            [$key, $value] = explode('=', substr($arg, 2), 2);
            $options[$key] = $value;
        }
    }

    return $options;
}

function printHelp(): void
{
    line('Usage: php packages/pbb-hotline-media-sdk/demo/resolve.php [options]');
    line('');
    line('Options:');
    line('  --dry-run                  Parse refs and show planned calls only.');
    line('  --sitrep=<path>            SITREP JSON file. Defaults to demo input.');
    line('  --cache-dir=<path>         Cache directory. Defaults to demo/cache.');
    line('  --base-url=<url>           Override all source Hotline base URLs.');
    line('  --token=<token>            Media access token. Defaults to HOTLINE_MEDIA_ACCESS_TOKEN.');
    line('  --source-system=<system>   Audit source system. Defaults to support.dispatch.');
    line('  --source-hub-id=<id>       Audit source hub id. Defaults to demo-support-hub.');
}

/**
 * @return array<string, mixed>
 */
function loadJsonFile(string $path): array
{
    if (! is_file($path)) {
        fwrite(STDERR, "SITREP file not found: {$path}\n");
        exit(1);
    }

    $payload = json_decode((string) file_get_contents($path), true);
    if (! is_array($payload)) {
        fwrite(STDERR, "SITREP file is not valid JSON object: {$path}\n");
        exit(1);
    }

    return $payload;
}

/**
 * @param  array<int, array<string, mixed>>  $refs
 * @param  array<string, string>  $sourceHubs
 * @return array<string, array<int, array<string, mixed>>>
 */
function groupRefsByBaseUrl(array $refs, array $sourceHubs, ?string $baseUrlOverride): array
{
    $groups = [];

    foreach ($refs as $ref) {
        $sourceHubId = text($ref['source_hub_id'] ?? null);
        $baseUrl = $baseUrlOverride ?? ($sourceHubId !== null ? ($sourceHubs[$sourceHubId] ?? null) : null);

        if ($baseUrl === null) {
            $baseUrl = 'missing-source-url';
        }

        $groups[rtrim($baseUrl, '/')][] = $ref;
    }

    ksort($groups);

    return $groups;
}

/**
 * @param  array<string, mixed>  $ref
 */
function describeRef(array $ref): string
{
    $kind = text($ref['kind'] ?? null) ?? 'unknown';
    $incidentId = text($ref['incident_id'] ?? null);
    $incident = text($ref['incident_ref'] ?? null) ?? ($incidentId !== null ? 'incident '.$incidentId : 'incident unknown');
    $id = text($ref['media_id'] ?? $ref['attachment_id'] ?? $ref['id'] ?? null) ?? 'unknown';
    $name = text($ref['original_filename'] ?? $ref['safe_filename'] ?? null) ?? 'unnamed';

    return "{$kind} {$id} {$incident} {$name}";
}

function text(mixed $value): ?string
{
    $text = trim((string) $value);

    return $text !== '' ? $text : null;
}

function line(string $message): void
{
    fwrite(STDOUT, $message.PHP_EOL);
}
