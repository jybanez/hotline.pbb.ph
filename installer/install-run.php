<?php

declare(strict_types=1);

$startedAt = gmdate('c');
$root = dirname(__DIR__);
$mode = optionValue($argv, '--mode') ?? 'preflight';
$configPath = optionValue($argv, '--config');
$reportPath = optionValue($argv, '--report');
$dryRun = in_array('--dry-run', $argv, true);
$noServiceRegister = in_array('--no-service-register', $argv, true);

$configResult = loadConfig($configPath);
$config = $configResult['config'];
$checks = [];

if ($configResult['check'] !== null) {
    $checks[] = $configResult['check'];
}

if ($mode === 'preflight') {
    $checks = array_merge($checks, runPreflight($root, $config));
    $status = hasFailedChecks($checks) ? 'failed' : 'success';
    $summary = $status === 'success'
        ? 'Hotline preflight passed.'
        : 'Hotline preflight found blocking issues.';
    $extra = [];
} elseif ($mode === 'fresh') {
    $result = runFreshInstallSlice($root, $config, $dryRun, $noServiceRegister);
    $checks = array_merge($checks, $result['checks']);
    $status = $result['status'];
    $summary = $result['summary'];
    $extra = $result['extra'];
} else {
    $status = 'not_implemented';
    $summary = 'Hotline mutating installer modes are not implemented yet.';
    $extra = [];
}

$report = [
    'schema_version' => 1,
    'app' => 'pbb-hotline',
    'tool' => 'installer',
    'mode' => $mode,
    'dry_run' => $dryRun,
    'no_service_register' => $noServiceRegister,
    'status' => $status,
    'started_at' => $startedAt,
    'finished_at' => gmdate('c'),
    'summary' => $summary,
    'checks' => $checks,
] + $extra;

if ($mode === 'fresh' && ! $dryRun) {
    writeCanonicalInstallReport($root, $report);
}

emitJson($report, $reportPath);

exit($status === 'success' ? 0 : 2);

/**
 * @return array<int, array<string, mixed>>
 */
function runPreflight(string $root, array $config): array
{
    $checks = [];

    $checks[] = checkPhpVersion('php_version', '8.2.0');
    foreach (requiredPhpExtensions() as $extension) {
        $checks[] = checkPhpExtension($extension);
    }

    $checks[] = checkPath('release_json', $root.DIRECTORY_SEPARATOR.'release.json');
    $checks[] = checkPath('schema', $root.DIRECTORY_SEPARATOR.'installer'.DIRECTORY_SEPARATOR.'schema'.DIRECTORY_SEPARATOR.'install.schema.json');
    $checks[] = checkPath('env_example', $root.DIRECTORY_SEPARATOR.'.env.example');
    $checks[] = checkPath('artisan', $root.DIRECTORY_SEPARATOR.'artisan');
    $checks[] = checkPath('vendor_autoload', $root.DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR.'autoload.php');
    $checks[] = checkPath('vite_manifest', $root.DIRECTORY_SEPARATOR.'public'.DIRECTORY_SEPARATOR.'build'.DIRECTORY_SEPARATOR.'manifest.json');
    $checks[] = checkPath('operator_ringtone_audio', $root.DIRECTORY_SEPARATOR.'public'.DIRECTORY_SEPARATOR.'audio'.DIRECTORY_SEPARATOR.'ringtone.mp3');
    $checks[] = checkPath('helper_bundle_js', $root.DIRECTORY_SEPARATOR.'public'.DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR.'helpers.pbb.ph'.DIRECTORY_SEPARATOR.'dist'.DIRECTORY_SEPARATOR.'helpers.ui.bundle.min.js');
    $checks[] = checkPath('helper_bundle_css', $root.DIRECTORY_SEPARATOR.'public'.DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR.'helpers.pbb.ph'.DIRECTORY_SEPARATOR.'dist'.DIRECTORY_SEPARATOR.'helpers.ui.bundle.min.css');
    $checks[] = checkPath('realtime_sdk', $root.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'Support'.DIRECTORY_SEPARATOR.'Realtime'.DIRECTORY_SEPARATOR.'Sdk'.DIRECTORY_SEPARATOR.'pbb_realtime_backend_sdk.php');

    foreach (writablePaths($root) as $id => $path) {
        $checks[] = checkWritableDirectory($id, $path);
    }

    $checks[] = checkConfigShape($config);

    if ($config !== []) {
        $checks = array_merge($checks, checkFilesystemBoundaries($root, $config));
        $checks[] = checkDatabase($config['database'] ?? []);
        $checks[] = checkMediaBinary($root, 'ffmpeg', (string) dataGet($config, ['hotline', 'ffmpeg_binary'], ''));
        $checks[] = checkMediaBinary($root, 'ffprobe', (string) dataGet($config, ['hotline', 'ffprobe_binary'], ''), false);
        $checks[] = checkNodeBinary((string) dataGet($config, ['hotline', 'sitrep_node_binary'], 'node'));
        $checks[] = checkRealtimeCaBundle($config);
        $checks = array_merge($checks, checkSecrets($config));
        $checks = array_merge($checks, checkUrls($config));
    } else {
        $checks[] = [
            'id' => 'database_connection',
            'status' => 'warn',
            'message' => 'Skipped because no --config file was provided.',
        ];
        $checks[] = [
            'id' => 'media_binaries',
            'status' => 'warn',
            'message' => 'Skipped configured media binary checks because no --config file was provided.',
        ];
    }

    return $checks;
}

/**
 * @return array{status: string, summary: string, checks: array<int, array<string, mixed>>, extra: array<string, mixed>}
 */
function runFreshInstallSlice(string $root, array $config, bool $dryRun, bool $noServiceRegister): array
{
    $checks = runPreflight($root, $config);

    if ($config === []) {
        $checks[] = [
            'id' => 'fresh_config_required',
            'status' => 'fail',
            'message' => 'Fresh install requires --config.',
        ];
    }

    $envPath = $root.DIRECTORY_SEPARATOR.'.env';
    $overwriteEnv = (bool) dataGet($config, ['options', 'overwrite_env'], false);
    $envExists = file_exists($envPath);

    if ($envExists && ! $overwriteEnv) {
        $checks[] = [
            'id' => 'env_overwrite_policy',
            'status' => 'fail',
            'path' => $envPath,
            'message' => '.env already exists and options.overwrite_env is false.',
        ];
    } else {
        $checks[] = [
            'id' => 'env_overwrite_policy',
            'status' => 'pass',
            'path' => $envPath,
            'message' => $envExists ? '.env will be backed up before rewrite.' : '.env will be created.',
        ];
    }

    if (hasFailedChecks($checks)) {
        return [
            'status' => 'failed',
            'summary' => 'Hotline fresh install preflight failed; no files were written.',
            'checks' => $checks,
            'extra' => [
                'actions' => [],
            ],
        ];
    }

    $runtimeDirs = runtimeDirectoryPaths($root);
    $installerDir = $root.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'installer';
    $servicesDir = $installerDir.DIRECTORY_SEPARATOR.'services';
    $backupPath = null;
    $actions = [
        [
            'id' => 'prepare_installer_directories',
            'status' => $dryRun ? 'planned' : 'pending',
            'paths' => array_values(array_unique(array_merge($runtimeDirs, [$installerDir, $servicesDir]))),
        ],
        [
            'id' => 'write_env',
            'status' => $dryRun ? 'planned' : 'pending',
            'path' => $envPath,
        ],
    ];

    if ($envExists) {
        $backupPath = $envPath.'.backup-'.date('Ymd-His');
        array_splice($actions, 1, 0, [[
            'id' => 'backup_env',
            'status' => $dryRun ? 'planned' : 'pending',
            'path' => $backupPath,
        ]]);
    }

    $databaseSetup = plannedDatabaseSetup($root, $config);

    foreach (freshInstallCommands($root, $config) as $command) {
        $actions[] = [
            'id' => $command['id'],
            'status' => $dryRun ? 'planned' : 'pending',
        ] + actionPlanMetadata($command);
    }

    $serviceArtifacts = serviceArtifacts($root, $config);
    $actions[] = [
        'id' => 'write_service_artifacts',
        'status' => $dryRun ? 'planned' : 'pending',
        'paths' => array_map(static fn (array $artifact): string => $artifact['path'], $serviceArtifacts),
    ];

    if (shouldRegisterServices($config) && ! $noServiceRegister) {
        $actions[] = [
            'id' => 'register_services',
            'status' => $dryRun ? 'planned' : 'pending',
            'message' => 'Direct service registration is not implemented in this slice; generated artifacts are provided for Kit/manual registration.',
        ];
    }

    if ((bool) dataGet($config, ['options', 'validate_after_install'], true)) {
        $actions[] = [
            'id' => 'post_install_health_checks',
            'status' => $dryRun ? 'planned' : 'pending',
            'checks' => postInstallHealthCheckIds(),
        ];
    }

    $actions[] = [
        'id' => 'write_manifest',
        'status' => $dryRun ? 'planned' : 'pending',
        'path' => $installerDir.DIRECTORY_SEPARATOR.'install-manifest.json',
    ];

    $manifest = buildInstallManifest($root, $config, $envPath, $backupPath, $databaseSetup);

    if (! $dryRun) {
        foreach ($runtimeDirs as $runtimeDir) {
            ensureDirectory($runtimeDir);
        }
        ensureDirectory($installerDir);
        ensureDirectory($servicesDir);

        if ($envExists && $backupPath !== null) {
            if (! copy($envPath, $backupPath)) {
                return [
                    'status' => 'failed',
                    'summary' => 'Unable to back up existing .env.',
                    'checks' => $checks,
                    'extra' => [
                        'actions' => markAction($actions, 'backup_env', 'failed'),
                    ],
                ];
            }
            $actions = markAction($actions, 'backup_env', 'success');
        }

        file_put_contents($envPath, buildEnvFile($config));
        $actions = markAction($actions, 'write_env', 'success');
        $actions = markAction($actions, 'prepare_installer_directories', 'success');

        foreach (freshInstallCommands($root, $config) as $command) {
            $commandResult = runInstallCommand($root, $config, $command);
            $actions = markAction($actions, $command['id'], $commandResult['status'], $commandResult);
            if ($command['id'] === 'apply_baseline_schema' && $commandResult['status'] === 'success') {
                $databaseSetup['migration_rows'] = (int) ($commandResult['migration_rows'] ?? $databaseSetup['migration_rows'] ?? 0);
                $manifest = buildInstallManifest($root, $config, $envPath, $backupPath, $databaseSetup);
            }

            if ($commandResult['status'] !== 'success') {
                return [
                    'status' => 'failed',
                    'summary' => 'Hotline fresh install command failed: '.$command['id'],
                    'checks' => $checks,
                    'extra' => [
                        'actions' => $actions,
                    ],
                ];
            }
        }

        writeServiceArtifacts($serviceArtifacts);
        $actions = markAction($actions, 'write_service_artifacts', 'success');

        if (shouldRegisterServices($config) && ! $noServiceRegister) {
            $actions = markAction($actions, 'register_services', 'failed', [
                'message' => 'Direct service registration is not implemented. Use generated service artifacts or pass --no-service-register.',
            ]);

            return [
                'status' => 'failed',
                'summary' => 'Hotline service artifact generation completed, but direct service registration is not implemented.',
                'checks' => $checks,
                'extra' => [
                    'actions' => $actions,
                ],
            ];
        }

        if ((bool) dataGet($config, ['options', 'validate_after_install'], true)) {
            $healthChecks = runPostInstallHealthChecks($root, $config);
            $actions = markAction($actions, 'post_install_health_checks', hasFailedChecks($healthChecks) ? 'failed' : 'success', [
                'checks' => $healthChecks,
            ]);

            if (hasFailedChecks($healthChecks)) {
                return [
                    'status' => 'failed',
                    'summary' => 'Hotline post-install health checks failed.',
                    'checks' => array_merge($checks, $healthChecks),
                    'extra' => [
                        'actions' => $actions,
                    ],
                ];
            }

            $checks = array_merge($checks, $healthChecks);
        }

        file_put_contents(
            $installerDir.DIRECTORY_SEPARATOR.'install-manifest.json',
            json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL,
        );
        $actions = markAction($actions, 'write_manifest', 'success');
    }

    return [
        'status' => 'success',
        'summary' => $dryRun
            ? 'Hotline fresh install dry run passed; no files were written.'
            : 'Hotline fresh install runnable slice completed.',
        'checks' => $checks,
        'extra' => [
            'actions' => $actions,
            'manifest' => $dryRun ? $manifest : null,
            'database_setup' => $databaseSetup,
        ],
    ];
}

/**
 * @return list<string>
 */
function requiredPhpExtensions(): array
{
    return [
        'bcmath',
        'curl',
        'fileinfo',
        'gd',
        'intl',
        'json',
        'mbstring',
        'openssl',
        'pdo',
        'pdo_mysql',
        'sodium',
        'tokenizer',
        'xml',
        'zip',
    ];
}

/**
 * @return array<string, string>
 */
function writablePaths(string $root): array
{
    return [
        'storage_writable' => $root.DIRECTORY_SEPARATOR.'storage',
        'storage_app_writable' => $root.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'app',
        'storage_framework_writable' => $root.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'framework',
        'storage_framework_cache_writable' => $root.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'framework'.DIRECTORY_SEPARATOR.'cache',
        'storage_framework_cache_data_writable' => $root.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'framework'.DIRECTORY_SEPARATOR.'cache'.DIRECTORY_SEPARATOR.'data',
        'storage_framework_sessions_writable' => $root.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'framework'.DIRECTORY_SEPARATOR.'sessions',
        'storage_framework_views_writable' => $root.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'framework'.DIRECTORY_SEPARATOR.'views',
        'storage_logs_writable' => $root.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'logs',
        'bootstrap_cache_writable' => $root.DIRECTORY_SEPARATOR.'bootstrap'.DIRECTORY_SEPARATOR.'cache',
    ];
}

/**
 * @return array<int, string>
 */
function runtimeDirectoryPaths(string $root): array
{
    return [
        $root.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'app',
        $root.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'framework',
        $root.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'framework'.DIRECTORY_SEPARATOR.'cache',
        $root.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'framework'.DIRECTORY_SEPARATOR.'cache'.DIRECTORY_SEPARATOR.'data',
        $root.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'framework'.DIRECTORY_SEPARATOR.'sessions',
        $root.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'framework'.DIRECTORY_SEPARATOR.'views',
        $root.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'logs',
        $root.DIRECTORY_SEPARATOR.'bootstrap'.DIRECTORY_SEPARATOR.'cache',
    ];
}

function checkPhpVersion(string $id, string $minimum): array
{
    return [
        'id' => $id,
        'status' => version_compare(PHP_VERSION, $minimum, '>=') ? 'pass' : 'fail',
        'actual' => PHP_VERSION,
        'required' => '>='.$minimum,
    ];
}

function checkPhpExtension(string $extension): array
{
    return [
        'id' => 'php_extension_'.$extension,
        'status' => extension_loaded($extension) ? 'pass' : 'fail',
        'extension' => $extension,
    ];
}

function checkPath(string $id, string $path): array
{
    return [
        'id' => $id,
        'path' => $path,
        'status' => file_exists($path) ? 'pass' : 'fail',
    ];
}

function checkWritableDirectory(string $id, string $path): array
{
    if (! file_exists($path)) {
        @mkdir($path, 0777, true);
    }

    if (! file_exists($path)) {
        return [
            'id' => $id,
            'path' => $path,
            'status' => 'fail',
            'message' => 'Path does not exist and could not be created.',
        ];
    }

    if (! is_dir($path)) {
        return [
            'id' => $id,
            'path' => $path,
            'status' => 'fail',
            'message' => 'Path exists but is not a directory.',
        ];
    }

    return [
        'id' => $id,
        'path' => $path,
        'status' => is_writable($path) ? 'pass' : 'fail',
        'message' => is_writable($path) ? 'Directory exists and is writable.' : 'Directory exists but is not writable.',
    ];
}

function checkConfigShape(array $config): array
{
    if ($config === []) {
        return [
            'id' => 'config_shape',
            'status' => 'warn',
            'message' => 'No --config file was provided; only bundle and host checks were run.',
        ];
    }

    $missing = [];
    foreach (['mode', 'app', 'database', 'admin', 'hotline'] as $key) {
        if (! array_key_exists($key, $config)) {
            $missing[] = $key;
        }
    }

    foreach ([['app', 'install_path'], ['app', 'app_url'], ['database', 'host'], ['database', 'database'], ['database', 'username'], ['admin', 'email'], ['hotline', 'media_assembly_token'], ['hotline', 'realtime_token_signing_secret']] as $path) {
        if (trim((string) dataGet($config, $path, '')) === '') {
            $missing[] = implode('.', $path);
        }
    }

    $adminStrategy = trim((string) dataGet($config, ['admin', 'strategy'], 'create_if_missing')) ?: 'create_if_missing';
    if (! in_array($adminStrategy, ['create_if_missing'], true)) {
        $missing[] = 'admin.strategy_supported';
    }

    return [
        'id' => 'config_shape',
        'status' => $missing === [] ? 'pass' : 'fail',
        'missing' => $missing,
    ];
}

/**
 * @return array<int, array<string, mixed>>
 */
function checkFilesystemBoundaries(string $root, array $config): array
{
    $checks = [];
    $installPath = (string) dataGet($config, ['app', 'install_path'], '');
    $publicPath = (string) dataGet($config, ['app', 'public_path'], '');
    $playwrightPath = (string) dataGet($config, ['hotline', 'playwright_browsers_path'], '');

    $checks[] = [
        'id' => 'boundary_app_install_path',
        'status' => pathsEqual($installPath, $root) ? 'pass' : 'fail',
        'path' => 'app.install_path',
        'configured' => $installPath,
        'app_root' => $root,
        'message' => pathsEqual($installPath, $root)
            ? 'Configured app.install_path matches the extracted app root.'
            : 'Configured app.install_path must match the extracted app root selected by Kit.',
    ];

    if (trim($publicPath) !== '') {
        $expectedPublicPath = $root.DIRECTORY_SEPARATOR.'public';
        $checks[] = [
            'id' => 'boundary_app_public_path',
            'status' => pathsEqual($publicPath, $expectedPublicPath) ? 'pass' : 'fail',
            'path' => 'app.public_path',
            'configured' => $publicPath,
            'expected' => $expectedPublicPath,
            'message' => pathsEqual($publicPath, $expectedPublicPath)
                ? 'Configured app.public_path matches the public directory under app.install_path.'
                : 'Configured app.public_path must stay under app.install_path as public/.',
        ];
    }

    if (trim($playwrightPath) !== '') {
        $checks[] = [
            'id' => 'boundary_hotline_playwright_browsers_path',
            'status' => pathIsWithin($playwrightPath, $root) ? 'pass' : 'fail',
            'path' => 'hotline.playwright_browsers_path',
            'configured' => $playwrightPath,
            'app_root' => $root,
            'message' => pathIsWithin($playwrightPath, $root)
                ? 'Playwright browser cache path is under app.install_path.'
                : 'App-owned Playwright browser cache path must be under app.install_path unless Kit provides an explicit external path contract.',
        ];
    }

    return $checks;
}

function normalizedSessionDomain(array $config, string $appUrl): string
{
    $appHost = strtolower((string) (parse_url($appUrl, PHP_URL_HOST) ?: ''));
    $configured = strtolower(trim((string) dataGet($config, ['app', 'session_domain'], '')));

    if ($configured === '') {
        return $appHost;
    }

    if (! isLocalHost($appHost) && isLocalHost($configured)) {
        return $appHost;
    }

    return $configured;
}

function isLocalHost(string $host): bool
{
    return in_array(trim(strtolower($host)), ['localhost', '127.0.0.1', '::1'], true);
}

function checkDatabase(array $database): array
{
    $host = (string) ($database['host'] ?? '');
    $port = (int) ($database['port'] ?? 3306);
    $name = (string) ($database['database'] ?? '');
    $username = (string) ($database['username'] ?? '');
    $password = (string) ($database['password'] ?? '');

    if ($host === '' || $name === '' || $username === '') {
        return [
            'id' => 'database_connection',
            'status' => 'fail',
            'message' => 'Database host, database, and username are required.',
        ];
    }

    try {
        $pdo = new PDO(
            sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $name),
            $username,
            $password,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => 5,
            ],
        );
        $pdo->query('SELECT 1');

        return [
            'id' => 'database_connection',
            'status' => 'pass',
            'host' => $host,
            'port' => $port,
            'database' => $name,
        ];
    } catch (Throwable $exception) {
        return [
            'id' => 'database_connection',
            'status' => 'fail',
            'host' => $host,
            'port' => $port,
            'database' => $name,
            'message' => $exception->getMessage(),
        ];
    }
}

function checkMediaBinary(string $root, string $id, string $configured, bool $required = true): array
{
    $candidates = mediaBinaryCandidates($root, $id, $configured);

    $resolved = null;
    foreach ($candidates as $candidate) {
        if (is_file($candidate) || commandExists($candidate)) {
            $resolved = $candidate;
            break;
        }
    }

    return [
        'id' => 'media_binary_'.$id,
        'status' => $resolved !== null ? 'pass' : ($required ? 'fail' : 'warn'),
        'binary' => $resolved ?? $candidates[0],
        'candidates' => $candidates,
        'message' => $resolved !== null && trim($configured) !== '' && $resolved !== trim($configured)
            ? 'Using bundled app-owned '.$id.' binary instead of configured external path.'
            : ($required ? null : 'Optional; not required by the current Hotline runtime.'),
    ];
}

/**
 * @return array<int, string>
 */
function mediaBinaryCandidates(string $root, string $id, string $configured): array
{
    $bundled = [
        $root.DIRECTORY_SEPARATOR.'bin'.DIRECTORY_SEPARATOR.'ffmpeg'.DIRECTORY_SEPARATOR.$id.'.exe',
        $root.DIRECTORY_SEPARATOR.'bin'.DIRECTORY_SEPARATOR.'ffmpeg'.DIRECTORY_SEPARATOR.$id,
    ];

    $candidates = $bundled;
    if (trim($configured) !== '') {
        $candidates[] = trim($configured);
    }
    $candidates[] = $id;

    return array_values(array_unique($candidates));
}

function resolveMediaBinaryForEnv(string $root, string $id, string $configured): string
{
    foreach (mediaBinaryCandidates($root, $id, $configured) as $candidate) {
        if (is_file($candidate) || commandExists($candidate)) {
            return $candidate;
        }
    }

    if ($id === 'ffprobe') {
        return trim($configured);
    }

    return $root.DIRECTORY_SEPARATOR.'bin'.DIRECTORY_SEPARATOR.'ffmpeg'.DIRECTORY_SEPARATOR.$id.'.exe';
}

function checkNodeBinary(string $binary): array
{
    $candidate = trim($binary) !== '' ? trim($binary) : 'node';

    return [
        'id' => 'sitrep_node_binary',
        'status' => commandExists($candidate) || is_file($candidate) ? 'pass' : 'warn',
        'binary' => $candidate,
        'message' => 'Required only when SITREP PDF export is enabled.',
    ];
}

function checkRealtimeCaBundle(array $config): array
{
    $configured = trim((string) dataGet($config, ['hotline', 'realtime_ca_bundle'], ''));
    $resolved = resolveRealtimeCaBundleForEnv(is_array($config['hotline'] ?? null) ? $config['hotline'] : []);

    if ($configured !== '' && ! is_file($configured)) {
        return [
            'id' => 'realtime_ca_bundle',
            'status' => 'fail',
            'path' => 'hotline.realtime_ca_bundle',
            'ca_bundle' => $configured,
            'message' => 'Configured Realtime CA bundle does not exist.',
        ];
    }

    if ($resolved !== '') {
        return [
            'id' => 'realtime_ca_bundle',
            'status' => 'pass',
            'path' => 'hotline.realtime_ca_bundle',
            'ca_bundle' => $resolved,
            'message' => 'Realtime HTTPS publish CA bundle is available.',
        ];
    }

    return [
        'id' => 'realtime_ca_bundle',
        'status' => 'warn',
        'path' => 'hotline.realtime_ca_bundle',
        'message' => 'No explicit Realtime CA bundle or PHP curl.cainfo/openssl.cafile was found; HTTPS publish may fail on Windows.',
    ];
}

function resolveRealtimeCaBundleForEnv(array $hotline): string
{
    foreach ([
        $hotline['realtime_ca_bundle'] ?? null,
        ini_get('curl.cainfo') ?: null,
        ini_get('openssl.cafile') ?: null,
        getenv('CURL_CA_BUNDLE') ?: null,
        getenv('SSL_CERT_FILE') ?: null,
    ] as $candidate) {
        $path = trim((string) $candidate);
        if ($path !== '' && is_file($path)) {
            return $path;
        }
    }

    return '';
}

/**
 * @return array<int, array<string, mixed>>
 */
function checkSecrets(array $config): array
{
    $checks = [];
    foreach ([
        ['hotline', 'media_assembly_token'],
        ['hotline', 'realtime_backend_ingress_secret'],
        ['hotline', 'realtime_media_ingest_secret'],
        ['hotline', 'realtime_token_signing_secret'],
        ['hotline', 'relay_token'],
        ['admin', 'password'],
    ] as $path) {
        $value = (string) dataGet($config, $path, '');
        $checks[] = [
            'id' => 'secret_'.implode('_', $path),
            'status' => isPlaceholderSecret($value) ? 'fail' : 'pass',
            'path' => implode('.', $path),
            'message' => isPlaceholderSecret($value) ? 'Secret is missing or still a placeholder.' : 'Secret is configured.',
        ];
    }

    $adminPassword = (string) dataGet($config, ['admin', 'password'], '');
    $checks[] = [
        'id' => 'admin_password_strength',
        'status' => isStrongAdminPassword($adminPassword) ? 'pass' : 'fail',
        'path' => 'admin.password',
        'message' => isStrongAdminPassword($adminPassword)
            ? 'Admin password satisfies the installer strength policy.'
            : 'Admin password must be at least 12 characters and include uppercase, lowercase, and numeric characters.',
    ];

    return $checks;
}

/**
 * @return array<int, array<string, mixed>>
 */
function checkUrls(array $config): array
{
    $checks = [];
    foreach ([
        ['app', 'app_url'],
        ['hotline', 'realtime_url'],
        ['hotline', 'relay_url'],
        ['hotline', 'map_server_url'],
    ] as $path) {
        $value = (string) dataGet($config, $path, '');
        $checks[] = [
            'id' => 'url_'.implode('_', $path),
            'status' => filter_var($value, FILTER_VALIDATE_URL) ? 'pass' : 'fail',
            'path' => implode('.', $path),
            'url' => $value,
        ];
    }

    return $checks;
}

function buildEnvFile(array $config): string
{
    $appUrl = (string) dataGet($config, ['app', 'app_url'], 'https://hotline.pbb.ph');
    $installPath = (string) dataGet($config, ['app', 'install_path'], dirname(__DIR__));
    $compiledViewPath = $installPath.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'framework'.DIRECTORY_SEPARATOR.'views';
    $appName = 'PBB Hotline';
    $hotline = is_array($config['hotline'] ?? null) ? $config['hotline'] : [];
    $database = is_array($config['database'] ?? null) ? $config['database'] : [];
    $normalSessionLifetime = (string) dataGet($config, ['hotline', 'normal_session_lifetime'], 15);
    $citizenSessionLifetime = (string) dataGet($config, ['hotline', 'citizen_session_lifetime'], 43200);
    $sessionDomain = normalizedSessionDomain($config, $appUrl);
    $ffmpegBinary = resolveMediaBinaryForEnv($installPath, 'ffmpeg', (string) ($hotline['ffmpeg_binary'] ?? ''));
    $ffprobeBinary = resolveMediaBinaryForEnv($installPath, 'ffprobe', (string) ($hotline['ffprobe_binary'] ?? ''));
    $realtimeCaBundle = resolveRealtimeCaBundleForEnv($hotline);

    $values = [
        'APP_NAME' => $appName,
        'APP_ENV' => (string) dataGet($config, ['app', 'app_env'], 'production'),
        'APP_KEY' => generateAppKey(),
        'APP_DEBUG' => boolString((bool) dataGet($config, ['app', 'app_debug'], false)),
        'APP_URL' => $appUrl,
        'APP_VERSION' => '1-5.6.1',
        'APP_RELEASE_NAME' => 'Citizen Live Call Readiness',
        'APP_RELEASE_DATE' => '2026-05-12',
        'APP_LOCALE' => 'en',
        'APP_FALLBACK_LOCALE' => 'en',
        'APP_FAKER_LOCALE' => 'en_US',
        'APP_MAINTENANCE_DRIVER' => 'file',
        'BCRYPT_ROUNDS' => '12',
        'LOG_CHANNEL' => 'stack',
        'LOG_STACK' => 'single',
        'LOG_DEPRECATIONS_CHANNEL' => 'null',
        'LOG_LEVEL' => 'debug',
        'VIEW_COMPILED_PATH' => $compiledViewPath,
        'DB_CONNECTION' => 'mysql',
        'DB_HOST' => (string) ($database['host'] ?? '127.0.0.1'),
        'DB_PORT' => (string) ($database['port'] ?? 3306),
        'DB_DATABASE' => (string) ($database['database'] ?? 'pbb_hotline'),
        'DB_USERNAME' => (string) ($database['username'] ?? 'root'),
        'DB_PASSWORD' => (string) ($database['password'] ?? ''),
        'SESSION_DRIVER' => 'database',
        'SESSION_LIFETIME' => $normalSessionLifetime,
        'SESSION_ENCRYPT' => 'false',
        'SESSION_COOKIE' => 'pbb_hotline_session',
        'SESSION_PATH' => '/',
        'SESSION_DOMAIN' => $sessionDomain,
        'HOTLINE_SESSION_DRIVER' => 'database',
        'HOTLINE_SESSION_LIFETIME' => $normalSessionLifetime,
        'HOTLINE_CRITICAL_SESSION_LIFETIME' => $citizenSessionLifetime,
        'HOTLINE_CITIZEN_SESSION_LIFETIME' => $citizenSessionLifetime,
        'HOTLINE_SESSION_ENCRYPT' => 'false',
        'HOTLINE_SESSION_COOKIE' => 'pbb_hotline_session',
        'HOTLINE_SESSION_PATH' => '/',
        'HOTLINE_SESSION_DOMAIN' => $sessionDomain,
        'BROADCAST_CONNECTION' => 'log',
        'FILESYSTEM_DISK' => 'local',
        'QUEUE_CONNECTION' => 'database',
        'CACHE_STORE' => 'database',
        'MAIL_MAILER' => 'log',
        'MAIL_SCHEME' => 'null',
        'MAIL_HOST' => '127.0.0.1',
        'MAIL_PORT' => '2525',
        'MAIL_USERNAME' => 'null',
        'MAIL_PASSWORD' => 'null',
        'MAIL_FROM_ADDRESS' => 'hello@example.com',
        'MAIL_FROM_NAME' => '${APP_NAME}',
        'AWS_ACCESS_KEY_ID' => '',
        'AWS_SECRET_ACCESS_KEY' => '',
        'AWS_DEFAULT_REGION' => 'us-east-1',
        'AWS_BUCKET' => '',
        'AWS_USE_PATH_STYLE_ENDPOINT' => 'false',
        'VITE_APP_NAME' => '${APP_NAME}',
        'VITE_APP_URL' => '${APP_URL}',
        'MEDIA_ASSEMBLY_TOKEN' => (string) ($hotline['media_assembly_token'] ?? ''),
        'HOTLINE_FFMPEG_BINARY' => $ffmpegBinary,
        'HOTLINE_FFPROBE_BINARY' => $ffprobeBinary,
        'HOTLINE_REALTIME_CA_BUNDLE' => $realtimeCaBundle,
        'SITREP_NODE_BINARY' => (string) ($hotline['sitrep_node_binary'] ?? 'node'),
        'PLAYWRIGHT_BROWSERS_PATH' => (string) ($hotline['playwright_browsers_path'] ?? ($installPath.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'playwright-browsers')),
    ];

    $lines = [];
    foreach ($values as $key => $value) {
        $lines[] = $key.'='.envValue($value);
    }

    return implode(PHP_EOL, $lines).PHP_EOL;
}

function buildInstallManifest(string $root, array $config, string $envPath, ?string $backupPath, array $databaseSetup): array
{
    $servicesDir = $root.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'installer'.DIRECTORY_SEPARATOR.'services';
    $createdPaths = appOwnedCreatedPaths($root);
    $externalPaths = externalReliedOnPaths($root, $config);

    return [
        'schema_version' => 1,
        'app' => 'pbb-hotline',
        'version' => '5.6.1',
        'display_version' => 'v1-5.6.1',
        'installed_at' => gmdate('c'),
        'install_path' => $root,
        'app_url' => (string) dataGet($config, ['app', 'app_url'], ''),
        'env_path' => $envPath,
        'env_backup_path' => $backupPath,
        'filesystem' => [
            'app_root' => $root,
            'created_paths' => $createdPaths,
            'relied_on_external_paths' => $externalPaths,
        ],
        'database_setup' => $databaseSetup,
        'database' => [
            'host' => (string) dataGet($config, ['database', 'host'], ''),
            'port' => (int) dataGet($config, ['database', 'port'], 3306),
            'database' => (string) dataGet($config, ['database', 'database'], ''),
            'setup' => $databaseSetup,
        ],
        'services' => [
            'queue' => 'php artisan queue:work --queue=default --sleep=1 --tries=3 --timeout=90',
            'scheduler' => 'php artisan schedule:run',
            'artifacts_path' => $servicesDir,
        ],
    ];
}

/**
 * @return array<int, array{path: string, content: string}>
 */
function serviceArtifacts(string $root, array $config): array
{
    $servicesDir = $root.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'installer'.DIRECTORY_SEPARATOR.'services';
    $php = PHP_BINARY;
    $installPath = (string) dataGet($config, ['app', 'install_path'], $root);
    $targetOs = strtolower((string) dataGet($config, ['services', 'target_os'], stripos(PHP_OS_FAMILY, 'Windows') === 0 ? 'windows' : 'linux'));
    $artifacts = [];

    if ($targetOs === 'linux') {
        $artifacts[] = [
            'path' => $servicesDir.DIRECTORY_SEPARATOR.'pbb-hotline-queue.service',
            'content' => linuxQueueService($installPath, $php),
        ];
        $artifacts[] = [
            'path' => $servicesDir.DIRECTORY_SEPARATOR.'pbb-hotline-scheduler.service',
            'content' => linuxSchedulerService($installPath, $php),
        ];
        $artifacts[] = [
            'path' => $servicesDir.DIRECTORY_SEPARATOR.'pbb-hotline-scheduler.timer',
            'content' => linuxSchedulerTimer(),
        ];
    } else {
        $artifacts[] = [
            'path' => $servicesDir.DIRECTORY_SEPARATOR.'register-hotline-scheduled-tasks.ps1',
            'content' => windowsScheduledTaskScript($installPath, $php),
        ];
        $artifacts[] = [
            'path' => $servicesDir.DIRECTORY_SEPARATOR.'hotline-service-commands.json',
            'content' => json_encode([
                'schema_version' => 1,
                'manager' => 'scheduled-task',
                'queue' => [
                    'command' => $php,
                    'arguments' => 'artisan queue:work --queue=default --sleep=1 --tries=3 --timeout=90',
                    'working_directory' => $installPath,
                ],
                'scheduler' => [
                    'command' => $php,
                    'arguments' => 'artisan schedule:run',
                    'working_directory' => $installPath,
                    'interval_minutes' => 1,
                ],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL,
        ];
    }

    $artifacts[] = [
        'path' => $servicesDir.DIRECTORY_SEPARATOR.'README.md',
        'content' => serviceArtifactReadme($targetOs),
    ];

    return $artifacts;
}

/**
 * @return list<string>
 */
function appOwnedCreatedPaths(string $root): array
{
    return [
        $root.DIRECTORY_SEPARATOR.'.env',
        $root.DIRECTORY_SEPARATOR.'public'.DIRECTORY_SEPARATOR.'storage',
        $root.DIRECTORY_SEPARATOR.'storage',
        $root.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'app',
        $root.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'installer',
        $root.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'installer'.DIRECTORY_SEPARATOR.'services',
        $root.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'playwright-browsers',
        $root.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'framework',
        $root.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'framework'.DIRECTORY_SEPARATOR.'cache',
        $root.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'framework'.DIRECTORY_SEPARATOR.'cache'.DIRECTORY_SEPARATOR.'data',
        $root.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'framework'.DIRECTORY_SEPARATOR.'sessions',
        $root.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'framework'.DIRECTORY_SEPARATOR.'views',
        $root.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'logs',
        $root.DIRECTORY_SEPARATOR.'bootstrap'.DIRECTORY_SEPARATOR.'cache',
    ];
}

/**
 * @return array<int, array{kind: string, path: string}>
 */
function externalReliedOnPaths(string $root, array $config): array
{
    $paths = [];
    foreach ([
        'ffmpeg' => (string) dataGet($config, ['hotline', 'ffmpeg_binary'], ''),
        'ffprobe' => (string) dataGet($config, ['hotline', 'ffprobe_binary'], ''),
    ] as $kind => $path) {
        $resolved = resolveMediaBinaryForEnv($root, $kind, $path);
        if (trim($resolved) !== '' && ! pathIsWithin($resolved, $root)) {
            $paths[] = [
                'kind' => $kind,
                'path' => $resolved,
            ];
        }
    }

    return $paths;
}

/**
 * @param array<int, array{path: string, content: string}> $artifacts
 */
function writeServiceArtifacts(array $artifacts): void
{
    foreach ($artifacts as $artifact) {
        ensureDirectory(dirname($artifact['path']));
        file_put_contents($artifact['path'], $artifact['content']);
    }
}

function shouldRegisterServices(array $config): bool
{
    return (bool) dataGet($config, ['options', 'register_services'], false)
        || (string) dataGet($config, ['services', 'registration_mode'], 'generate') === 'register';
}

function linuxQueueService(string $installPath, string $php): string
{
    return <<<UNIT
[Unit]
Description=PBB Hotline Queue Worker
After=network.target mysql.service mariadb.service

[Service]
Type=simple
WorkingDirectory={$installPath}
ExecStart={$php} artisan queue:work --queue=default --sleep=1 --tries=3 --timeout=90
Restart=always
RestartSec=5
User=www-data
Group=www-data

[Install]
WantedBy=multi-user.target
UNIT.PHP_EOL;
}

function linuxSchedulerService(string $installPath, string $php): string
{
    return <<<UNIT
[Unit]
Description=PBB Hotline Laravel Scheduler

[Service]
Type=oneshot
WorkingDirectory={$installPath}
ExecStart={$php} artisan schedule:run
User=www-data
Group=www-data
UNIT.PHP_EOL;
}

function linuxSchedulerTimer(): string
{
    return <<<UNIT
[Unit]
Description=Run PBB Hotline Laravel Scheduler every minute

[Timer]
OnBootSec=60
OnUnitActiveSec=60
AccuracySec=1
Unit=pbb-hotline-scheduler.service

[Install]
WantedBy=timers.target
UNIT.PHP_EOL;
}

function windowsScheduledTaskScript(string $installPath, string $php): string
{
    $escapedPhp = str_replace("'", "''", $php);
    $escapedPath = str_replace("'", "''", $installPath);

    return <<<POWERSHELL
\$ErrorActionPreference = 'Stop'
\$php = '{$escapedPhp}'
\$app = '{$escapedPath}'

\$queueAction = New-ScheduledTaskAction -Execute \$php -Argument 'artisan queue:work --queue=default --sleep=1 --tries=3 --timeout=90' -WorkingDirectory \$app
\$queueTrigger = New-ScheduledTaskTrigger -AtStartup
Register-ScheduledTask -TaskName 'PBB Hotline Queue Worker' -Action \$queueAction -Trigger \$queueTrigger -Description 'Runs the PBB Hotline Laravel queue worker.' -Force

\$schedulerAction = New-ScheduledTaskAction -Execute \$php -Argument 'artisan schedule:run' -WorkingDirectory \$app
\$schedulerTrigger = New-ScheduledTaskTrigger -Once -At (Get-Date).Date -RepetitionInterval (New-TimeSpan -Minutes 1) -RepetitionDuration (New-TimeSpan -Days 1)
Register-ScheduledTask -TaskName 'PBB Hotline Scheduler' -Action \$schedulerAction -Trigger \$schedulerTrigger -Description 'Runs the PBB Hotline Laravel scheduler every minute.' -Force
POWERSHELL.PHP_EOL;
}

function serviceArtifactReadme(string $targetOs): string
{
    if ($targetOs === 'linux') {
        return <<<MARKDOWN
# PBB Hotline Service Artifacts

Generated files:

- `pbb-hotline-queue.service`
- `pbb-hotline-scheduler.service`
- `pbb-hotline-scheduler.timer`

Install them with the host's normal systemd workflow, then enable and start the queue service and scheduler timer.
MARKDOWN.PHP_EOL;
    }

    return <<<MARKDOWN
# PBB Hotline Service Artifacts

Generated files:

- `register-hotline-scheduled-tasks.ps1`
- `hotline-service-commands.json`

Run the PowerShell script as an administrator, or let Kit Setup consume the JSON command contract.
MARKDOWN.PHP_EOL;
}

function baselineSchemaPath(string $root): string
{
    return $root.DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'schema'.DIRECTORY_SEPARATOR.'hotline-schema-mysql.sql';
}

function baselineSchemaRelativePath(): string
{
    return 'database/schema/hotline-schema-mysql.sql';
}

/**
 * @return array<string, mixed>
 */
function plannedDatabaseSetup(string $root, array $config): array
{
    if (! (bool) dataGet($config, ['options', 'run_migrations'], true)) {
        return [
            'strategy' => 'skipped',
            'baseline_schema' => baselineSchemaRelativePath(),
            'baseline_schema_used' => false,
            'migration_rows' => 0,
            'upgrade_strategy' => 'laravel_migrations',
        ];
    }

    $schemaPath = baselineSchemaPath($root);
    if (shouldUseBaselineSchema($root, $config)) {
        return [
            'strategy' => 'baseline_schema',
            'baseline_schema' => baselineSchemaRelativePath(),
            'baseline_schema_used' => true,
            'migration_rows' => baselineMigrationRowCount($schemaPath),
            'upgrade_strategy' => 'laravel_migrations',
        ];
    }

    return [
        'strategy' => 'laravel_migrations',
        'baseline_schema' => baselineSchemaRelativePath(),
        'baseline_schema_used' => false,
        'migration_rows' => 0,
        'upgrade_strategy' => 'laravel_migrations',
    ];
}

function shouldUseBaselineSchema(string $root, array $config): bool
{
    $requestedSetup = (string) dataGet($config, ['options', 'database_setup'], '');
    if ($requestedSetup !== '') {
        return $requestedSetup === 'baseline_schema' && file_exists(baselineSchemaPath($root));
    }

    return (bool) dataGet($config, ['options', 'use_baseline_schema'], true) && file_exists(baselineSchemaPath($root));
}

function baselineMigrationRowCount(string $schemaPath): int
{
    if (! file_exists($schemaPath)) {
        return 0;
    }

    $sql = (string) file_get_contents($schemaPath);
    if (! preg_match('/INSERT INTO `migrations` .*?VALUES\s*(.+?);/s', $sql, $match)) {
        return 0;
    }

    preg_match_all('/\(\s*\'[^\']+\'\s*,\s*\d+\s*\)/', $match[1], $rows);

    return count($rows[0]);
}

/**
 * @return array<string, mixed>
 */
function actionPlanMetadata(array $command): array
{
    if (($command['type'] ?? '') === 'baseline_schema') {
        return [
            'database_setup' => [
                'strategy' => 'baseline_schema',
                'baseline_schema' => $command['artifact'],
                'baseline_schema_used' => true,
                'migration_rows' => baselineMigrationRowCount((string) $command['path']),
                'upgrade_strategy' => 'laravel_migrations',
            ],
            'artifact' => $command['artifact'],
        ];
    }

    return [
        'command' => displayCommand($command['argv']),
    ];
}

/**
 * @return array<int, array<string, mixed>>
 */
function freshInstallCommands(string $root, array $config): array
{
    $commands = [];

    if ((bool) dataGet($config, ['options', 'run_migrations'], true)) {
        $schemaPath = baselineSchemaPath($root);
        if (shouldUseBaselineSchema($root, $config)) {
            $commands[] = [
                'id' => 'apply_baseline_schema',
                'type' => 'baseline_schema',
                'path' => $schemaPath,
                'artifact' => baselineSchemaRelativePath(),
            ];
        } else {
            $commands[] = [
                'id' => 'artisan_migrate',
                'argv' => [PHP_BINARY, 'artisan', 'migrate', '--force'],
            ];
        }
    }

    if ((bool) dataGet($config, ['options', 'seed_settings'], true)) {
        $commands[] = [
            'id' => 'artisan_seed_settings',
            'argv' => [PHP_BINARY, 'artisan', 'db:seed', '--class=SettingsSeeder', '--force'],
        ];
    }

    if ((bool) dataGet($config, ['options', 'create_admin'], true)) {
        $commands[] = [
            'id' => 'bootstrap_runtime',
            'argv' => [PHP_BINARY, 'installer/bootstrap-runtime.php', '--config', (string) dataGet($config, ['_config_path'], '')],
        ];
    }

    $commands[] = [
        'id' => 'artisan_storage_link',
        'argv' => [PHP_BINARY, 'artisan', 'storage:link', '--force'],
    ];

    if ((bool) dataGet($config, ['options', 'cache_config'], true)) {
        $commands[] = [
            'id' => 'artisan_config_cache',
            'argv' => [PHP_BINARY, 'artisan', 'config:cache'],
        ];
        $commands[] = [
            'id' => 'artisan_route_cache',
            'argv' => [PHP_BINARY, 'artisan', 'route:cache'],
        ];
        $commands[] = [
            'id' => 'artisan_view_cache',
            'argv' => [PHP_BINARY, 'artisan', 'view:cache'],
        ];
    }

    return $commands;
}

/**
 * @param array<int, string> $argv
 */
function displayCommand(array $argv): string
{
    $display = $argv;

    if (isset($display[0]) && $display[0] === PHP_BINARY) {
        $display[0] = 'php';
    }

    return implode(' ', array_map(static function (string $part): string {
        return preg_match('/\s/', $part) ? '"'.$part.'"' : $part;
    }, $display));
}

/**
 * @return array<string, mixed>
 */
function runInstallCommand(string $root, array $config, array $command): array
{
    if (($command['type'] ?? '') === 'baseline_schema') {
        return applyBaselineSchema($config, (string) $command['path'], (string) $command['artifact']);
    }

    return runCommand($root, $command['argv']);
}

/**
 * @return array<string, mixed>
 */
function applyBaselineSchema(array $config, string $schemaPath, string $artifact): array
{
    if (! file_exists($schemaPath)) {
        return [
            'status' => 'failed',
            'database_setup' => [
                'strategy' => 'baseline_schema',
                'baseline_schema' => $artifact,
                'baseline_schema_used' => false,
                'migration_rows' => 0,
                'upgrade_strategy' => 'laravel_migrations',
            ],
            'artifact' => $artifact,
            'message' => 'Baseline schema artifact is missing.',
        ];
    }

    try {
        $pdo = installerPdo($config['database'] ?? []);
        $existingTables = databaseTableCount($pdo, (string) dataGet($config, ['database', 'database'], ''));

        if ($existingTables > 0) {
            return [
                'status' => 'failed',
                'database_setup' => [
                    'strategy' => 'baseline_schema',
                    'baseline_schema' => $artifact,
                    'baseline_schema_used' => false,
                    'migration_rows' => 0,
                    'upgrade_strategy' => 'laravel_migrations',
                ],
                'artifact' => $artifact,
                'existing_tables' => $existingTables,
                'message' => 'Fresh baseline schema install requires an empty app database.',
            ];
        }

        $sql = (string) file_get_contents($schemaPath);
        foreach (splitSqlStatements($sql) as $statement) {
            $pdo->exec($statement);
        }

        $migrationRows = migrationRowCount($pdo);

        return [
            'status' => 'success',
            'database_setup' => [
                'strategy' => 'baseline_schema',
                'baseline_schema' => $artifact,
                'baseline_schema_used' => true,
                'migration_rows' => $migrationRows,
                'upgrade_strategy' => 'laravel_migrations',
            ],
            'artifact' => $artifact,
            'migration_rows' => $migrationRows,
        ];
    } catch (Throwable $exception) {
        return [
            'status' => 'failed',
            'database_setup' => [
                'strategy' => 'baseline_schema',
                'baseline_schema' => $artifact,
                'baseline_schema_used' => false,
                'migration_rows' => 0,
                'upgrade_strategy' => 'laravel_migrations',
            ],
            'artifact' => $artifact,
            'message' => $exception->getMessage(),
        ];
    }
}

function installerPdo(array $database): PDO
{
    $host = (string) ($database['host'] ?? '');
    $port = (int) ($database['port'] ?? 3306);
    $name = (string) ($database['database'] ?? '');
    $username = (string) ($database['username'] ?? '');
    $password = (string) ($database['password'] ?? '');

    return new PDO(
        sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $name),
        $username,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ],
    );
}

function databaseTableCount(PDO $pdo, string $database): int
{
    $statement = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ?');
    $statement->execute([$database]);

    return (int) $statement->fetchColumn();
}

function migrationRowCount(PDO $pdo): int
{
    try {
        return (int) $pdo->query('SELECT COUNT(*) FROM migrations')->fetchColumn();
    } catch (Throwable) {
        return 0;
    }
}

/**
 * @return list<string>
 */
function splitSqlStatements(string $sql): array
{
    $statements = preg_split('/;\s*(?:\r?\n|$)/', $sql);

    return array_values(array_filter(array_map('trim', $statements), static fn (string $statement): bool => $statement !== ''));
}

/**
 * @param array<int, string> $argv
 * @return array{status: string, exit_code: int, stdout: string, stderr: string}
 */
function runCommand(string $cwd, array $argv): array
{
    $command = implode(' ', array_map('escapeshellarg', $argv));
    $descriptorSpec = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $process = proc_open($command, $descriptorSpec, $pipes, $cwd);

    if (! is_resource($process)) {
        return [
            'status' => 'failed',
            'exit_code' => 127,
            'stdout' => '',
            'stderr' => 'Unable to start process.',
        ];
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    return [
        'status' => $exitCode === 0 ? 'success' : 'failed',
        'exit_code' => $exitCode,
        'stdout' => trim((string) $stdout),
        'stderr' => trim((string) $stderr),
    ];
}

/**
 * @return list<string>
 */
function postInstallHealthCheckIds(): array
{
    return [
        'health_storage_write',
        'health_artisan_about',
        'health_queue_failed_command',
        'health_schedule_list_command',
        'health_media_ffmpeg',
        'health_media_ffprobe',
        'health_http_up',
        'health_http_bootstrap',
    ];
}

/**
 * @return array<int, array<string, mixed>>
 */
function runPostInstallHealthChecks(string $root, array $config): array
{
    $checks = [];
    $checks[] = checkStorageWrite($root);
    $checks[] = commandHealthCheck($root, 'health_artisan_about', [PHP_BINARY, 'artisan', 'about', '--only=environment']);
    $checks[] = commandHealthCheck($root, 'health_queue_failed_command', [PHP_BINARY, 'artisan', 'queue:failed']);
    $checks[] = commandHealthCheck($root, 'health_schedule_list_command', [PHP_BINARY, 'artisan', 'schedule:list']);
    $checks[] = normalizeHealthId(checkMediaBinary($root, 'ffmpeg', (string) dataGet($config, ['hotline', 'ffmpeg_binary'], '')), 'health_media_ffmpeg');
    $checks[] = normalizeHealthId(checkMediaBinary($root, 'ffprobe', (string) dataGet($config, ['hotline', 'ffprobe_binary'], ''), false), 'health_media_ffprobe');

    $appUrl = rtrim((string) dataGet($config, ['app', 'app_url'], ''), '/');
    if ($appUrl === '') {
        $checks[] = [
            'id' => 'health_http_up',
            'status' => 'fail',
            'message' => 'app.app_url is empty.',
        ];
        $checks[] = [
            'id' => 'health_http_bootstrap',
            'status' => 'fail',
            'message' => 'app.app_url is empty.',
        ];
    } else {
        $checks[] = checkHttpEndpoint('health_http_up', $appUrl.'/up');
        $checks[] = checkHttpEndpoint('health_http_bootstrap', $appUrl.'/api/bootstrap?surface=public');
    }

    return $checks;
}

function checkStorageWrite(string $root): array
{
    $path = $root.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'installer'.DIRECTORY_SEPARATOR.'healthcheck.tmp';

    try {
        ensureDirectory(dirname($path));
        file_put_contents($path, 'ok');
        $contents = file_get_contents($path);
        @unlink($path);

        return [
            'id' => 'health_storage_write',
            'status' => $contents === 'ok' ? 'pass' : 'fail',
            'path' => $path,
        ];
    } catch (Throwable $exception) {
        return [
            'id' => 'health_storage_write',
            'status' => 'fail',
            'path' => $path,
            'message' => $exception->getMessage(),
        ];
    }
}

function commandHealthCheck(string $root, string $id, array $argv): array
{
    $result = runCommand($root, $argv);

    return [
        'id' => $id,
        'status' => $result['status'] === 'success' ? 'pass' : 'fail',
        'command' => displayCommand($argv),
        'exit_code' => $result['exit_code'],
        'stdout' => $result['stdout'],
        'stderr' => $result['stderr'],
    ];
}

function checkHttpEndpoint(string $id, string $url): array
{
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 8,
            'ignore_errors' => true,
            'header' => "Accept: application/json\r\nUser-Agent: pbb-hotline-installer/1.0\r\n",
        ],
    ]);

    $body = @file_get_contents($url, false, $context);
    $statusCode = httpStatusCode($http_response_header ?? []);

    return [
        'id' => $id,
        'status' => $statusCode >= 200 && $statusCode < 400 ? 'pass' : 'fail',
        'url' => $url,
        'http_status' => $statusCode,
        'body_excerpt' => $body === false ? null : substr(trim((string) $body), 0, 300),
    ];
}

/**
 * @param array<int, string> $headers
 */
function httpStatusCode(array $headers): int
{
    foreach ($headers as $header) {
        if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $header, $matches)) {
            return (int) $matches[1];
        }
    }

    return 0;
}

function normalizeHealthId(array $check, string $id): array
{
    $check['id'] = $id;
    $check['status'] = ($check['status'] ?? null) === 'pass' ? 'pass' : 'fail';

    return $check;
}

function generateAppKey(): string
{
    return 'base64:'.base64_encode(random_bytes(32));
}

function envValue(string $value): string
{
    if ($value === '' || preg_match('/^[A-Za-z0-9_.,:\/@{}${}-]+$/', $value)) {
        return $value;
    }

    return '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"';
}

function boolString(bool $value): string
{
    return $value ? 'true' : 'false';
}

function ensureDirectory(string $path): void
{
    if (! is_dir($path)) {
        mkdir($path, 0777, true);
    }
}

function pathsEqual(string $left, string $right): bool
{
    return normalizePath($left) === normalizePath($right);
}

function pathIsWithin(string $path, string $root): bool
{
    $normalizedPath = normalizePath($path);
    $normalizedRoot = normalizePath($root);

    return $normalizedPath === $normalizedRoot || str_starts_with($normalizedPath, $normalizedRoot.DIRECTORY_SEPARATOR);
}

function normalizePath(string $path): string
{
    $path = trim($path);
    if ($path === '') {
        return '';
    }

    $real = realpath($path);
    if ($real !== false) {
        $path = $real;
    }

    $path = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
    $path = rtrim($path, DIRECTORY_SEPARATOR);

    if (DIRECTORY_SEPARATOR === '\\') {
        $path = strtolower($path);
    }

    return $path;
}

/**
 * @param array<int, array<string, mixed>> $actions
 * @return array<int, array<string, mixed>>
 */
function markAction(array $actions, string $id, string $status, array $details = []): array
{
    foreach ($actions as $index => $action) {
        if (($action['id'] ?? null) === $id) {
            $actions[$index]['status'] = $status;
            if ($details !== []) {
                $actions[$index]['result'] = $details;
            }
        }
    }

    return $actions;
}

function commandExists(string $command): bool
{
    if (trim($command) === '') {
        return false;
    }

    if (preg_match('/[\/\\\\]/', $command)) {
        return is_file($command);
    }

    $probe = stripos(PHP_OS_FAMILY, 'Windows') === 0
        ? 'where '.escapeshellarg($command)
        : 'command -v '.escapeshellarg($command);
    $output = [];
    $exitCode = 1;
    @exec($probe, $output, $exitCode);

    return $exitCode === 0;
}

function isPlaceholderSecret(string $value): bool
{
    $trimmed = trim($value);

    return $trimmed === ''
        || stripos($trimmed, 'replace-with') !== false
        || stripos($trimmed, 'changeme') !== false
        || stripos($trimmed, 'placeholder') !== false
        || stripos($trimmed, 'password') === 0;
}

function isStrongAdminPassword(string $value): bool
{
    return ! isPlaceholderSecret($value)
        && strlen($value) >= 12
        && preg_match('/[a-z]/', $value) === 1
        && preg_match('/[A-Z]/', $value) === 1
        && preg_match('/[0-9]/', $value) === 1;
}

/**
 * @return array{config: array<string, mixed>, check: array<string, mixed>|null}
 */
function loadConfig(?string $path): array
{
    if ($path === null || trim($path) === '') {
        return [
            'config' => [],
            'check' => null,
        ];
    }

    if (! is_file($path)) {
        return [
            'config' => [],
            'check' => [
                'id' => 'config_file',
                'path' => $path,
                'status' => 'fail',
                'message' => 'Config file does not exist.',
            ],
        ];
    }

    $raw = file_get_contents($path);
    $decoded = json_decode((string) $raw, true);

    if (! is_array($decoded)) {
        return [
            'config' => [],
            'check' => [
                'id' => 'config_file',
                'path' => $path,
                'status' => 'fail',
                'message' => 'Config file is not valid JSON.',
            ],
        ];
    }

    return [
        'config' => $decoded + ['_config_path' => $path],
        'check' => [
            'id' => 'config_file',
            'path' => $path,
            'status' => 'pass',
        ],
    ];
}

function optionValue(array $argv, string $name): ?string
{
    foreach ($argv as $index => $arg) {
        if ($arg === $name) {
            return isset($argv[$index + 1]) ? (string) $argv[$index + 1] : null;
        }

        if (str_starts_with($arg, $name.'=')) {
            return substr($arg, strlen($name) + 1);
        }
    }

    return null;
}

function dataGet(array $data, array $path, mixed $default = null): mixed
{
    $cursor = $data;
    foreach ($path as $segment) {
        if (! is_array($cursor) || ! array_key_exists($segment, $cursor)) {
            return $default;
        }
        $cursor = $cursor[$segment];
    }

    return $cursor;
}

function hasFailedChecks(array $checks): bool
{
    foreach ($checks as $check) {
        if (($check['status'] ?? null) === 'fail') {
            return true;
        }
    }

    return false;
}

function emitJson(array $payload, ?string $path): void
{
    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;

    if ($path !== null && $path !== '') {
        $directory = dirname($path);
        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }
        file_put_contents($path, $json);
    }

    echo $json;
}

function writeCanonicalInstallReport(string $root, array $report): void
{
    $path = $root.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'installer'.DIRECTORY_SEPARATOR.'install-report.json';
    ensureDirectory(dirname($path));
    file_put_contents($path, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
}
