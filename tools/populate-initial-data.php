<?php

declare(strict_types=1);

$rootPath = dirname(__DIR__);
$startedAt = gmdate('c');
$mode = optionValue($argv, '--mode') ?? 'initial';
$configPath = optionValue($argv, '--config');
$reportPath = optionValue($argv, '--report');
$dryRun = in_array('--dry-run', $argv, true);

$managedGroups = [
    'incident_categories',
    'incident_types',
    'incident_type_fields',
    'resource_type_categories',
    'resource_types',
    'incident_type_default_resources',
    'team_categories',
    'teams',
    'team_resource_inventories',
];

$sourceDefinitions = [
    'reference_data' => [
        'label' => 'Hotline reference data',
        'groups' => $managedGroups,
    ],
    'incident_catalog' => [
        'label' => 'incident catalog',
        'groups' => [
            'incident_categories',
            'incident_types',
            'incident_type_fields',
            'incident_type_default_resources',
        ],
    ],
    'resource_catalog' => [
        'label' => 'resource catalog',
        'groups' => [
            'resource_type_categories',
            'resource_types',
        ],
    ],
    'teams' => [
        'label' => 'teams and inventory',
        'groups' => [
            'team_categories',
            'teams',
            'team_resource_inventories',
        ],
    ],
];

$deprecatedSources = [
    'operators' => 'operators are intentionally outside the first Data Prep reference-data scope',
    'dispatch_defaults' => 'dispatch defaults are intentionally outside the first Data Prep reference-data scope',
];

$warnings = [];
$errors = [];
$checks = [];
$actions = [];
$totals = [
    'configured_sources' => 0,
    'available_sources' => 0,
    'missing_sources' => 0,
    'planned_records' => 0,
    'inserted_records' => 0,
    'updated_records' => 0,
    'skipped_existing_records' => 0,
];

$canonical = emptyCanonical($managedGroups);

$configResult = loadConfig($configPath);
if ($configResult['status'] === 'failed') {
    $errors[] = $configResult['message'];
    $checks[] = checkResult('load_config', 'failed', $configResult['message'], ['path' => $configPath]);
    $config = [];
    $populate = [];
} else {
    $config = $configResult['config'];
    $populate = populationConfig($config);
    $checks[] = checkResult('load_config', 'passed', $configResult['message'], ['path' => $configPath]);
}

$enabled = boolValue($populate['enabled'] ?? false);
$sources = is_array($populate['sources'] ?? null) ? $populate['sources'] : [];
$options = is_array($populate['options'] ?? null) ? $populate['options'] : [];
$includeDemoData = boolValue($options['include_demo_data'] ?? false);
$overwriteExisting = boolValue($options['overwrite_existing'] ?? true);
$deactivateMissing = boolValue($options['deactivate_missing'] ?? false);

foreach ($deprecatedSources as $sourceId => $reason) {
    if (isset($sources[$sourceId]) && trim((string) $sources[$sourceId]) !== '') {
        $warnings[] = 'Ignoring deprecated source '.$sourceId.': '.$reason.'.';
        $actions[] = actionResult('ignore_'.$sourceId, 'skipped', 'Deprecated source ignored for this Data Prep scope.', [
            'source' => $sourceId,
            'reason' => $reason,
        ]);
    }
}

if ($mode === 'demo' && ! $includeDemoData) {
    $errors[] = 'Demo population mode requires hotline.populate.options.include_demo_data=true.';
    $checks[] = checkResult('demo_data_guard', 'failed', 'Demo data guard blocked demo mode.');
} else {
    $checks[] = checkResult('demo_data_guard', 'passed', 'Demo data guard passed.', [
        'include_demo_data' => $includeDemoData,
    ]);
}

if ($deactivateMissing) {
    $warnings[] = 'hotline.populate.options.deactivate_missing is accepted but not applied for reference-data imports.';
}

if (! $enabled) {
    $actions[] = actionResult(
        'populate_disabled',
        'skipped',
        'Hotline reference-data population is disabled; no data will be inspected or loaded.'
    );
} else {
    if (! hasConfiguredManagedSource($sources, array_keys($sourceDefinitions))) {
        $sources['reference_data'] = 'resources/data/hotline/reference-data.json';
        $actions[] = actionResult(
            'use_packaged_reference_data',
            'planned',
            'No managed source path was configured; using packaged Hotline reference data.',
            ['path' => $sources['reference_data']]
        );
    }

    foreach ($sourceDefinitions as $sourceId => $definition) {
        $sourcePath = trim((string) ($sources[$sourceId] ?? ''));
        if ($sourcePath === '') {
            continue;
        }

        $totals['configured_sources']++;
        $absolutePath = normalizePath($sourcePath, $rootPath);

        if (! is_file($absolutePath)) {
            $totals['missing_sources']++;
            $warnings[] = 'Configured '.$definition['label'].' source does not exist and was skipped: '.$sourcePath;
            $actions[] = actionResult('inspect_'.$sourceId, 'skipped', 'Configured source was not found.', [
                'source' => $sourceId,
                'path' => $sourcePath,
                'resolved_path' => $absolutePath,
            ]);
            continue;
        }

        $inspection = inspectJsonSource($absolutePath, $definition['groups']);
        if ($inspection['status'] === 'failed') {
            $errors[] = $inspection['message'];
            $actions[] = actionResult('inspect_'.$sourceId, 'failed', $inspection['message'], [
                'source' => $sourceId,
                'path' => $sourcePath,
                'resolved_path' => $absolutePath,
            ]);
            continue;
        }

        foreach ($definition['groups'] as $group) {
            foreach ($inspection['data'][$group] ?? [] as $record) {
                $canonical[$group][] = $record;
            }
        }

        $totals['available_sources']++;
        $totals['planned_records'] += $inspection['total_records'];

        $actions[] = actionResult('inspect_'.$sourceId, 'passed', 'Validated '.$definition['label'].' source.', [
            'source' => $sourceId,
            'path' => $sourcePath,
            'resolved_path' => $absolutePath,
            'record_counts' => $inspection['record_counts'],
            'total_records' => $inspection['total_records'],
        ]);
    }

    if ($totals['available_sources'] === 0 && trim((string) ($sources['reference_data'] ?? '')) === '') {
        $fallbackPath = 'resources/data/hotline/reference-data.json';
        $absolutePath = normalizePath($fallbackPath, $rootPath);
        if (is_file($absolutePath)) {
            $inspection = inspectJsonSource($absolutePath, $sourceDefinitions['reference_data']['groups']);
            if ($inspection['status'] === 'passed') {
                foreach ($sourceDefinitions['reference_data']['groups'] as $group) {
                    foreach ($inspection['data'][$group] ?? [] as $record) {
                        $canonical[$group][] = $record;
                    }
                }

                $totals['configured_sources']++;
                $totals['available_sources']++;
                $totals['planned_records'] += $inspection['total_records'];

                $actions[] = actionResult('use_packaged_reference_data_fallback', 'planned', 'Configured sources were unavailable; using packaged Hotline reference data.', [
                    'source' => 'reference_data',
                    'path' => $fallbackPath,
                    'resolved_path' => $absolutePath,
                    'record_counts' => $inspection['record_counts'],
                    'total_records' => $inspection['total_records'],
                ]);
            }
        }
    }

    if ($totals['available_sources'] > 0) {
        $validation = validateCanonical($canonical);
        $checks = array_merge($checks, $validation['checks']);
        foreach ($validation['warnings'] as $warning) {
            $warnings[] = $warning;
        }
        foreach ($validation['errors'] as $error) {
            $errors[] = $error;
        }

        $actions[] = actionResult(
            'plan_reference_data',
            $dryRun ? 'planned' : 'pending',
            ($dryRun ? 'Dry-run planned' : 'Ready to import').' Hotline reference data.',
            ['record_counts' => groupCounts($canonical), 'total_records' => $totals['planned_records']]
        );
    }
}

if ($enabled && ! $dryRun && $errors === [] && $totals['available_sources'] > 0) {
    $importResult = importReferenceData($config, $rootPath, $canonical, $overwriteExisting);
    $checks = array_merge($checks, $importResult['checks']);
    foreach ($importResult['actions'] as $action) {
        $actions[] = $action;
    }
    foreach ($importResult['warnings'] as $warning) {
        $warnings[] = $warning;
    }
    foreach ($importResult['errors'] as $error) {
        $errors[] = $error;
    }
    $totals['inserted_records'] = $importResult['totals']['inserted'];
    $totals['updated_records'] = $importResult['totals']['updated'];
    $totals['skipped_existing_records'] = $importResult['totals']['skipped_existing'];
} else {
    $checks[] = checkResult(
        'write_mode',
        'passed',
        $dryRun ? 'Dry-run mode will not write data.' : 'No source-backed writes are required.'
    );
}

$status = $errors === [] ? 'success' : 'failed';
$summary = summarize($enabled, $dryRun, $totals, $errors);

$report = [
    'schema_version' => 2,
    'app' => 'pbb-hotline',
    'tool' => 'populate_initial_data',
    'scope' => 'reference_data',
    'managed_groups' => $managedGroups,
    'excluded_groups' => array_keys($deprecatedSources),
    'mode' => $mode,
    'dry_run' => $dryRun,
    'status' => $status,
    'started_at' => $startedAt,
    'finished_at' => gmdate('c'),
    'summary' => $summary,
    'config' => [
        'path' => $configPath,
        'population_enabled' => $enabled,
        'overwrite_existing' => $overwriteExisting,
        'include_demo_data' => $includeDemoData,
        'deactivate_missing' => $deactivateMissing,
    ],
    'totals' => $totals,
    'record_counts' => groupCounts($canonical),
    'checks' => $checks,
    'actions' => $actions,
    'warnings' => $warnings,
    'errors' => $errors,
    'results' => [
        'planned_records' => $totals['planned_records'],
        'inserted_records' => $totals['inserted_records'],
        'updated_records' => $totals['updated_records'],
        'skipped_existing_records' => $totals['skipped_existing_records'],
        'available_sources' => $totals['available_sources'],
        'missing_sources' => $totals['missing_sources'],
    ],
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
            'status' => 'passed',
            'message' => 'No config file was provided; using disabled population defaults.',
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

function populationConfig(array $config): array
{
    if (isset($config['hotline']) && is_array($config['hotline']) && isset($config['hotline']['populate']) && is_array($config['hotline']['populate'])) {
        return $config['hotline']['populate'];
    }

    if (isset($config['populate']) && is_array($config['populate'])) {
        return $config['populate'];
    }

    if (array_key_exists('enabled', $config) || array_key_exists('sources', $config)) {
        return $config;
    }

    return [];
}

function normalizePath(string $path, string $rootPath): string
{
    $trimmed = trim($path);
    if ($trimmed === '') {
        return $trimmed;
    }

    if (preg_match('/^[A-Za-z]:[\\\\\\/]/', $trimmed) === 1 || str_starts_with($trimmed, '\\\\') || str_starts_with($trimmed, '/')) {
        return $trimmed;
    }

    return $rootPath.DIRECTORY_SEPARATOR.str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $trimmed);
}

function inspectJsonSource(string $path, array $groups): array
{
    $json = file_get_contents($path);
    if ($json === false) {
        return failedInspection('Source file could not be read: '.$path);
    }

    $data = json_decode($json, true);
    if (! is_array($data)) {
        return failedInspection('Source file is not valid JSON: '.$path.' ('.json_last_error_msg().')');
    }

    $counts = [];
    $grouped = [];
    foreach ($groups as $group) {
        $records = $data[$group] ?? [];
        if ($records === null) {
            $records = [];
        }
        if (! is_array($records) || ! arrayIsList($records)) {
            return failedInspection('Source group '.$group.' must be a JSON array in '.$path.'.');
        }
        $counts[$group] = count($records);
        $grouped[$group] = $records;
    }

    return [
        'status' => 'passed',
        'message' => 'Source file is valid JSON.',
        'record_counts' => $counts,
        'total_records' => array_sum($counts),
        'data' => $grouped,
    ];
}

function failedInspection(string $message): array
{
    return [
        'status' => 'failed',
        'message' => $message,
        'record_counts' => [],
        'total_records' => 0,
        'data' => [],
    ];
}

function validateCanonical(array $data): array
{
    $errors = [];
    $warnings = [];
    $checks = [];

    foreach ([
        'incident_categories' => ['name'],
        'incident_types' => ['name', 'category'],
        'incident_type_fields' => ['incident_type', 'field_key', 'field_label', 'input_type'],
        'resource_type_categories' => ['name'],
        'resource_types' => ['name', 'category'],
        'incident_type_default_resources' => ['incident_type', 'resource_type'],
        'team_categories' => ['name'],
        'teams' => ['name', 'category'],
        'team_resource_inventories' => ['team', 'resource_type'],
    ] as $group => $requiredFields) {
        foreach ($data[$group] as $index => $record) {
            if (! is_array($record)) {
                $errors[] = $group.'['.$index.'] must be an object.';
                continue;
            }

            foreach ($requiredFields as $field) {
                if (! array_key_exists($field, $record) || trim((string) $record[$field]) === '') {
                    $errors[] = $group.'['.$index.'] is missing required field '.$field.'.';
                }
            }
        }
    }

    $incidentCategories = names($data['incident_categories']);
    foreach ($data['incident_types'] as $index => $record) {
        if (! in_array((string) ($record['category'] ?? ''), $incidentCategories, true)) {
            $errors[] = 'incident_types['.$index.'] references unknown category '.(string) ($record['category'] ?? '').'.';
        }
    }

    $incidentTypes = names($data['incident_types']);
    foreach (['incident_type_fields', 'incident_type_default_resources'] as $group) {
        foreach ($data[$group] as $index => $record) {
            if (! in_array((string) ($record['incident_type'] ?? ''), $incidentTypes, true)) {
                $errors[] = $group.'['.$index.'] references unknown incident_type '.(string) ($record['incident_type'] ?? '').'.';
            }
        }
    }

    $resourceCategories = names($data['resource_type_categories']);
    foreach ($data['resource_types'] as $index => $record) {
        if (! in_array((string) ($record['category'] ?? ''), $resourceCategories, true)) {
            $errors[] = 'resource_types['.$index.'] references unknown category '.(string) ($record['category'] ?? '').'.';
        }
    }

    $resourceTypes = names($data['resource_types']);
    foreach (['incident_type_default_resources', 'team_resource_inventories'] as $group) {
        foreach ($data[$group] as $index => $record) {
            if (! in_array((string) ($record['resource_type'] ?? ''), $resourceTypes, true)) {
                $errors[] = $group.'['.$index.'] references unknown resource_type '.(string) ($record['resource_type'] ?? '').'.';
            }
        }
    }

    $teamCategories = names($data['team_categories']);
    foreach ($data['teams'] as $index => $record) {
        if (! in_array((string) ($record['category'] ?? ''), $teamCategories, true)) {
            $errors[] = 'teams['.$index.'] references unknown category '.(string) ($record['category'] ?? '').'.';
        }
    }

    $teams = names($data['teams']);
    foreach ($data['team_resource_inventories'] as $index => $record) {
        if (! in_array((string) ($record['team'] ?? ''), $teams, true)) {
            $errors[] = 'team_resource_inventories['.$index.'] references unknown team '.(string) ($record['team'] ?? '').'.';
        }
    }

    foreach (groupCounts($data) as $group => $count) {
        if ($count === 0) {
            $warnings[] = 'Reference-data group '.$group.' has zero records.';
        }
    }

    $checks[] = checkResult(
        'reference_data_contract',
        $errors === [] ? 'passed' : 'failed',
        $errors === [] ? 'Reference-data source contract is valid.' : 'Reference-data source contract failed validation.',
        ['record_counts' => groupCounts($data)]
    );

    return ['errors' => $errors, 'warnings' => $warnings, 'checks' => $checks];
}

function importReferenceData(array $config, string $rootPath, array $data, bool $overwriteExisting): array
{
    $checks = [];
    $actions = [];
    $warnings = [];
    $errors = [];
    $totals = ['inserted' => 0, 'updated' => 0, 'skipped_existing' => 0];

    try {
        $pdo = connectPdo($config, $rootPath);
        $checks[] = checkResult('database_connection', 'passed', 'Connected to Hotline database.');
    } catch (Throwable $exception) {
        return [
            'checks' => [checkResult('database_connection', 'failed', $exception->getMessage())],
            'actions' => [],
            'warnings' => [],
            'errors' => [$exception->getMessage()],
            'totals' => $totals,
        ];
    }

    try {
        $pdo->beginTransaction();
        $now = date('Y-m-d H:i:s');

        $incidentCategoryIds = upsertNamed($pdo, 'incident_categories', $data['incident_categories'], ['description', 'sort_order'], $overwriteExisting, $now, $totals);
        $resourceCategoryIds = upsertNamed($pdo, 'resource_type_categories', $data['resource_type_categories'], ['description', 'sort_order'], $overwriteExisting, $now, $totals);
        $teamCategoryIds = upsertNamed($pdo, 'team_categories', $data['team_categories'], ['description', 'sort_order'], $overwriteExisting, $now, $totals);

        $incidentTypeIds = [];
        foreach ($data['incident_types'] as $record) {
            $name = (string) $record['name'];
            $values = [
                'incident_category_id' => $incidentCategoryIds[(string) $record['category']],
                'name' => $name,
                'description' => nullableString($record['description'] ?? null),
            ];
            $incidentTypeIds[$name] = upsertByConditions($pdo, 'incident_types', ['name' => $name], $values, $overwriteExisting, $now, $totals);
        }

        $resourceTypeIds = [];
        foreach ($data['resource_types'] as $record) {
            $name = (string) $record['name'];
            $categoryId = $resourceCategoryIds[(string) $record['category']];
            $values = [
                'category_id' => $categoryId,
                'name' => $name,
                'unit_label' => nullableString($record['unit_label'] ?? null),
            ];
            $resourceTypeIds[$name] = upsertByConditions($pdo, 'resource_types', ['category_id' => $categoryId, 'name' => $name], $values, $overwriteExisting, $now, $totals);
        }

        $teamIds = [];
        foreach ($data['teams'] as $record) {
            $name = (string) $record['name'];
            $values = [
                'team_category_id' => $teamCategoryIds[(string) $record['category']],
                'name' => $name,
                'status' => nullableString($record['status'] ?? 'active') ?? 'active',
            ];
            $teamIds[$name] = upsertByConditions($pdo, 'teams', ['name' => $name], $values, $overwriteExisting, $now, $totals);
        }

        foreach ($data['incident_type_fields'] as $record) {
            $incidentTypeId = $incidentTypeIds[(string) $record['incident_type']];
            $values = [
                'incident_type_id' => $incidentTypeId,
                'field_key' => (string) $record['field_key'],
                'field_label' => (string) $record['field_label'],
                'input_type' => (string) $record['input_type'],
                'options_json' => jsonOrNull($record['options'] ?? $record['options_json'] ?? null),
                'config_json' => jsonOrNull(normalizeIncidentTypeFieldConfig(
                    (string) $record['input_type'],
                    $record['config'] ?? $record['config_json'] ?? null,
                )),
                'default_value' => nullableString($record['default_value'] ?? null),
                'placeholder' => nullableString($record['placeholder'] ?? null),
                'unit' => nullableString($record['unit'] ?? null),
                'is_required' => boolValue($record['is_required'] ?? false) ? 1 : 0,
                'sort_order' => intValue($record['sort_order'] ?? 0),
                'min' => nullableNumber($record['min'] ?? null),
                'max' => nullableNumber($record['max'] ?? null),
                'step' => nullableNumber($record['step'] ?? null),
            ];
            upsertByConditions($pdo, 'incident_type_fields', ['incident_type_id' => $incidentTypeId, 'field_key' => (string) $record['field_key']], $values, $overwriteExisting, $now, $totals);
        }

        foreach ($data['incident_type_default_resources'] as $record) {
            $incidentTypeId = $incidentTypeIds[(string) $record['incident_type']];
            $resourceTypeId = $resourceTypeIds[(string) $record['resource_type']];
            $values = [
                'incident_type_id' => $incidentTypeId,
                'resource_type_id' => $resourceTypeId,
                'quantity_required' => intValue($record['quantity_required'] ?? 1),
                'notes' => nullableString($record['notes'] ?? null),
                'sort_order' => intValue($record['sort_order'] ?? 0),
            ];
            upsertByConditions($pdo, 'incident_type_default_resources', ['incident_type_id' => $incidentTypeId, 'resource_type_id' => $resourceTypeId], $values, $overwriteExisting, $now, $totals);
        }

        foreach ($data['team_resource_inventories'] as $record) {
            $teamId = $teamIds[(string) $record['team']];
            $resourceTypeId = $resourceTypeIds[(string) $record['resource_type']];
            $values = [
                'team_id' => $teamId,
                'resource_type_id' => $resourceTypeId,
                'quantity_available' => intValue($record['quantity_available'] ?? 0),
            ];
            upsertByConditions($pdo, 'team_resource_inventories', ['team_id' => $teamId, 'resource_type_id' => $resourceTypeId], $values, $overwriteExisting, $now, $totals);
        }

        $pdo->commit();
        $actions[] = actionResult('import_reference_data', 'passed', 'Imported Hotline reference data.', $totals);
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $errors[] = 'Reference-data import failed: '.$exception->getMessage();
        $checks[] = checkResult('import_reference_data', 'failed', 'Reference-data import failed.');
    }

    return [
        'checks' => $checks,
        'actions' => $actions,
        'warnings' => $warnings,
        'errors' => $errors,
        'totals' => $totals,
    ];
}

function connectPdo(array $config, string $rootPath): PDO
{
    $database = is_array($config['database'] ?? null) ? $config['database'] : [];
    if ($database === []) {
        $database = databaseConfigFromEnv($rootPath.DIRECTORY_SEPARATOR.'.env');
    }

    $driver = (string) ($database['driver'] ?? 'mysql');
    if ($driver !== 'mysql') {
        throw new RuntimeException('Hotline reference-data import only supports mysql database configs.');
    }

    foreach (['host', 'database', 'username'] as $field) {
        if (trim((string) ($database[$field] ?? '')) === '') {
            throw new RuntimeException('Database config is missing required field database.'.$field.'.');
        }
    }

    $host = (string) $database['host'];
    $port = (int) ($database['port'] ?? 3306);
    $dbname = (string) $database['database'];
    $username = (string) $database['username'];
    $password = (string) ($database['password'] ?? '');
    $dsn = 'mysql:host='.$host.';port='.$port.';dbname='.$dbname.';charset=utf8mb4';

    $pdo = new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec("SET time_zone = '+00:00'");

    return $pdo;
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

function upsertNamed(PDO $pdo, string $table, array $records, array $fields, bool $overwriteExisting, string $now, array &$totals): array
{
    $ids = [];
    foreach ($records as $record) {
        $name = (string) $record['name'];
        $values = ['name' => $name];
        foreach ($fields as $field) {
            $values[$field] = $field === 'sort_order' ? intValue($record[$field] ?? 0) : nullableString($record[$field] ?? null);
        }
        $ids[$name] = upsertByConditions($pdo, $table, ['name' => $name], $values, $overwriteExisting, $now, $totals);
    }

    return $ids;
}

function upsertByConditions(PDO $pdo, string $table, array $conditions, array $values, bool $overwriteExisting, string $now, array &$totals): int
{
    $where = implode(' AND ', array_map(static fn ($field) => '`'.$field.'` = ?', array_keys($conditions)));
    $select = $pdo->prepare('SELECT id FROM `'.$table.'` WHERE '.$where.' LIMIT 1');
    $select->execute(array_values($conditions));
    $id = $select->fetchColumn();

    if ($id !== false) {
        if (! $overwriteExisting) {
            $totals['skipped_existing']++;
            return (int) $id;
        }

        $updateValues = $values;
        $updateValues['updated_at'] = $now;
        $assignments = implode(', ', array_map(static fn ($field) => '`'.$field.'` = ?', array_keys($updateValues)));
        $update = $pdo->prepare('UPDATE `'.$table.'` SET '.$assignments.' WHERE id = ?');
        $update->execute([...array_values($updateValues), (int) $id]);
        $totals['updated']++;
        return (int) $id;
    }

    $insertValues = $values;
    $insertValues['created_at'] = $now;
    $insertValues['updated_at'] = $now;
    $columns = array_keys($insertValues);
    $insert = $pdo->prepare('INSERT INTO `'.$table.'` (`'.implode('`, `', $columns).'`) VALUES ('.implode(', ', array_fill(0, count($columns), '?')).')');
    $insert->execute(array_values($insertValues));
    $totals['inserted']++;

    return (int) $pdo->lastInsertId();
}

function emptyCanonical(array $groups): array
{
    $data = [];
    foreach ($groups as $group) {
        $data[$group] = [];
    }

    return $data;
}

function hasConfiguredManagedSource(array $sources, array $managedSourceIds): bool
{
    foreach ($managedSourceIds as $sourceId) {
        if (isset($sources[$sourceId]) && trim((string) $sources[$sourceId]) !== '') {
            return true;
        }
    }

    return false;
}

function groupCounts(array $data): array
{
    $counts = [];
    foreach ($data as $group => $records) {
        $counts[$group] = is_array($records) ? count($records) : 0;
    }

    return $counts;
}

function names(array $records): array
{
    return array_values(array_map(static fn ($record) => (string) ($record['name'] ?? ''), $records));
}

function arrayIsList(array $data): bool
{
    if (function_exists('array_is_list')) {
        return array_is_list($data);
    }

    return array_keys($data) === range(0, count($data) - 1);
}

function nullableString(mixed $value): ?string
{
    if ($value === null) {
        return null;
    }
    $string = trim((string) $value);

    return $string === '' ? null : $string;
}

function nullableNumber(mixed $value): ?string
{
    if ($value === null || $value === '') {
        return null;
    }

    return number_format((float) $value, 2, '.', '');
}

function intValue(mixed $value): int
{
    return max(0, (int) $value);
}

function jsonOrNull(mixed $value): ?string
{
    if ($value === null || $value === '') {
        return null;
    }

    return json_encode($value, JSON_UNESCAPED_SLASHES);
}

function normalizeIncidentTypeFieldConfig(string $inputType, mixed $config): ?array
{
    if ($inputType !== 'group') {
        return null;
    }

    $config = is_array($config) ? $config : [];
    $preset = trim((string) ($config['preset'] ?? ''));

    if ($preset === '') {
        return $config === [] ? null : $config;
    }

    $presets = [
        'person' => ['label' => 'Person', 'repeatable' => false],
        'address' => ['label' => 'Address', 'repeatable' => false],
        'missingPerson' => ['label' => 'Missing Person', 'repeatable' => true],
        'evacuee' => ['label' => 'Evacuee', 'repeatable' => true],
        'family' => ['label' => 'Family', 'repeatable' => true],
        'casualtyPatient' => ['label' => 'Casualty / Patient', 'repeatable' => true],
        'infrastructureDamage' => ['label' => 'Infrastructure Damage', 'repeatable' => true],
        'shelterDamage' => ['label' => 'Shelter Damage', 'repeatable' => true],
        'roadAccessStatus' => ['label' => 'Road / Access Status', 'repeatable' => true],
        'vehicleInvolved' => ['label' => 'Vehicle Involved', 'repeatable' => true],
    ];

    $meta = $presets[$preset] ?? null;

    if ($meta === null) {
        return $config;
    }

    return [
        ...$config,
        'preset' => $preset,
        'preset_label' => $config['preset_label'] ?? $meta['label'],
        'repeatable' => array_key_exists('repeatable', $config) ? boolValue($config['repeatable']) : $meta['repeatable'],
    ];
}

function boolValue(mixed $value): bool
{
    if (is_bool($value)) {
        return $value;
    }

    if (is_int($value)) {
        return $value === 1;
    }

    if (is_string($value)) {
        return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
    }

    return false;
}

function checkResult(string $id, string $status, string $message, array $meta = []): array
{
    return array_filter([
        'id' => $id,
        'status' => $status,
        'message' => $message,
        'meta' => $meta,
    ], static fn ($value) => $value !== []);
}

function actionResult(string $id, string $status, string $summary, array $meta = []): array
{
    return array_filter([
        'id' => $id,
        'status' => $status,
        'summary' => $summary,
        'meta' => $meta,
    ], static fn ($value) => $value !== []);
}

function summarize(bool $enabled, bool $dryRun, array $totals, array $errors): string
{
    if ($errors !== []) {
        return 'Hotline reference-data population '.($dryRun ? 'dry run' : 'run').' failed validation.';
    }

    if (! $enabled) {
        return 'Hotline reference-data population is disabled; nothing to load.';
    }

    if ($totals['available_sources'] === 0) {
        return 'Hotline reference-data population '.($dryRun ? 'dry run' : 'run').' completed with no available source files; nothing planned.';
    }

    if ($dryRun) {
        return 'Hotline reference-data population dry run validated '.$totals['available_sources'].' source file(s) and planned '.$totals['planned_records'].' record(s).';
    }

    return 'Hotline reference-data population imported '.$totals['inserted_records'].' inserted, '.$totals['updated_records'].' updated, and '.$totals['skipped_existing_records'].' skipped existing record(s).';
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
