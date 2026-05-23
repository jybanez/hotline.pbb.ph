<?php

declare(strict_types=1);

$rootPath = dirname(__DIR__, 2);
$startedAt = gmdate('c');
$mode = optionValue($argv, '--mode') ?? 'initial';
$configPath = optionValue($argv, '--config');
$reportPath = optionValue($argv, '--report');
$dryRun = in_array('--dry-run', $argv, true);

$expectedMinimums = [
    'incident_categories' => 9,
    'incident_types' => 23,
    'incident_type_fields' => 26,
    'resource_type_categories' => 14,
    'resource_types' => 38,
    'incident_type_default_resources' => 64,
    'team_categories' => 6,
    'teams' => 8,
    'team_resource_inventories' => 27,
];

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
        $pdo = connectPdo($config, $rootPath);

        foreach ($expectedMinimums as $table => $minimum) {
            $count = tableCount($pdo, $table);
            $passed = $count >= $minimum;
            if (! $passed) {
                $errors[] = $table.' has '.$count.' record(s); expected at least '.$minimum.'.';
            }

            $results[] = [
                'id' => $table,
                'type' => 'reference_table',
                'status' => $passed ? 'success' : 'failed',
                'count' => $count,
                'expected_minimum' => $minimum,
            ];
        }

        $expectedSettings = expectedRealtimeSettings($config);
        if ($expectedSettings !== []) {
            $actualSettings = currentSettings($pdo, array_keys($expectedSettings));
            $settingErrors = [];

            foreach ($expectedSettings as $key => $expected) {
                $actual = $actualSettings[$key] ?? null;
                $passed = $actual === $expected;
                if (! $passed) {
                    $settingErrors[] = $key;
                    $errors[] = $key.' is '.json_encode($actual).' but expected '.json_encode($expected).'.';
                }

                $results[] = [
                    'id' => $key,
                    'type' => 'runtime_setting',
                    'status' => $passed ? 'success' : 'failed',
                    'configured' => $actual !== null && trim((string) $actual) !== '',
                    'matches_expected' => $passed,
                ];
            }

            $outputs[] = [
                'id' => 'hotline_realtime_runtime_settings',
                'kind' => 'client_settings',
                'target_app' => 'pbb-hotline',
                'status' => $settingErrors === [] ? 'verified' : 'failed',
                'checked_keys' => array_keys($expectedSettings),
                'failed_keys' => $settingErrors,
                'media_ingest_project_code' => $expectedSettings['realtime_project_code_media_ingest'] ?? null,
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
    'tool' => 'data_prep_verify',
    'mode' => $mode,
    'dry_run' => $dryRun,
    'status' => $status,
    'summary' => $status === 'success'
        ? 'Hotline reference data verification passed.'
        : 'Hotline reference data verification failed.',
    'started_at' => $startedAt,
    'finished_at' => gmdate('c'),
    'sources' => [
        [
            'id' => 'packaged_reference_data',
            'path' => 'resources/data/hotline/reference-data.json',
        ],
    ],
    'results' => $results,
    'outputs' => $outputs,
    'warnings' => $warnings,
    'errors' => $errors,
];

emitJson($report, $reportPath);

exit($status === 'success' ? 0 : 1);

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
            'message' => 'Config file is required for Hotline Data Prep verification.',
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
        throw new RuntimeException('Hotline Data Prep verification only supports mysql database configs.');
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

function tableCount(PDO $pdo, string $table): int
{
    $statement = $pdo->query('SELECT COUNT(*) FROM `'.$table.'`');

    return (int) $statement->fetchColumn();
}

function expectedRealtimeSettings(array $config): array
{
    $verify = dataGet($config, 'hotline.data_prep.verify');
    $apply = dataGet($config, 'hotline.data_prep.apply_settings');
    $hasApplySettings = is_array($apply) && $apply !== [];
    $require = is_array($verify) && array_key_exists('require_realtime_settings', $verify)
        ? boolValue($verify['require_realtime_settings'])
        : $hasApplySettings;

    if (! $require) {
        return [];
    }

    $realtime = [];
    foreach ([
        dataGet($config, 'realtime'),
        dataGet($config, 'dependencies.realtime'),
        dataGet($config, 'data_prep.apply_settings.realtime'),
        dataGet($config, 'hotline.data_prep.realtime'),
        dataGet($config, 'hotline.data_prep.apply_settings.realtime'),
        $apply,
    ] as $candidate) {
        if (is_array($candidate)) {
            $realtime = array_merge($realtime, $candidate);
        }
    }

    $hotline = is_array(dataGet($config, 'hotline')) ? dataGet($config, 'hotline') : [];
    $serverCode = firstString([
        $realtime['project_code_server'] ?? null,
        $realtime['server_project_code'] ?? null,
        $realtime['project_codes']['server'] ?? null,
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
    ];
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

function firstString(array $values): ?string
{
    foreach ($values as $value) {
        if (is_string($value) && trim($value) !== '') {
            return trim($value);
        }
    }

    return null;
}

function boolValue(mixed $value): bool
{
    if (is_bool($value)) {
        return $value;
    }

    return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
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
