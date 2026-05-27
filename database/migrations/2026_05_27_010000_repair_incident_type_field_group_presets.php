<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * @var array<string, array{label: string, repeatable: bool}>
     */
    private array $presets = [
        'casualtyPatient' => ['label' => 'Casualty / Patient', 'repeatable' => true],
        'vehicleInvolved' => ['label' => 'Vehicle Involved', 'repeatable' => true],
        'roadAccessStatus' => ['label' => 'Road / Access Status', 'repeatable' => true],
        'infrastructureDamage' => ['label' => 'Infrastructure Damage', 'repeatable' => true],
        'shelterDamage' => ['label' => 'Shelter Damage', 'repeatable' => true],
        'family' => ['label' => 'Family', 'repeatable' => true],
        'person' => ['label' => 'Person', 'repeatable' => false],
    ];

    public function up(): void
    {
        $this->repairTable('incident_type_fields');
        $this->repairTable('incident_type_details');
    }

    public function down(): void
    {
        $this->restoreTable('incident_type_fields');
        $this->restoreTable('incident_type_details');
    }

    private function repairTable(string $table): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        foreach ($this->presets as $preset => $meta) {
            DB::table($table)
                ->where('input_type', $preset)
                ->update([
                    'input_type' => 'group',
                    'options_json' => null,
                    'config_json' => json_encode([
                        'preset' => $preset,
                        'preset_label' => $meta['label'],
                        'repeatable' => $meta['repeatable'],
                    ], JSON_UNESCAPED_SLASHES),
                    'updated_at' => now(),
                ]);
        }
    }

    private function restoreTable(string $table): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        foreach ($this->presets as $preset => $meta) {
            DB::table($table)
                ->where('input_type', 'group')
                ->where('config_json->preset', $preset)
                ->update([
                    'input_type' => $preset,
                    'options_json' => null,
                    'config_json' => null,
                    'updated_at' => now(),
                ]);
        }
    }
};
