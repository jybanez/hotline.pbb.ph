<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $this->removePresetFields('incident_type_fields');
        $this->removePresetFields('incident_type_details');
    }

    public function down(): void
    {
        // The removed fields are duplicated Helper preset schema. They are not
        // recoverable from Hotline storage without reintroducing that duplicate.
    }

    private function removePresetFields(string $table): void
    {
        DB::table($table)
            ->select(['id', 'config_json'])
            ->where('input_type', 'group')
            ->whereNotNull('config_json')
            ->orderBy('id')
            ->chunkById(100, function ($rows) use ($table): void {
                foreach ($rows as $row) {
                    $config = json_decode((string) $row->config_json, true);

                    if (! is_array($config) || ! isset($config['preset']) || ! array_key_exists('fields', $config)) {
                        continue;
                    }

                    unset($config['fields']);

                    DB::table($table)
                        ->where('id', $row->id)
                        ->update([
                            'config_json' => json_encode($config),
                        ]);
                }
            });
    }
};
