<?php

namespace Database\Seeders;

use App\Models\Setting;
use App\Support\Settings\SettingsService;
use Illuminate\Database\Seeder;

class SettingsSeeder extends Seeder
{
    public function run(): void
    {
        $settings = app(SettingsService::class);

        foreach ($settings->defaults() as $key => $value) {
            Setting::query()->firstOrCreate(
                ['key' => $key],
                ['value' => ['value' => $value]],
            );
        }
    }
}
