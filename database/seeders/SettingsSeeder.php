<?php

namespace Database\Seeders;

use App\Support\Settings\SettingsService;
use Illuminate\Database\Seeder;

class SettingsSeeder extends Seeder
{
    public function run(): void
    {
        $settings = app(SettingsService::class);

        foreach ($settings->defaults() as $key => $value) {
            $settings->set($key, $value);
        }
    }
}
