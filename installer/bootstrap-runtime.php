<?php

declare(strict_types=1);

use App\Domain\Shared\Enums\UserRole;
use App\Domain\Shared\Enums\UserStatus;
use App\Models\User;
use App\Support\Settings\SettingsService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Hash;

$configPath = optionValue($argv, '--config');

if ($configPath === null || ! is_file($configPath)) {
    fwrite(STDERR, "Missing --config file.\n");
    exit(2);
}

$config = json_decode((string) file_get_contents($configPath), true);

if (! is_array($config)) {
    fwrite(STDERR, "Config file is not valid JSON.\n");
    exit(2);
}

require dirname(__DIR__).DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR.'autoload.php';

$app = require dirname(__DIR__).DIRECTORY_SEPARATOR.'bootstrap'.DIRECTORY_SEPARATOR.'app.php';
$app->make(Kernel::class)->bootstrap();

/** @var SettingsService $settings */
$settings = $app->make(SettingsService::class);
$hotline = is_array($config['hotline'] ?? null) ? $config['hotline'] : [];

foreach (hotlineSettingKeys() as $configKey => $settingKey) {
    if (array_key_exists($configKey, $hotline)) {
        $settings->set($settingKey, $hotline[$configKey]);
    }
}

$admin = is_array($config['admin'] ?? null) ? $config['admin'] : [];
$strategy = trim((string) ($admin['strategy'] ?? 'create_if_missing')) ?: 'create_if_missing';
$overwriteExisting = (bool) ($admin['overwrite_existing'] ?? false);
$email = trim((string) ($admin['email'] ?? 'admin@pbb.local'));
$password = (string) ($admin['password'] ?? '');

if ($strategy !== 'create_if_missing') {
    fwrite(STDERR, "Unsupported admin strategy.\n");
    exit(2);
}

if ($email === '' || $password === '') {
    fwrite(STDERR, "Admin email and password are required.\n");
    exit(2);
}

$existingAdmin = User::query()->where('email', $email)->first();
$adminAction = 'created';

if ($existingAdmin && ! $overwriteExisting) {
    $adminAction = 'existing_unchanged';
} else {
    User::query()->updateOrCreate(
        ['email' => $email],
        [
            'name' => trim((string) ($admin['name'] ?? 'PBB Administrator')) ?: 'PBB Administrator',
            'mobile' => trim((string) ($admin['mobile'] ?? '')),
            'role' => UserRole::Admin,
            'status' => UserStatus::Active,
            'password' => Hash::make($password),
        ],
    );

    $adminAction = $existingAdmin ? 'updated' : 'created';
}

echo json_encode([
    'status' => 'success',
    'admin_email' => $email,
    'admin_strategy' => $strategy,
    'admin_action' => $adminAction,
    'admin_overwrite_existing' => $overwriteExisting,
    'settings_applied' => array_values(hotlineSettingKeys()),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;

/**
 * @return array<string, string>
 */
function hotlineSettingKeys(): array
{
    return [
        'realtime_url' => 'realtime_url',
        'realtime_client_code' => 'realtime_client_code',
        'realtime_project_code_server' => 'realtime_project_code_server',
        'realtime_project_code_caller' => 'realtime_project_code_caller',
        'realtime_project_code_operator' => 'realtime_project_code_operator',
        'realtime_project_code_command' => 'realtime_project_code_command',
        'realtime_project_code_media_ingest' => 'realtime_project_code_media_ingest',
        'realtime_backend_ingress_secret' => 'realtime_backend_ingress_secret',
        'realtime_media_ingest_secret' => 'realtime_media_ingest_secret',
        'realtime_token_signing_secret' => 'realtime_token_signing_secret',
        'relay_url' => 'relay_url',
        'relay_token' => 'relay_token',
        'map_server_url' => 'map_server_url',
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
