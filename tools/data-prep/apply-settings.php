<?php

declare(strict_types=1);

$rootPath = dirname(__DIR__, 2);
$startedAt = gmdate('c');
$mode = optionValue($argv, '--mode') ?? 'initial';
$configPath = optionValue($argv, '--config');
$reportPath = optionValue($argv, '--report');
$dryRun = in_array('--dry-run', $argv, true);

$warnings = [];
$errors = [];
$results = [];
$outputs = [];

$configResult = loadConfig($configPath);
if ($configResult['status'] === 'failed') {
    $errors[] = $configResult['message'];
    $config = [];
} else {
    $config = $configResult['config'];
}

if ($errors === []) {
    try {
        $settings = resolveRealtimeSettings($config);
        $validationErrors = validateRealtimeSettings($settings);
        $errors = array_merge($errors, $validationErrors);

        if ($errors === []) {
            $pdo = connectPdo($config, $rootPath);
            $existing = currentSettings($pdo, array_keys($settings));
            $changedKeys = [];

            foreach ($settings as $key => $value) {
                if (($existing[$key] ?? null) !== $value) {
                    $changedKeys[] = $key;
                }
            }

            if (! $dryRun) {
                foreach ($settings as $key => $value) {
                    upsertSetting($pdo, $key, $value);
                }
            }

            $results[] = [
                'id' => 'hotline_realtime_settings',
                'type' => 'app_settings',
                'action' => $changedKeys === [] ? 'noop' : 'upsert',
                'status' => 'success',
                'changed_keys' => $changedKeys,
                'changed' => count($changedKeys),
                'secret_keys_supplied' => suppliedSecretKeys($settings),
                'media_ingest_project_code' => $settings['realtime_project_code_media_ingest'],
            ];

            $outputs[] = [
                'id' => 'hotline_realtime_runtime_settings',
                'kind' => 'client_settings',
                'target_app' => 'pbb-hotline',
                'status' => $dryRun ? 'planned' : 'applied',
                'realtime_url' => $settings['realtime_url'],
                'realtime_client_code' => $settings['realtime_client_code'],
                'project_codes' => [
                    'server' => $settings['realtime_project_code_server'],
                    'caller' => $settings['realtime_project_code_caller'],
                    'operator' => $settings['realtime_project_code_operator'],
                    'command' => $settings['realtime_project_code_command'],
                    'media_ingest' => $settings['realtime_project_code_media_ingest'],
                ],
                'secrets_configured' => [
                    'backend_ingress' => trim((string) $settings['realtime_backend_ingress_secret']) !== '',
                    'media_ingest' => trim((string) $settings['realtime_media_ingest_secret']) !== '',
                    'token_signing' => trim((string) $settings['realtime_token_signing_secret']) !== '',
                ],
            ];
        }
    } catch (Throwable $exception) {
        $errors[] = $exception->getMessage();
    }
}

$status = $errors === [] ? 'success' : 'failed';

$report = [
    'schema_version' => 1,
    'app' => 'pbb-hotline',
    'tool' => 'data_prep_apply_settings',
    'mode' => $mode,
    'dry_run' => $dryRun,
    'status' => $status,
    'summary' => $status === 'success'
        ? ($dryRun ? 'Hotline Realtime settings planned.' : 'Hotline Realtime settings applied.')
        : 'Hotline Realtime settings failed.',
    'started_at' => $startedAt,
    'finished_at' => gmdate('c'),
    'sources' => [
        [
            'id' => 'runtime_realtime_settings',
            'kind' => 'runtime_config',
        ],
    ],
    'results' => $results,
    'outputs' => $outputs,
    'warnings' => $warnings,
    'errors' => $errors,
];

emitJson($report, $reportPath);

exit($status === 'success' ? 0 : 1);

function resolveRealtimeSettings(array $config): array
{
    $realtime = [];
    foreach ([
        dataGet($config, 'realtime'),
        dataGet($config, 'dependencies.realtime'),
        dataGet($config, 'data_prep.apply_settings.realtime'),
        dataGet($config, 'hotline.data_prep.realtime'),
        dataGet($config, 'hotline.data_prep.apply_settings.realtime'),
        dataGet($config, 'hotline.data_prep.apply_settings'),
    ] as $candidate) {
        if (is_array($candidate)) {
            $realtime = array_merge($realtime, $candidate);
        }
    }

    $hotline = is_array(dataGet($config, 'hotline')) ? dataGet($config, 'hotline') : [];
    $secrets = is_array(dataGet($config, 'secrets.values')) ? dataGet($config, 'secrets.values') : [];

    $serverCode = firstString([
        $realtime['project_code_server'] ?? null,
        $realtime['server_project_code'] ?? null,
        $realtime['project_codes']['server'] ?? null,
        dataGet($config, 'realtime.data_prep.outputs.hotline.project_codes.server'),
    ]) ?? 'prj_HOTLINE_SERVER';
    $operatorCode = firstString([
        $realtime['project_code_operator'] ?? null,
        $realtime['operator_project_code'] ?? null,
        $realtime['project_codes']['operator'] ?? null,
        $hotline['realtime_project_code_operator'] ?? null,
    ]) ?? 'prj_HOTLINE_OPERATOR';

    return [
        'realtime_url' => firstString([
            $realtime['base_url'] ?? null,
            $realtime['app_url'] ?? null,
            $realtime['url'] ?? null,
            $hotline['realtime_url'] ?? null,
        ]) ?? 'https://realtime.pbb.ph',
        'realtime_client_code' => firstString([
            $realtime['client_code'] ?? null,
            $hotline['realtime_client_code'] ?? null,
        ]) ?? 'clt_PBB_HOTLINE',
        'realtime_project_code_server' => $serverCode,
        'realtime_project_code_caller' => firstString([
            $realtime['project_code_caller'] ?? null,
            $realtime['project_code_citizen'] ?? null,
            $realtime['citizen_project_code'] ?? null,
            $realtime['project_codes']['caller'] ?? null,
            $realtime['project_codes']['citizen'] ?? null,
            $hotline['realtime_project_code_caller'] ?? null,
        ]) ?? 'prj_HOTLINE_CITIZEN',
        'realtime_project_code_operator' => $operatorCode,
        'realtime_project_code_command' => firstString([
            $realtime['project_code_command'] ?? null,
            $realtime['command_project_code'] ?? null,
            $realtime['project_codes']['command'] ?? null,
            $hotline['realtime_project_code_command'] ?? null,
        ]) ?? 'prj_HOTLINE_COMMAND',
        'realtime_project_code_media_ingest' => firstString([
            $realtime['project_code_media_ingest'] ?? null,
            $realtime['media_ingest_project_code'] ?? null,
            $realtime['project_codes']['media_ingest'] ?? null,
            $hotline['realtime_project_code_media_ingest'] ?? null,
        ]) ?? $operatorCode,
        'realtime_backend_ingress_secret' => firstString([
            $realtime['backend_ingress_secret'] ?? null,
            $hotline['realtime_backend_ingress_secret'] ?? null,
            $secrets['hotline_realtime_backend_ingress_secret'] ?? null,
            $secrets['realtime_backend_ingress_secret'] ?? null,
        ]) ?? '',
        'realtime_media_ingest_secret' => firstString([
            $realtime['media_ingest_secret'] ?? null,
            $hotline['realtime_media_ingest_secret'] ?? null,
            $secrets['hotline_realtime_media_ingest_secret'] ?? null,
            $secrets['realtime_media_ingest_secret'] ?? null,
        ]) ?? '',
        'realtime_token_signing_secret' => firstString([
            $realtime['token_signing_secret'] ?? null,
            $hotline['realtime_token_signing_secret'] ?? null,
            $secrets['hotline_realtime_token_signing_secret'] ?? null,
            $secrets['realtime_token_signing_secret'] ?? null,
        ]) ?? '',
    ];
}

function validateRealtimeSettings(array $settings): array
{
    $errors = [];

    foreach ([
        'realtime_url',
        'realtime_client_code',
        'realtime_project_code_server',
        'realtime_project_code_caller',
        'realtime_project_code_operator',
        'realtime_project_code_command',
        'realtime_project_code_media_ingest',
    ] as $key) {
        if (trim((string) ($settings[$key] ?? '')) === '') {
            $errors[] = 'Hotline Data Prep apply settings requires '.$key.'.';
        }
    }

    foreach ([
        'realtime_backend_ingress_secret',
        'realtime_media_ingest_secret',
        'realtime_token_signing_secret',
    ] as $key) {
        if (trim((string) ($settings[$key] ?? '')) === '') {
            $errors[] = 'Hotline Data Prep apply settings requires '.$key.'.';
        }
    }

    if (filter_var((string) $settings['realtime_url'], FILTER_VALIDATE_URL) === false) {
        $errors[] = 'Hotline Data Prep apply settings requires realtime_url to be a valid URL.';
    }

    return $errors;
}

function suppliedSecretKeys(array $settings): array
{
    return array_values(array_filter([
        trim((string) $settings['realtime_backend_ingress_secret']) !== '' ? 'realtime_backend_ingress_secret' : null,
        trim((string) $settings['realtime_media_ingest_secret']) !== '' ? 'realtime_media_ingest_secret' : null,
        trim((string) $settings['realtime_token_signing_secret']) !== '' ? 'realtime_token_signing_secret' : null,
    ]));
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

function loadConfig(?string $path): array
{
    if ($path === null || trim($path) === '') {
        return [
            'status' => 'failed',
            'message' => 'Config file is required for Hotline Data Prep apply settings.',
            'config' => [],
        ];
    }

    if (! is_file($path)) {
        return [
            'status' => 'failed',
            'message' => 'Config file was not found: '.$path,
            'config' => [],
        ];
    }

    $json = file_get_contents($path);
    if ($json === false) {
        return [
            'status' => 'failed',
            'message' => 'Config file could not be read: '.$path,
            'config' => [],
        ];
    }

    $config = json_decode($json, true);
    if (! is_array($config)) {
        return [
            'status' => 'failed',
            'message' => 'Config file is not valid JSON: '.json_last_error_msg(),
            'config' => [],
        ];
    }

    return [
        'status' => 'passed',
        'message' => 'Config file loaded.',
        'config' => $config,
    ];
}

function connectPdo(array $config, string $rootPath): PDO
{
    $database = is_array($config['database'] ?? null) ? $config['database'] : databaseConfigFromEnv($rootPath.DIRECTORY_SEPARATOR.'.env');

    foreach (['host', 'database', 'username'] as $field) {
        if (trim((string) ($database[$field] ?? '')) === '') {
            throw new RuntimeException('Database config is missing required field database.'.$field.'.');
        }
    }

    $driver = (string) ($database['driver'] ?? 'mysql');
    if ($driver !== 'mysql') {
        throw new RuntimeException('Hotline Data Prep apply settings only supports mysql database configs.');
    }

    return new PDO(
        'mysql:host='.(string) $database['host'].';port='.(int) ($database['port'] ?? 3306).';dbname='.(string) $database['database'].';charset=utf8mb4',
        (string) $database['username'],
        (string) ($database['password'] ?? ''),
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
}

function currentSettings(PDO $pdo, array $keys): array
{
    if ($keys === []) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($keys), '?'));
    $statement = $pdo->prepare('SELECT `key`, `value` FROM `settings` WHERE `key` IN ('.$placeholders.')');
    $statement->execute(array_values($keys));

    $settings = [];
    foreach ($statement->fetchAll() as $row) {
        $decoded = json_decode((string) $row['value'], true);
        $settings[(string) $row['key']] = is_array($decoded) ? ($decoded['value'] ?? null) : null;
    }

    return $settings;
}

function upsertSetting(PDO $pdo, string $key, mixed $value): void
{
    $payload = json_encode(['value' => $value], JSON_UNESCAPED_SLASHES);
    if ($payload === false) {
        throw new RuntimeException('Unable to encode setting '.$key.'.');
    }

    $statement = $pdo->prepare(
        'INSERT INTO `settings` (`key`, `value`, `created_at`, `updated_at`) VALUES (?, ?, NOW(), NOW()) '
        .'ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), `updated_at` = NOW()'
    );
    $statement->execute([$key, $payload]);
}

function databaseConfigFromEnv(string $path): array
{
    if (! is_file($path)) {
        return [];
    }

    $values = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || str_starts_with($trimmed, '#') || ! str_contains($trimmed, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $trimmed, 2);
        $values[$key] = trim($value, " \t\n\r\0\x0B\"'");
    }

    return [
        'driver' => $values['DB_CONNECTION'] ?? 'mysql',
        'host' => $values['DB_HOST'] ?? null,
        'port' => isset($values['DB_PORT']) ? (int) $values['DB_PORT'] : 3306,
        'database' => $values['DB_DATABASE'] ?? null,
        'username' => $values['DB_USERNAME'] ?? null,
        'password' => $values['DB_PASSWORD'] ?? '',
    ];
}

function firstString(array $values): ?string
{
    foreach ($values as $value) {
        if (is_string($value) && trim($value) !== '') {
            return trim($value);
        }
    }

    return null;
}

function dataGet(array $data, string $path): mixed
{
    $current = $data;
    foreach (explode('.', $path) as $segment) {
        if (! is_array($current) || ! array_key_exists($segment, $current)) {
            return null;
        }
        $current = $current[$segment];
    }

    return $current;
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
