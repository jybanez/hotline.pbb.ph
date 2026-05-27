<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$options = parseOptions($argv);
$releasePath = $root.DIRECTORY_SEPARATOR.'release.json';

if (! is_file($releasePath)) {
    fail('release.json is missing.');
}

$release = json_decode((string) file_get_contents($releasePath), true);
if (! is_array($release)) {
    fail('release.json is not valid JSON.');
}

$app = (string) ($release['app'] ?? 'pbb-hotline');
$version = (string) ($release['version'] ?? '');
if ($version === '') {
    fail('release.json.version is required.');
}

$buildId = (string) ($options['build-id'] ?? sprintf('%s-%s', $app, date('Ymd-His')));
$outputPath = (string) ($options['output'] ?? $root.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'installer-build'.DIRECTORY_SEPARATOR.sprintf('%s-%s.zip', $app, $version));
$stagePath = $root.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'installer-build'.DIRECTORY_SEPARATOR.'stage-'.$buildId;

ensureDirectory(dirname($outputPath));
removePath($stagePath);
ensureDirectory($stagePath);

if (! isset($options['skip-npm'])) {
    runCommand(['npm', 'ci'], $root);
    runCommand(['npm', 'run', 'build'], $root);
}

$requiredPaths = [
    'public/build/manifest.json',
    'public/vendor/helpers.pbb.ph/dist/helpers.ui.bundle.min.js',
    'public/vendor/helpers.pbb.ph/dist/helpers.ui.bundle.min.css',
    'bin/ffmpeg/ffmpeg.exe',
    'database/schema/hotline-schema-mysql.sql',
    'resources/data/hotline/reference-data.json',
    'installer/install-run.php',
];

foreach ($requiredPaths as $requiredPath) {
    if (! is_file($root.DIRECTORY_SEPARATOR.pathToNative($requiredPath))) {
        fail("Required build input is missing: {$requiredPath}");
    }
}

$includes = array_values(array_unique(array_map(
    static fn (mixed $path): string => trim((string) $path),
    $release['distributable']['include'] ?? [],
)));
$excludes = array_values(array_filter(array_map(
    static fn (mixed $path): string => normalizePath((string) $path),
    $release['distributable']['exclude'] ?? [],
)));

foreach ($includes as $include) {
    if ($include === 'checksums.sha256') {
        continue;
    }

    if ($include === 'vendor/' && ! isset($options['skip-composer'])) {
        continue;
    }

    $source = $root.DIRECTORY_SEPARATOR.pathToNative($include);
    if (! file_exists($source)) {
        continue;
    }

    copyIntoStage($root, $stagePath, normalizePath($include), $excludes);
}

if (! isset($options['skip-composer'])) {
    $lockPath = $root.DIRECTORY_SEPARATOR.'composer.lock';
    if (! is_file($lockPath)) {
        fail('composer.lock is required for production bundle builds.');
    }

    copy($lockPath, $stagePath.DIRECTORY_SEPARATOR.'composer.lock');

    runCommand([
        ...composerCommand($options),
        'install',
        '--no-dev',
        '--prefer-dist',
        '--optimize-autoloader',
        '--no-interaction',
    ], $stagePath);

    removePath($stagePath.DIRECTORY_SEPARATOR.'composer.lock');
}

assertRequiredPaths($stagePath, [
    'vendor/autoload.php',
    ...$requiredPaths,
]);

$stagedRelease = $release;
$stagedRelease['build'] = [
    'version' => $version,
    'id' => $buildId,
    'built_at' => date(DATE_ATOM),
    'git_commit' => (string) ($options['git-commit'] ?? gitCommit($root) ?? ($release['build']['git_commit'] ?? 'unknown')),
    'builder' => 'tools/build-hotline-bundle.php',
];
$stagedRelease['update'] = $stagedRelease['update'] ?? [
    'contract_version' => 1,
    'channel' => (string) ($options['channel'] ?? 'testing'),
    'immutable_release' => false,
    'from_versions' => [$version],
    'compatibility' => 'same-version-rebuild',
    'requires_database_migration' => false,
    'requires_data_prep_rerun' => false,
    'requires_service_restart' => true,
    'rollback_supported' => true,
];

file_put_contents(
    $stagePath.DIRECTORY_SEPARATOR.'release.json',
    json_encode($stagedRelease, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL,
);

writeChecksums($stagePath);

if (is_file($outputPath)) {
    unlink($outputPath);
}

zipDirectory($stagePath, $outputPath);

$archiveHash = hash_file('sha256', $outputPath);
$summary = [
    'status' => 'success',
    'app' => $app,
    'version' => $version,
    'build_id' => $buildId,
    'output' => $outputPath,
    'sha256' => $archiveHash,
    'size_bytes' => filesize($outputPath),
    'stage' => isset($options['keep-stage']) ? $stagePath : null,
];

if (! isset($options['keep-stage'])) {
    removePath($stagePath);
}

echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;

/**
 * @return array<string, string|bool>
 */
function parseOptions(array $argv): array
{
    $options = [];

    foreach (array_slice($argv, 1) as $arg) {
        if (! str_starts_with($arg, '--')) {
            continue;
        }

        $arg = substr($arg, 2);
        if (str_contains($arg, '=')) {
            [$key, $value] = explode('=', $arg, 2);
            $options[$key] = $value;
            continue;
        }

        $options[$arg] = true;
    }

    return $options;
}

function copyIntoStage(string $root, string $stagePath, string $relativePath, array $excludes): void
{
    $source = $root.DIRECTORY_SEPARATOR.pathToNative($relativePath);
    $destination = $stagePath.DIRECTORY_SEPARATOR.pathToNative($relativePath);

    if (is_file($source)) {
        if (! isExcluded($relativePath, $excludes)) {
            ensureDirectory(dirname($destination));
            copy($source, $destination);
        }
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST,
    );

    foreach ($iterator as $item) {
        $itemPath = $item->getPathname();
        $itemRelative = normalizePath(substr($itemPath, strlen($root) + 1));

        if (isExcluded($itemRelative, $excludes)) {
            if ($item->isDir()) {
                $iterator->next();
            }
            continue;
        }

        $target = $stagePath.DIRECTORY_SEPARATOR.pathToNative($itemRelative);
        if ($item->isDir()) {
            ensureDirectory($target);
            continue;
        }

        ensureDirectory(dirname($target));
        copy($itemPath, $target);
    }
}

function isExcluded(string $relativePath, array $excludes): bool
{
    $relativePath = normalizePath($relativePath);

    foreach ($excludes as $pattern) {
        $pattern = rtrim($pattern, '/');
        if ($pattern === '') {
            continue;
        }

        if (str_ends_with($pattern, '/*')) {
            $prefix = substr($pattern, 0, -1);
            if (str_starts_with($relativePath.'/', $prefix)) {
                return true;
            }
        }

        if (str_contains($pattern, '*') && fnmatch($pattern, $relativePath)) {
            return true;
        }

        if ($relativePath === $pattern || str_starts_with($relativePath.'/', $pattern.'/')) {
            return true;
        }
    }

    return false;
}

function assertRequiredPaths(string $root, array $requiredPaths): void
{
    foreach ($requiredPaths as $requiredPath) {
        if (! is_file($root.DIRECTORY_SEPARATOR.pathToNative((string) $requiredPath))) {
            fail("Required build output is missing: {$requiredPath}");
        }
    }
}

function writeChecksums(string $stagePath): void
{
    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($stagePath, FilesystemIterator::SKIP_DOTS),
    );

    foreach ($iterator as $file) {
        if (! $file->isFile()) {
            continue;
        }

        $relative = normalizePath(substr($file->getPathname(), strlen($stagePath) + 1));
        if ($relative === 'checksums.sha256') {
            continue;
        }

        $files[] = $relative;
    }

    sort($files, SORT_STRING);

    $lines = [];
    foreach ($files as $relative) {
        $lines[] = hash_file('sha256', $stagePath.DIRECTORY_SEPARATOR.pathToNative($relative)).'  '.$relative;
    }

    file_put_contents($stagePath.DIRECTORY_SEPARATOR.'checksums.sha256', implode(PHP_EOL, $lines).PHP_EOL);
}

function zipDirectory(string $sourceDir, string $zipPath): void
{
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        fail("Unable to create ZIP: {$zipPath}");
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($sourceDir, FilesystemIterator::SKIP_DOTS),
    );

    foreach ($iterator as $file) {
        if ($file->isFile()) {
            $zip->addFile($file->getPathname(), normalizePath(substr($file->getPathname(), strlen($sourceDir) + 1)));
        }
    }

    $zip->close();
}

function runCommand(array $command, string $cwd): void
{
    $descriptorSpec = [
        0 => STDIN,
        1 => STDOUT,
        2 => STDERR,
    ];

    $process = proc_open($command, $descriptorSpec, $pipes, $cwd);
    if (! is_resource($process)) {
        fail('Unable to start command: '.implode(' ', $command));
    }

    $exitCode = proc_close($process);
    if ($exitCode !== 0) {
        fail('Command failed: '.implode(' ', $command));
    }
}

/**
 * @return list<string>
 */
function composerCommand(array $options): array
{
    $configured = (string) ($options['composer-bin'] ?? getenv('COMPOSER_BINARY') ?: '');

    if ($configured !== '') {
        return str_ends_with(strtolower($configured), '.phar')
            ? [PHP_BINARY, $configured]
            : [$configured];
    }

    $windowsComposerPhar = 'C:\\ProgramData\\ComposerSetup\\bin\\composer.phar';
    if (PHP_OS_FAMILY === 'Windows' && is_file($windowsComposerPhar)) {
        return [PHP_BINARY, $windowsComposerPhar];
    }

    return ['composer'];
}

function gitCommit(string $root): ?string
{
    $command = 'git -C '.escapeshellarg($root).' rev-parse --short HEAD';
    $output = [];
    $exitCode = 1;
    exec($command, $output, $exitCode);

    return $exitCode === 0 && isset($output[0]) ? trim($output[0]) : null;
}

function ensureDirectory(string $path): void
{
    if (! is_dir($path) && ! mkdir($path, 0777, true) && ! is_dir($path)) {
        fail("Unable to create directory: {$path}");
    }
}

function removePath(string $path): void
{
    if (! file_exists($path)) {
        return;
    }

    if (is_file($path) || is_link($path)) {
        unlink($path);
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($iterator as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }

    rmdir($path);
}

function normalizePath(string $path): string
{
    return trim(str_replace('\\', '/', $path), '/');
}

function pathToNative(string $path): string
{
    return str_replace('/', DIRECTORY_SEPARATOR, normalizePath($path));
}

function fail(string $message): never
{
    fwrite(STDERR, $message.PHP_EOL);
    exit(1);
}
